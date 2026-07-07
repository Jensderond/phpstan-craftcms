<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\Binary\NullCoalesceBinary;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\ForNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\SetNode;

/**
 * One AST walk that maintains a loop-context stack and emits performance
 * findings. Each enabled check is evaluated during the same traversal.
 */
final class TwigPerformanceAnalyzer
{
    /** @var list<Finding> */
    private array $findings = [];

    /**
     * Stack of active loops. Each frame: loop value-variable name + the relations
     * known to be eager-loaded on the loop source. eagerLoaded === null means a
     * dynamic with(...) was present → suppress N+1 for this loop. nonElement is
     * true when the loop iterates a plain data structure (an array/hash literal,
     * directly or via {% set %}/nesting) rather than element query results — such
     * a loop variable can never trigger a relation N+1, so those checks skip it.
     *
     * @var list<array{var: string, eagerLoaded: array<string, true>|null, nonElement: bool}>
     */
    private array $loopStack = [];

    /**
     * Variables assigned in the current scope via {% set x = ... %}, mapped to
     * the assigned expression, so a loop over `x` can resolve its source.
     *
     * @var array<string, Node>
     */
    private array $assignments = [];

    /**
     * Variable names currently bound to a plain (non-element) value: assigned an
     * array/hash literal via {% set %}, or the value variable of a loop whose
     * source is itself plain. Used to propagate "this is not a Craft element"
     * through {% set %} and nested loops (e.g. the fonts hash in
     * `{% for family, fonts in fonts %}{% for font in fonts %}`).
     *
     * @var array<string, true>
     */
    private array $plainVars = [];

    /**
     * @param  array<string, bool>  $enabledChecks
     */
    public function __construct(
        private readonly RelationFieldRegistry $relations,
        private readonly array $enabledChecks,
    ) {}

    /**
     * @return list<Finding>
     */
    public function analyze(ModuleNode $module): array
    {
        $this->findings = [];
        $this->loopStack = [];
        $this->assignments = [];
        $this->plainVars = [];

        $this->walk($module);

        return $this->findings;
    }

    private function enabled(string $check): bool
    {
        return $this->enabledChecks[$check] ?? false;
    }

    private function walk(Node $node): void
    {
        if ($node instanceof SetNode) {
            $this->recordAssignment($node);
        }

        if ($node instanceof ForNode) {
            // Walk the loop's source expression in the OUTER context first: a
            // query used as the loop's own source runs once (before iteration
            // begins), so it must not be visible to checks like
            // checkQueryInLoop() as "inside this loop". If that same source
            // expression is itself nested inside an enclosing loop, the outer
            // frame is still on the stack at this point, so it is correctly
            // flagged as running per outer iteration.
            $seq = $node->getNode('seq');
            $this->walk($seq);

            $valueVar = $this->enterLoop($node);
            $nonElement = $this->loopStack[count($this->loopStack) - 1]['nonElement'];

            // Inside the loop body, the value variable holds one iterated item.
            // If the source is plain, so is each item — propagate that so a
            // nested loop over this variable is recognised as plain too. Save
            // and restore any shadowed outer binding.
            $hadPlain = isset($this->plainVars[$valueVar]);
            if ($nonElement) {
                $this->plainVars[$valueVar] = true;
            } else {
                unset($this->plainVars[$valueVar]);
            }

            // The {% else %} branch runs at most once (only when the sequence
            // is empty), so it is walked OUTSIDE this loop's frame below —
            // a query there is not a per-iteration query.
            $else = $node->hasNode('else') ? $node->getNode('else') : null;

            foreach ($node as $child) {
                if ($child === $seq || $child === $else) {
                    continue;
                }
                $this->walk($child);
            }

            array_pop($this->loopStack);
            if ($hadPlain) {
                $this->plainVars[$valueVar] = true;
            } else {
                unset($this->plainVars[$valueVar]);
            }

            if ($else instanceof Node) {
                $this->walk($else);
            }

            return;
        }

        if ($node instanceof GetAttrExpression) {
            $this->checkChain($node);
            $this->checkNestedRelationAll($node);
            $this->checkQueryInLoop($node);
            $this->checkUnboundedAll($node);
            $this->walkChainSideBranches($node);

            return;
        }

        if ($node instanceof FilterExpression) {
            $this->checkLengthOnQuery($node);
        }

        foreach ($node as $child) {
            $this->walk($child);
        }
    }

