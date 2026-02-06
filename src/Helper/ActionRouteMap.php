<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Helper;

use ReflectionClass;
use ReflectionMethod;

final class ActionRouteMap
{
    /**
     * @var array<string, string> Map of module handle → controller namespace
     */
    private array $handleMap = [];

    /**
     * @var array<string, list<string>> Controller FQCN → list of action method names (from autoloader discovery)
     */
    private array $discoveredControllerActions = [];

    private ?string $projectRoot = null;

    /**
     * @param  array<string, string>  $handleMap  Manual overrides/additions from neon config
     */
    public function __construct(string $configPath, array $handleMap = [])
    {
        // Always register Craft core controllers
        $this->handleMap[''] = 'craft\\controllers';

        if (file_exists($configPath)) {
            // config/app.php is at <projectRoot>/config/app.php
            $this->projectRoot = dirname($configPath, 2);

            $this->parseConfig($configPath);

            // Discover Craft plugins from vendor/craftcms/plugins.php
            $pluginsPath = $this->projectRoot.'/vendor/craftcms/plugins.php';
            if (file_exists($pluginsPath)) {
                $this->parsePlugins($pluginsPath);
            }
        }

        // Manual overrides take precedence
        foreach ($handleMap as $handle => $namespace) {
            $this->handleMap[$handle] = $namespace;
        }

        // Discover controllers by scanning filesystem using PSR-4 mappings
        $this->discoverControllersFromAutoloader();
    }

    /**
     * @return array<string, string>
     */
    public function getHandleMap(): array
    {
        return $this->handleMap;
    }

    /**
     * @return array<string, list<string>> Controller FQCN → action method names
     */
    public function getDiscoveredControllerActions(): array
    {
        return $this->discoveredControllerActions;
    }

    private function parseConfig(string $configPath): void
    {
        defined('YII_DEBUG') || define('YII_DEBUG', true);
        defined('YII_ENV_DEV') || define('YII_ENV_DEV', false);
        defined('YII_ENV_PROD') || define('YII_ENV_PROD', false);
        defined('YII_ENV_TEST') || define('YII_ENV_TEST', true);

        /** @var array<string, mixed> $config */
        $config = require $configPath;

        // Multi-env config: modules under '*' key
        /** @var array<string, mixed> $modules */
        $modules = $config['*']['modules'] ?? $config['modules'] ?? [];

        foreach ($modules as $handle => $moduleDefinition) {
            $moduleClass = $this->resolveModuleClass($moduleDefinition);

            if ($moduleClass === null) {
                continue;
            }

            $controllerNamespace = $this->deriveControllerNamespace($moduleClass);
            $this->handleMap[$handle] = $controllerNamespace;
        }
    }

    /**
     * Parse vendor/craftcms/plugins.php for plugin handle → controller namespace mappings.
     */
    private function parsePlugins(string $pluginsPath): void
    {
        /** @var array<string, array{class: string, handle: string, basePath?: string}> $plugins */
        $plugins = require $pluginsPath;

        foreach ($plugins as $pluginConfig) {
            $handle = $pluginConfig['handle'] ?? null;
            $class = $pluginConfig['class'] ?? null;

            if (! is_string($handle) || ! is_string($class)) {
                continue;
            }

            $controllerNamespace = $this->deriveControllerNamespace($class);
            $this->handleMap[$handle] = $controllerNamespace;
        }
    }

    /**
     * Discover controller classes and their action methods by scanning controller directories.
     *
     * Uses Composer's autoload_psr4.php to resolve namespaces to filesystem paths, then
     * scans for *Controller.php files and reflects their action methods.
     */
    private function discoverControllersFromAutoloader(): void
    {
        /** @var array<string, list<string>> $psr4Map */
        $psr4Map = $this->loadPsr4Map();

        if ($psr4Map === []) {
            return;
        }

        foreach ($this->handleMap as $controllerNamespace) {
            $nsPrefix = $controllerNamespace.'\\';

            foreach ($psr4Map as $prefix => $dirs) {
                if (! str_starts_with($nsPrefix, $prefix)) {
                    continue;
                }

                // Calculate the subdirectory relative to the PSR-4 base
                $relativeNs = substr($controllerNamespace, strlen($prefix));
                $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeNs);

                foreach ($dirs as $dir) {
                    $controllerDir = $dir.DIRECTORY_SEPARATOR.$relativePath;

                    if (! is_dir($controllerDir)) {
                        continue;
                    }

                    $this->scanControllerDirectory($controllerDir, $controllerNamespace);
                }
            }
        }
    }

    /**
     * Load the Composer PSR-4 autoload map from vendor/composer/autoload_psr4.php.
     *
     * @return array<string, list<string>>
     */
    private function loadPsr4Map(): array
    {
        if ($this->projectRoot === null) {
            return [];
        }

        $psr4Path = $this->projectRoot.'/vendor/composer/autoload_psr4.php';

        if (! file_exists($psr4Path)) {
            return [];
        }

        /** @var array<string, list<string>> */
        return require $psr4Path;
    }

    /**
     * Scan a directory for Controller classes and collect their action methods.
     */
    private function scanControllerDirectory(string $directory, string $namespace): void
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'*Controller.php');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $className = $namespace.'\\'.basename($file, '.php');

            if (! class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (\ReflectionException) {
                continue;
            }

            if ($reflection->isAbstract()) {
                continue;
            }

            if (! $reflection->isSubclassOf('yii\\web\\Controller')) {
                continue;
            }

            /** @var list<string> $actionMethods */
            $actionMethods = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();

                if (! str_starts_with($name, 'action') || $name === 'action' || $name === 'actions') {
                    continue;
                }

                $actionMethods[] = $name;
            }

            if ($actionMethods !== []) {
                $this->discoveredControllerActions[$className] = $actionMethods;
            }
        }
    }

    /**
     * Resolve the module class from various config formats.
     *
     * Supports:
     * - String class name: 'modules\mymodule\MyModule'
     * - Array with 'class' key: ['class' => 'modules\mymodule\MyModule', ...]
     */
    private function resolveModuleClass(mixed $moduleDefinition): ?string
    {
        if (is_string($moduleDefinition) && (class_exists($moduleDefinition) || str_contains($moduleDefinition, '\\'))) {
            return $moduleDefinition;
        }

        if (is_array($moduleDefinition) && isset($moduleDefinition['class']) && is_string($moduleDefinition['class'])) {
            return $moduleDefinition['class'];
        }

        return null;
    }

    /**
     * Derive the controller namespace from a module class.
     *
     * e.g. "modules\recruiteeconnector\RecruiteeConnector" → "modules\recruiteeconnector\controllers"
     */
    private function deriveControllerNamespace(string $moduleClass): string
    {
        $lastBackslash = strrpos($moduleClass, '\\');

        if ($lastBackslash === false) {
            return $moduleClass.'\\controllers';
        }

        return substr($moduleClass, 0, $lastBackslash).'\\controllers';
    }
}
