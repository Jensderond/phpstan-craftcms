<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Rule;

use Jensderond\PhpstanCraftcms\Collector\ControllerActionCollector;
use Jensderond\PhpstanCraftcms\Helper\ActionRouteMap;
use Jensderond\PhpstanCraftcms\Helper\ActionRouteResolver;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateScanner;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validates that actionInput() calls in Twig templates reference valid controller action routes.
 *
 * Template traversal is delegated to {@see TwigTemplateScanner} (shared with
 * the performance scanning); this rule only needs each template's raw contents
 * for its regex match.
 *
 * @implements Rule<CollectedDataNode>
 */
final class TwigActionInputRule implements Rule
{
    public function __construct(
        private readonly ActionRouteMap $actionRouteMap,
        private readonly TwigTemplateScanner $scanner,
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

        foreach ($this->scanner->scan() as $template) {
            $contents = $template->contents();

            if ($contents === null) {
                continue;
            }

            $this->scanTwigFile($template->path(), $contents, $validRoutes, $errors);
        }

        return $errors;
    }

    /**
     * @param  array<string, true>  $validRoutes
     * @param  list<RuleError>  $errors
     */
    private function scanTwigFile(string $filePath, string $contents, array $validRoutes, array &$errors): void
    {
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
