<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Rule;

use Jensderond\PhpstanCraftcms\Twig\TwigPerformanceAnalyzer;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
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
 * Reports Twig N+1 and performance findings across the configured template tree.
 *
 * @implements Rule<CollectedDataNode>
 */
final class TwigPerformanceRule implements Rule
{
    /**
     * @param  list<string>  $templatePaths
     */
    public function __construct(
        private readonly TwigTemplateParser $parser,
        private readonly TwigPerformanceAnalyzer $analyzer,
        private readonly array $templatePaths,
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

        foreach ($this->templatePaths as $templatePath) {
            if (! is_dir($templatePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($templatePath),
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

                $this->scanFile($realPath, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param  list<RuleError>  $errors
     */
    private function scanFile(string $filePath, array &$errors): void
    {
        $module = $this->parser->parseFile($filePath);
        if ($module === null) {
            return;
        }

        foreach ($this->analyzer->analyze($module) as $finding) {
            $builder = RuleErrorBuilder::message($finding->message)
                ->file($filePath)
                ->line($finding->line)
                ->identifier($finding->identifier);

            if ($finding->tip !== null) {
                $builder->tip($finding->tip);
            }

            $errors[] = $builder->build();
        }
    }
}
