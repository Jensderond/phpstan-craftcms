<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Node;

final class TwigNodeHelper
{
    /**
     * The variable name a name-expression refers to, or null if $node is not one.
     */
    public static function nameOf(Node $node): ?string
    {
        if ($node->hasAttribute('name') && ! $node->hasNode('node')) {
            $name = $node->getAttribute('name');

            return is_string($name) ? $name : null;
        }

        return null;
    }

    /**
     * The accessor name of a GetAttr node (e.g. `author` in `entry.author`).
     */
    public static function attrName(GetAttrExpression $node): ?string
    {
        if (! $node->hasNode('attribute')) {
            return null;
        }

        $attr = $node->getNode('attribute');
        if ($attr instanceof ConstantExpression) {
            $value = $attr->getAttribute('value');

            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * True when the GetAttr is a method call (`foo.bar(...)`) rather than a
     * property/array access.
     */
    public static function isMethodCall(GetAttrExpression $node): bool
    {
        return $node->hasAttribute('type') && $node->getAttribute('type') === 'method';
    }

    /**
     * Walks a GetAttr/method chain and returns the set of accessor names used,
     * e.g. for `q.with([...]).all()` → ['with' => true, 'all' => true].
     *
     * @return array<string, true>
     */
    public static function methodsInChain(Node $node): array
    {
        $methods = [];
        $current = $node;

        while ($current instanceof GetAttrExpression) {
            if (self::isMethodCall($current)) {
                $name = self::attrName($current);
                if ($name !== null) {
                    $methods[$name] = true;
                }
            }
            $current = $current->hasNode('node') ? $current->getNode('node') : null;
            if (! $current instanceof Node) {
                break;
            }
        }

        return $methods;
    }

    /**
     * For a `with([...])` call somewhere in the chain rooted at $node, return the
     * literal string relation handles it eager-loads. Returns null when a
     * `with(...)` exists but its argument is not a literal string array (dynamic
     * args → caller must treat relations as possibly eager-loaded).
     *
     * @return array<string, true>|null
     */
    public static function eagerLoadedRelations(Node $node): ?array
    {
        $current = $node;

        while ($current instanceof GetAttrExpression) {
            if (self::isMethodCall($current) && self::attrName($current) === 'with' && $current->hasNode('arguments')) {
                return self::literalStringsFromArguments($current->getNode('arguments'));
            }
            $current = $current->hasNode('node') ? $current->getNode('node') : null;
            if (! $current instanceof Node) {
                break;
            }
        }

        return [];
    }

    /**
     * @return array<string, true>|null null when args are not a literal string list
     */
    private static function literalStringsFromArguments(Node $arguments): ?array
    {
        // $arguments is an ArrayExpression of call args: its children alternate
        // key (a LocalVariable holding the positional index) / value (the actual
        // argument expression). The first call argument's value is therefore at
        // index 1, not index 0.
        $first = self::firstArgumentValue($arguments);

        if ($first instanceof ConstantExpression) {
            $value = $first->getAttribute('value');

            return is_string($value) ? [$value => true] : null;
        }

        if ($first instanceof ArrayExpression) {
            $relations = [];
            foreach ($first->getKeyValuePairs() as $pair) {
                $element = $pair['value'];
                if (! $element instanceof ConstantExpression) {
                    return null; // dynamic element → unknown
                }
                $value = $element->getAttribute('value');
                if (is_string($value)) {
                    $relations[$value] = true;
                }
            }

            return $relations;
        }

        return null; // not a literal → unknown
    }

    private static function firstArgumentValue(Node $arguments): ?Node
    {
        if ($arguments instanceof ArrayExpression) {
            $pairs = $arguments->getKeyValuePairs();

            return $pairs === [] ? null : $pairs[0]['value'];
        }

        foreach ($arguments as $arg) {
            return $arg;
        }

        return null;
    }

    /**
     * Root variable name of a GetAttr/method chain, e.g. `craft` in
     * `craft.entries.all()`.
     */
    public static function rootName(Node $node): ?string
    {
        $current = $node;
        while ($current instanceof GetAttrExpression && $current->hasNode('node')) {
            $current = $current->getNode('node');
        }

        return self::nameOf($current);
    }

    /**
     * Heuristic: the chain looks like a Craft element query (`craft.entries`,
     * `craft.assets`, ... or a `.find()`/`.relatedTo()` builder).
     */
    public static function isQueryRooted(Node $node): bool
    {
        if (self::rootName($node) === 'craft') {
            return self::chainHasAnyAttr($node, ['entries', 'assets', 'users', 'categories', 'tags', 'addresses']);
        }

        $methods = self::methodsInChain($node);

        return isset($methods['find']) || isset($methods['relatedTo']);
    }

    /**
     * @param  list<string>  $names
     */
    public static function chainHasAnyAttr(Node $node, array $names): bool
    {
        foreach ($names as $name) {
            if (self::chainHasAttr($node, $name)) {
                return true;
            }
        }

        return false;
    }

    public static function chainHasAttr(Node $node, string $name): bool
    {
        $current = $node;
        while ($current instanceof GetAttrExpression) {
            if (self::attrName($current) === $name) {
                return true;
            }
            $current = $current->hasNode('node') ? $current->getNode('node') : null;
            if (! $current instanceof Node) {
                break;
            }
        }

        return false;
    }

    /**
     * The filter name of a FilterExpression (e.g. `length` in `foo|length`).
     * Verified against Twig 3.27 (the required floor): the `name` attribute is
     * always set by the FilterExpression constructor regardless of how it was
     * built, and the `filter` child node (when present) is a ConstantExpression
     * carrying the same value.
     */
    public static function filterName(FilterExpression $node): ?string
    {
        if ($node->hasAttribute('name')) {
            $name = $node->getAttribute('name');

            return is_string($name) ? $name : null;
        }

        if ($node->hasNode('filter')) {
            $filter = $node->getNode('filter');
            if ($filter instanceof ConstantExpression) {
                $value = $filter->getAttribute('value');

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
