<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Rule;

use Jensderond\PhpstanCraftcms\Collector\ControllerActionCollector;
use Jensderond\PhpstanCraftcms\Helper\ActionRouteMap;
use Jensderond\PhpstanCraftcms\Helper\ActionRouteResolver;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Validates that actionInput() calls in Twig templates reference valid controller action routes.
 *
 * @implements Rule<CollectedDataNode>
 */
final class TwigActionInputRule implements Rule
{
    /**
     * @param  list<string>  $templatePaths
     */
    public function __construct(
        private readonly ActionRouteMap $actionRouteMap,
        private readonly array $templatePaths,
    ) {}

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param  CollectedDataNode  $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Gather all collected controller actions
        /** @var array<string, list<array{string, list<string>}>> $collectedData */
        $collectedData = $node->get(ControllerActionCollector::class);

        /** @var array<string, list<string>> $controllerActions */
        $controllerActions = [];

        foreach ($collectedData as $fileData) {
            foreach ($fileData as [$controllerFqcn, $methods]) {
                // Merge methods — a controller may appear from multiple files (traits, etc.)
                $existing = $controllerActions[$controllerFqcn] ?? [];
                $controllerActions[$controllerFqcn] = array_values(array_unique([...$existing, ...$methods]));
            }
        }

        // Merge autoloader-discovered controllers (plugins, Craft core) with collected data
        foreach ($this->actionRouteMap->getDiscoveredControllerActions() as $fqcn => $methods) {
            if (isset($controllerActions[$fqcn])) {
                continue;
            }
            $controllerActions[$fqcn] = $methods;
        }

        $validRoutes = ActionRouteResolver::buildValidRoutes(
            $this->actionRouteMap->getHandleMap(),
            $controllerActions,
        );

        // Scan Twig templates
        /** @var list<RuleError> $errors */
        $errors = [];

        foreach ($this->templatePaths as $templatePath) {
            if (! is_dir($templatePath)) {
                continue;
            }

            $this->scanDirectory($templatePath, $validRoutes, $errors);
        }

        return $errors;
    }

    /**
     * @param  array<string, true>  $validRoutes
     * @param  list<RuleError>  $errors
     */
    private function scanDirectory(string $directory, array $validRoutes, array &$errors): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            $realPath = $file->getRealPath();

            if ($realPath === false) {
                continue;
            }

            $this->scanTwigFile($realPath, $validRoutes, $errors);
        }
    }

    /**
     * @param  array<string, true>  $validRoutes
     * @param  list<RuleError>  $errors
     */
    private function scanTwigFile(string $filePath, array $validRoutes, array &$errors): void
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return;
        }

        // Match actionInput('route/string') or actionInput("route/string")
        if (! preg_match_all('/actionInput\(\s*[\'"]([^\'"]+)[\'"]/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as [$actionString, $offset]) {
            // Calculate line number from byte offset
            $line = substr_count($contents, "\n", 0, $offset) + 1;

            if (isset($validRoutes[$actionString])) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                sprintf('Action route "%s" does not match any controller action.', $actionString),
            )
                ->file($filePath)
                ->line($line)
                ->identifier('craftcms.invalidActionInput')
                ->build();
        }
    }
}
