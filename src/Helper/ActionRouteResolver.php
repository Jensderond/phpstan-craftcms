<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Helper;

use ReflectionClass;

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

                // Resolve the controller's default action (Yii2 defaults to 'index')
                $defaultActionId = self::resolveDefaultActionId($controllerFqcn);

                foreach ($methods as $methodName) {
                    $actionId = self::methodNameToActionId($methodName);

                    // Build route: "handle/controller-id/action-id" or "controller-id/action-id" for Craft core
                    $route = $handle !== ''
                        ? $handle.'/'.$controllerId.'/'.$actionId
                        : $controllerId.'/'.$actionId;

                    $routes[$route] = true;

                    // Also register shorthand route when action matches the controller's defaultAction
                    // e.g. "job-quiz/result" as shorthand for "job-quiz/result/index"
                    if ($actionId === $defaultActionId) {
                        $shortRoute = $handle !== ''
                            ? $handle.'/'.$controllerId
                            : $controllerId;

                        $routes[$shortRoute] = true;
                    }
                }
            }
        }

        return $routes;
    }

    /**
     * Resolve the default action ID for a controller class.
     *
     * Reads the $defaultAction property from the controller (falls back to 'index',
     * which is Yii2's default). Converts the value to a kebab-case action ID.
     */
    private static function resolveDefaultActionId(string $controllerFqcn): string
    {
        try {
            $reflection = new ReflectionClass($controllerFqcn);
            $property = $reflection->getProperty('defaultAction');
            $defaultValue = $property->getDefaultValue();

            if (is_string($defaultValue) && $defaultValue !== '') {
                return self::camelToId($defaultValue);
            }
        } catch (\ReflectionException) {
            // Class not loadable or property doesn't exist — fall back to Yii2 default
        }

        return 'index';
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
