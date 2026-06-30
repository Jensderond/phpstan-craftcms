<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
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
            $this->enterLoop($node);
            foreach ($node as $child) {
                $this->walk($child);
            }
            array_pop($this->loopStack);

            return;
        }

        if ($node instanceof GetAttrExpression) {
            $this->checkChain($node);
            $this->walkChainSideBranches($node);

            return;
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
