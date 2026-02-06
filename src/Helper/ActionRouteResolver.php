<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Helper;

final class ActionRouteResolver
{
    /**
     * Convert a controller class name to a Yii2 controller ID.
     *
     * e.g. "EntriesController" → "entries", "CspSourcesController" → "csp-sources"
     */
    public static function classNameToControllerId(string $className): string
    {
        // Strip namespace — take only the short class name
        if (($pos = strrpos($className, '\\')) !== false) {
            $className = substr($className, $pos + 1);
        }

        // Remove "Controller" suffix
        if (str_ends_with($className, 'Controller')) {
            $className = substr($className, 0, -10);
        }

        return self::camelToId($className);
    }

    /**
     * Convert a controller action method name to a Yii2 action ID.
     *
     * e.g. "actionSaveEntry" → "save-entry", "actionGetAll" → "get-all"
     */
    public static function methodNameToActionId(string $methodName): string
    {
        // Strip "action" prefix
        if (str_starts_with($methodName, 'action')) {
            $methodName = substr($methodName, 6);
        }

        return self::camelToId($methodName);
    }

    /**
     * Build a set of valid route strings from handle→namespace map and collected controller actions.
     *
     * @param  array<string, string>  $handleToNamespace  Map of module handle → controller namespace
     * @param  array<string, list<string>>  $controllerActions  Map of controller FQCN → list of action method names
     * @return array<string, true> Set of valid route strings (lowercased)
     */
    public static function buildValidRoutes(array $handleToNamespace, array $controllerActions): array
    {
        /** @var array<string, true> $routes */
        $routes = [];

        foreach ($controllerActions as $controllerFqcn => $methods) {
            $controllerId = self::classNameToControllerId($controllerFqcn);

            // Find which handle(s) this controller belongs to
            foreach ($handleToNamespace as $handle => $namespace) {
                if (! str_starts_with($controllerFqcn, $namespace.'\\')) {
                    continue;
                }

                foreach ($methods as $methodName) {
                    $actionId = self::methodNameToActionId($methodName);

                    // Build route: "handle/controller-id/action-id" or "controller-id/action-id" for Craft core
                    $route = $handle !== ''
                        ? $handle.'/'.$controllerId.'/'.$actionId
                        : $controllerId.'/'.$actionId;

                    $routes[$route] = true;
                }
            }
        }

        return $routes;
    }

    /**
     * Mirror Yii2's Inflector::camel2id() with $strict=false (default).
     *
     * Regex: /(?<!\p{Lu})\p{Lu}/u — inserts separator before any uppercase letter
     * that is NOT preceded by another uppercase letter.
     */
    private static function camelToId(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $result = preg_replace('/(?<!\p{Lu})\p{Lu}/u', '-\0', $input);
        $result = mb_strtolower(trim($result ?? $input, '-'));

        return $result;
    }
}