    /**
     * GetAttr nodes link into a chain via their `node` child (e.g.
     * `entry.author.eagerly().one()` is four nested GetAttrs). We only want to
     * evaluate the N+1 check once per chain — from the outermost node, looking
     * down the whole chain — rather than once per GetAttr in it, otherwise a
     * `.eagerly()` applied above the relation access would not be visible when
     * the inner `entry.author` node is inspected in isolation.
     *
     * `walk()` only calls this for a GetAttr it reaches by general recursion,
     * which is always the outermost unvisited GetAttr of its chain (inner ones
     * are consumed by walkChainSideBranches() below, not by the outer foreach),
     * so every chain is scanned exactly once, top-down.
     */
    private function checkChain(GetAttrExpression $node): void
    {
        if (! $this->enabled('nPlusOne') || $this->loopStack === []) {
            return;
        }

        $chainMethodsAboveRelation = [];
        $current = $node;

        while ($current instanceof GetAttrExpression) {
            $object = $current->hasNode('node') ? $current->getNode('node') : null;
            $objectName = $object instanceof Node ? TwigNodeHelper::nameOf($object) : null;

            // The chain may be rooted at ANY active loop's value variable, not
            // just the innermost one: `entry.author` inside a nested loop still
            // runs once per `entry` iteration. elementLoopFrame() resolves the
            // name shadow-aware, so an inner loop over a plain literal masks an
            // outer element loop of the same variable name.
            if ($objectName !== null && ($frame = $this->elementLoopFrame($objectName)) !== null) {
                // `<loopVar>.<attr>` found: $current is the relation access.
                $this->reportIfUnguarded($current, $frame, $chainMethodsAboveRelation);

                return;
            }

            if (TwigNodeHelper::isMethodCall($current)) {
                $name = TwigNodeHelper::attrName($current);
                if ($name !== null) {
                    $chainMethodsAboveRelation[$name] = true;
                }
            }

            $current = $object;
        }
    }

    /**
     * @param  array{var: string, eagerLoaded: array<string, true>|null}  $frame
     * @param  array<string, true>  $methodsAboveRelation  Method names applied
     *                                                     on top of the relation access within the same chain, e.g. `eagerly`
     *                                                     in `entry.author.eagerly().one()`.
     */
    private function reportIfUnguarded(GetAttrExpression $relationAccess, array $frame, array $methodsAboveRelation): void
    {
        if (isset($methodsAboveRelation['eagerly'])) {
            return;
        }

        $attr = TwigNodeHelper::attrName($relationAccess);
        if ($attr === null || ! $this->relations->isRelation($attr)) {
            return;
        }

        // Suppress if eager-loaded (or dynamic with(...) → null → unknown).
        $eager = $frame['eagerLoaded'];
        if ($eager === null || $this->isEagerLoaded($eager, $attr)) {
            return;
        }

        $this->findings[] = new Finding(
            'craftcms.twigNPlusOne',
            $relationAccess->getTemplateLine(),
            sprintf('Relation "%s" is accessed inside a loop without eager-loading, causing an N+1 query.', $attr),
            sprintf('Eager-load it on the query with .with([\'%s\']) or use %s.%s.eagerly() in the loop.', $attr, $frame['var'], $attr),
        );
    }

