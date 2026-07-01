<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Rule;

use Jensderond\PhpstanCraftcms\Twig\TwigPerformanceAnalyzer;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateScanner;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports Twig N+1 and performance findings across the configured template tree.
 *
 * The traversal itself is delegated to {@see TwigTemplateScanner} so the tree
 * is walked once and shared with the action-input scanning. Result-cache
 * correctness for template edits is handled by
 * {@see \Jensderond\PhpstanCraftcms\Twig\TwigTemplateCacheMetaExtension}.
 *
 * @implements Rule<CollectedDataNode>
 */
final class TwigPerformanceRule implements Rule
{
    public function __construct(
        private readonly TwigTemplateScanner $scanner,
        private readonly TwigPerformanceAnalyzer $analyzer,
        private readonly bool $enabled,
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
        return $this->scanTemplates();
    }

    /**
     * @return list<RuleError>
     */
    public function scanTemplates(): array
    {
        if (! $this->enabled) {
            return [];
        }

        /** @var list<RuleError> $errors */
        $errors = [];

        foreach ($this->scanner->scan() as $template) {
            $module = $template->module();
            if ($module === null) {
                continue;
            }

            foreach ($this->analyzer->analyze($module) as $finding) {
                $builder = RuleErrorBuilder::message($finding->message)
                    ->file($template->path())
                    ->line($finding->line)
                    ->identifier($finding->identifier);

                if ($finding->tip !== null) {
                    $builder->tip($finding->tip);
                }

                $errors[] = $builder->build();
            }
        }

        return $errors;
    }
}
