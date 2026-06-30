<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
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
     * dynamic with(...) was present → suppress N+1 for this loop.
     *
     * @var list<array{var: string, eagerLoaded: array<string, true>|null}>
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

            $this->enterLoop($node);
            foreach ($node as $child) {
                if ($child === $seq) {
                    continue;
                }
                $this->walk($child);
            }
            array_pop($this->loopStack);

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

        $frame = $this->loopStack[count($this->loopStack) - 1];
        $chainMethodsAboveRelation = [];
        $current = $node;

        while ($current instanceof GetAttrExpression) {
            $object = $current->hasNode('node') ? $current->getNode('node') : null;
            $objectName = $object instanceof Node ? TwigNodeHelper::nameOf($object) : null;

            if ($objectName === $frame['var']) {
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
        if ($eager === null || isset($eager[$attr])) {
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
        if (! $this->isActiveLoopVar($objectName) || ! $this->relations->isRelation($attr)) {
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

    private function isActiveLoopVar(string $name): bool
    {
        foreach ($this->loopStack as $frame) {
            if ($frame['var'] === $name) {
                return true;
            }
        }

        return false;
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
        }
    }

    private function firstChild(Node $wrapper): ?Node
    {
        foreach ($wrapper as $child) {
            return $child instanceof Node ? $child : null;
        }

        return null;
    }

    private function enterLoop(ForNode $node): void
    {
        $valueTarget = $node->getNode('value_target');
        $varName = $valueTarget->hasAttribute('name') ? (string) $valueTarget->getAttribute('name') : '';

        $source = $node->getNode('seq');
        $resolved = $this->resolveSource($source);

        $this->loopStack[] = [
            'var' => $varName,
            'eagerLoaded' => TwigNodeHelper::eagerLoadedRelations($resolved),
        ];
    }

    /**
     * If the loop source is a bare variable assigned one level back, use that
     * expression; otherwise use the source expression as-is.
     */
    private function resolveSource(Node $source): Node
    {
        $name = TwigNodeHelper::nameOf($source);
        if ($name !== null && isset($this->assignments[$name])) {
            return $this->assignments[$name];
        }

        return $source;
    }
}