    /**
     * Pattern: `<loopVar>.<relation>.all()` inside a loop — a nested relation
     * fetched with .all() on each iteration, causing an N+1 query, unless
     * `.eagerly()` is also present in the chain (it sits below the terminal
     * `.all()`, e.g. `entry.rel.eagerly().all()`, so methodsInChain($node) sees it).
     *
     * $node is the terminal (outermost) GetAttr of its chain — see walk().
     */
    private function checkNestedRelationAll(GetAttrExpression $node): void
    {
        if (! $this->enabled('nestedRelationAll') || $this->loopStack === []) {
            return;
        }

        if (! TwigNodeHelper::isMethodCall($node) || TwigNodeHelper::attrName($node) !== 'all') {
            return;
        }

        $methods = TwigNodeHelper::methodsInChain($node);
        if (isset($methods['eagerly'])) {
            return;
        }

        $object = $node->hasNode('node') ? $node->getNode('node') : null;
        if (! $object instanceof GetAttrExpression) {
            return;
        }

        // Walk down through any method calls to the relation access itself.
        $relationNode = $object;
        while ($relationNode instanceof GetAttrExpression && TwigNodeHelper::isMethodCall($relationNode)) {
            $relationNode = $relationNode->hasNode('node') ? $relationNode->getNode('node') : null;
        }
        if (! $relationNode instanceof GetAttrExpression || ! $relationNode->hasNode('node')) {
            return;
        }

        $objectName = TwigNodeHelper::nameOf($relationNode->getNode('node'));
        $attr = TwigNodeHelper::attrName($relationNode);
        if ($objectName === null || $attr === null) {
            return;
        }
        $frame = $this->elementLoopFrame($objectName);
        if ($frame === null || ! $this->relations->isRelation($attr)) {
            return;
        }

        // If the relation is (or may be) eager-loaded, `<loopVar>.<rel>.all()` is
        // served from the eager-loaded cache rather than re-querying, so it is
        // not an N+1. null = unknown (e.g. the loop element came from outside
        // this template); see resolveEagerLoaded().
        $eager = $frame['eagerLoaded'];
        if ($eager === null || $this->isEagerLoaded($eager, $attr)) {
            return;
        }

        $this->findings[] = new Finding(
            'craftcms.twigNestedRelationAll',
            $node->getTemplateLine(),
            sprintf('Nested relation "%s" is fetched with .all() inside a loop, causing an N+1 query.', $attr),
            sprintf('Use %s.%s.eagerly().all() or eager-load "%s" on the parent query.', $objectName, $attr, $attr),
        );
    }

    /**
     * Fires once per query (on the chain's outermost node) when that query is
     * built and executed inside a loop. The terminal fetch method
     * (all/one/count/exists/nth/ids) may sit anywhere in the chain, not just at
     * the outermost position — e.g. `craft.entries.section('n').one().title`
     * reads a property off the fetch, so the outermost node is `.title`, not
     * `.one()`. We therefore scan the whole chain for a terminal fetch method
     * rather than requiring $node itself to be one.
     *
     * $node is the terminal (outermost) GetAttr of its chain — see walk() —
     * so this still reports exactly once per chain.
     */
    private function checkQueryInLoop(GetAttrExpression $node): void
    {
        if (! $this->enabled('queryInLoop') || $this->loopStack === []) {
            return;
        }

        $methods = TwigNodeHelper::methodsInChain($node);
        $terminals = array_intersect_key($methods, array_flip(['all', 'one', 'count', 'exists', 'nth', 'ids']));
        if ($terminals === []) {
            return;
        }

        if (! TwigNodeHelper::isQueryRooted($node)) {
            return;
        }

        $this->findings[] = new Finding(
            'craftcms.twigQueryInLoop',
            $node->getTemplateLine(),
            'An element query is executed inside a loop, running one query per iteration.',
            'Move the query outside the loop and eager-load, or collect IDs and query once.',
        );
    }

    /**
     * Unbounded `.all()` on an element query outside any loop (in-loop queries
     * are checkQueryInLoop's job) with no `.limit()` anywhere in the chain.
     *
     * $node is the terminal (outermost) GetAttr of its chain — see walk().
     */
    private function checkUnboundedAll(GetAttrExpression $node): void
    {
        if (! $this->enabled('unboundedAll') || $this->loopStack !== []) {
            return;
        }

        if (! TwigNodeHelper::isMethodCall($node) || TwigNodeHelper::attrName($node) !== 'all') {
            return;
        }

        if (! TwigNodeHelper::isQueryRooted($node)) {
            return;
        }

        $methods = TwigNodeHelper::methodsInChain($node);
        if (isset($methods['limit'])) {
            return;
        }

        $this->findings[] = new Finding(
            'craftcms.twigUnboundedAll',
            $node->getTemplateLine(),
            'Unbounded .all() on an element query may load a large result set into memory.',
            'Add .limit(n) if you do not need every element.',
        );
    }

    /**
     * `|length` applied directly to an element query forces it to fetch every
     * row just to count them; `.count()` is the cheaper equivalent. Does not
     * return early: the operand's own GetAttr chain still needs to be walked
     * by the caller.
     */
    private function checkLengthOnQuery(FilterExpression $node): void
    {
        if (! $this->enabled('lengthOnQuery')) {
            return;
        }

        if (TwigNodeHelper::filterName($node) !== 'length') {
            return;
        }

        $operand = $node->hasNode('node') ? $node->getNode('node') : null;
        if (! $operand instanceof Node || ! TwigNodeHelper::isQueryRooted($operand)) {
            return;
        }

        // If .all()/.ids() already executed the query, |length is on an array — fine.
        $methods = TwigNodeHelper::methodsInChain($operand);
        if (isset($methods['all']) || isset($methods['ids'])) {
            return;
        }

        $this->findings[] = new Finding(
            'craftcms.twigLengthOnQuery',
            $node->getTemplateLine(),
            'Using |length on an element query fetches every element just to count them.',
            'Use .count() instead of |length.',
        );
    }

    /**
     * The innermost active loop frame whose value variable is $name, provided
     * that loop iterates elements (not a plain array/hash literal); null when
     * $name is not a loop variable. The innermost match decides so an inner
     * loop shadowing $name masks the outer binding: if it iterates a plain
     * literal, $name holds plain values here and no element frame is returned.
     *
     * @return array{var: string, eagerLoaded: array<string, true>|null, nonElement: bool}|null
     */
    private function elementLoopFrame(string $name): ?array
    {
        for ($i = count($this->loopStack) - 1; $i >= 0; $i--) {
            $frame = $this->loopStack[$i];
            if ($frame['var'] === $name) {
                return $frame['nonElement'] ? null : $frame;
            }
        }

        return null;
    }

    /**
     * Walk the non-spine children of a GetAttr chain (the `attribute` and
     * `arguments` of every node in the chain), since those may contain nested
     * expressions (e.g. other loops, sub-chains) that still need visiting. The
     * `node` spine itself is intentionally not re-walked here — checkChain()
     * already scanned it for the N+1 check, and re-walking it would visit the
     * same chain's GetAttrs a second time via the general walk() recursion.
     */
    private function walkChainSideBranches(GetAttrExpression $node): void
    {
        $current = $node;

        while ($current instanceof GetAttrExpression) {
            if ($current->hasNode('attribute')) {
                $this->walk($current->getNode('attribute'));
            }
            if ($current->hasNode('arguments')) {
                $this->walk($current->getNode('arguments'));
            }

            $next = $current->hasNode('node') ? $current->getNode('node') : null;
            if (! $next instanceof GetAttrExpression) {
                // Reached the chain root (not a GetAttr, e.g. a NameExpression):
                // walk it normally since nothing else will.
                if ($next instanceof Node) {
                    $this->walk($next);
                }

                return;
            }
            $current = $next;
        }
    }

    private function recordAssignment(SetNode $node): void
    {
        if (! $node->hasNode('names') || ! $node->hasNode('values')) {
            return;
        }

        // `names`/`values` are each a `Nodes` wrapper; the single-target case
        // (the common `{% set x = expr %}`) has exactly one child at index 0.
        $names = $this->firstChild($node->getNode('names'));
        $values = $this->firstChild($node->getNode('values'));

        if ($names === null || $values === null) {
            return;
        }

        $name = TwigNodeHelper::nameOf($names);
        if ($name !== null) {
            $this->assignments[$name] = $values;

            if ($this->isPlainExpr($values)) {
                $this->plainVars[$name] = true;
            } else {
                unset($this->plainVars[$name]);
            }
        }
    }

    /**
     * A plain (non-element) expression: an array/hash literal, or a bare
     * reference to a variable already known to be plain. Query builders and
     * relation accesses are GetAttr chains, so they are never plain.
     */
    private function isPlainExpr(Node $node): bool
    {
        if ($node instanceof ArrayExpression) {
            return true;
        }

        $name = TwigNodeHelper::nameOf($node);

        return $name !== null && isset($this->plainVars[$name]);
    }

    private function firstChild(Node $wrapper): ?Node
    {
        foreach ($wrapper as $child) {
            return $child instanceof Node ? $child : null;
        }

        return null;
    }

    /**
     * Pushes a loop frame and returns the loop's value-variable name.
     */
    private function enterLoop(ForNode $node): string
    {
        $valueTarget = $node->getNode('value_target');
        $varName = $valueTarget->hasAttribute('name') ? (string) $valueTarget->getAttribute('name') : '';

        $source = $node->getNode('seq');
        $resolved = $this->resolveSource($source);

        $this->loopStack[] = [
            'var' => $varName,
            'eagerLoaded' => $this->resolveEagerLoaded($resolved),
            'nonElement' => $this->isPlainExpr($resolved),
        ];

        return $varName;
    }

    /**
     * If the loop source is a bare variable assigned one level back, use that
     * expression; otherwise use the source expression as-is. Fallback wrappers
     * (`query ?? []`, `query ?: []`) are unwrapped to the query on both the
     * direct source and the resolved assignment.
     */
    private function resolveSource(Node $source): Node
    {
        $source = $this->unwrapFallback($source);

        $name = TwigNodeHelper::nameOf($source);
        if ($name !== null && isset($this->assignments[$name])) {
            return $this->unwrapFallback($this->assignments[$name]);
        }

        return $source;
    }

    /**
     * Unwrap a `query ?? default` / `query ?: default` fallback to the query
     * side, so the eager-loading on the query is visible. Without this the query
     * is buried in the fallback node and every relation access on the loop items
     * is misreported — the exact false positive behind the ubiquitous
     * `craft.…all() ?? []` navigation idiom.
     *
     * The AST shape differs across Twig versions:
     *  - Twig ≥3.16 parses `a ?? b` to a NullCoalesceBinary (`left` = the query);
     *  - `a ?: b` (and `a ?? b` on older Twig) is a ConditionalExpression whose
     *    `expr2` holds the value used when the subject is present — the query.
     */
    private function unwrapFallback(Node $source): Node
    {
        if ($source instanceof NullCoalesceBinary && $source->hasNode('left')) {
            return $source->getNode('left');
        }

        if ($source instanceof ConditionalExpression && $source->hasNode('expr2')) {
            return $source->getNode('expr2');
        }

        return $source;
    }

    /**
     * The eager-load set for a loop source, or null ("unknown") when the source
     * cannot be traced to something visible in THIS template.
     *
     * PHPStan analyses each template in isolation, so a partial that receives an
     * already-eager-loaded element across an {% include %}/{% embed %} boundary
     * (e.g. navigation/node.twig iterating `node.children`, where `nodes` was
     * fetched with `.with(['children.children'])` in the parent layout) has no
     * way to see that eager-loading. Assuming "nothing eager-loaded" for such an
     * externally-supplied variable is exactly what produced N+1 false positives.
     *
     * We only trust an *empty* eager-load set when we can actually read the
     * query's chain: a `craft.*` global (covers `craft.entries()…` and plugin
     * queries such as `craft.navigation.nodes()…`), a builder recognised by
     * isQueryRooted (`.find()`/`.relatedTo()`), a `{% set %}` variable, or an
     * element of an enclosing loop whose own source was known. Otherwise the
     * state is unknown → null, which the N+1 checks treat as
     * possibly-eager-loaded and suppress (the same sentinel a dynamic
     * `with(...)` yields).
     *
     * @return array<string, true>|null
     */
    private function resolveEagerLoaded(Node $source): ?array
    {
        // A plain array/hash literal (or a var known to hold one): no relations.
        if ($this->isPlainExpr($source)) {
            return [];
        }

        $root = TwigNodeHelper::rootName($source);

        // A chain whose query we can read directly — rooted at the `craft`
        // global, or a recognised builder. A missing `.with()` here genuinely
        // means no eager-loading.
        if ($root === 'craft' || TwigNodeHelper::isQueryRooted($source)) {
            return TwigNodeHelper::eagerLoadedRelations($source);
        }

        if ($root === null) {
            // No identifiable root variable (unusual) → keep the conservative reading.
            return TwigNodeHelper::eagerLoadedRelations($source);
        }

        // Rooted at an element of an enclosing loop: if that loop's own eager
        // state was unknown, so is this one (propagate across nested loops such
        // as `{% for child in node.children %}` under an external `nodes`).
        // Otherwise descend the enclosing loop's eager-load paths through the
        // relation(s) accessed here, so a deep path like
        // `with(['children.children.children'])` keeps covering each nesting
        // level (`node.children` → the children collection is still eager-loaded
        // for `children.children`), while relations beyond the path's depth
        // remain flagged. An explicit `.with()` on this source overrides the
        // inherited state.
        foreach ($this->loopStack as $frame) {
            if ($frame['var'] === $root) {
                if ($frame['eagerLoaded'] === null) {
                    return null;
                }

                if (isset(TwigNodeHelper::methodsInChain($source)['with'])) {
                    return TwigNodeHelper::eagerLoadedRelations($source);
                }

                return $this->descendEagerPaths(
                    $frame['eagerLoaded'],
                    $this->relationSegments($source),
                );
            }
        }

        // Rooted at a variable assigned locally via {% set %}: visible here.
        if (isset($this->assignments[$root])) {
            return TwigNodeHelper::eagerLoadedRelations($source);
        }

        // Rooted at a variable supplied from outside this template (include
        // variable, embed context, or global). Its eager-load state is
        // unknowable here → unknown rather than "none".
        return null;
    }

    /**
     * Whether relation $attr is covered by the eager-load set. Handles nested
     * paths: `with(['children.children'])` eager-loads `children` (the head
     * segment) as well as its `children`, so accessing `children` on the loop
     * items is served from cache. A path matches when $attr is exactly the path
     * or its first dotted segment.
     *
     * @param  array<string, true>  $eager
     */
    private function isEagerLoaded(array $eager, string $attr): bool
    {
        foreach (array_keys($eager) as $path) {
            if ($path === $attr || str_starts_with($path, $attr.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The relation segments accessed on a chain rooted at a loop variable, in
     * root→outer order: `node.children` → ['children'], `node.parent.children`
     * → ['parent', 'children']. Method calls (`.all()`, `.eagerly()`) are not
     * segments and are skipped.
     *
     * @return list<string>
     */
    private function relationSegments(Node $source): array
    {
        $segments = [];
        $current = $source;

        while ($current instanceof GetAttrExpression) {
            if (! TwigNodeHelper::isMethodCall($current)) {
                $attr = TwigNodeHelper::attrName($current);
                if ($attr !== null) {
                    $segments[] = $attr;
                }
            }
            $current = $current->hasNode('node') ? $current->getNode('node') : null;
        }

        return array_reverse($segments);
    }

    /**
     * Descend an eager-load path set through the given relation segments,
     * returning the eager state of the collection reached. For each segment,
     * keep only paths whose head matches it and strip that head; a path with no
     * remaining tail contributed no deeper eager-loading. Example: descending
     * {'children.children.children'} through ['children'] yields
     * {'children.children'}; descending {'children'} through ['children']
     * yields {} (nothing deeper is eager-loaded, so the next level flags).
     *
     * @param  array<string, true>  $eager
     * @param  list<string>  $segments
     * @return array<string, true>
     */
    private function descendEagerPaths(array $eager, array $segments): array
    {
        $set = $eager;

        foreach ($segments as $segment) {
            $next = [];
            foreach (array_keys($set) as $path) {
                $parts = explode('.', $path);
                if ($parts[0] === $segment && count($parts) > 1) {
                    $next[implode('.', array_slice($parts, 1))] = true;
                }
            }
            $set = $next;
        }

        return $set;
    }
}
