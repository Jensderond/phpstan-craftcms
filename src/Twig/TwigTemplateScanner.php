<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Single source of truth for walking the configured template directories.
 *
 * Both the action-input scanning and the performance scanning go through this
 * service so the template tree is traversed once and each `.twig` file is
 * opened/parsed at most once (lazily, via {@see ScannedTemplate}). It is also
 * used by {@see TwigTemplateCacheMetaExtension} to hash the discovered files so
 * PHPStan's result cache is invalidated when any template changes.
 *
 * @phpstan-type ScanEntry ScannedTemplate
 */
final class TwigTemplateScanner
{
    /** @var list<string> */
    private readonly array $templatePaths;

    /**
     * The action-input and performance configs are separate parameters that
     * usually — but need not — point at the same directories. Both lists are
     * merged and de-duplicated here so the tree is walked once and every
     * consumer sees every configured directory.
     *
     * @param  list<string>  $actionInputPaths
     * @param  list<string>  $performancePaths
     */
    public function __construct(
        private readonly TwigTemplateParser $parser,
        array $actionInputPaths,
        array $performancePaths,
    ) {
        $merged = [];
        foreach ([...$actionInputPaths, ...$performancePaths] as $path) {
            $merged[$path] = true;
        }

        $this->templatePaths = array_keys($merged);
    }

    /**
     * Absolute, de-duplicated realpaths of every `.twig` file under the
     * configured template directories, sorted for a stable order.
     *
     * @return list<string>
     */
    public function templateFiles(): array
    {
        $paths = [];

        foreach ($this->templatePaths as $templatePath) {
            if (! is_dir($templatePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($templatePath, RecursiveDirectoryIterator::SKIP_DOTS),
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

                $paths[$realPath] = true;
            }
        }

        $files = array_keys($paths);
        sort($files);

        return $files;
    }

    /**
     * Every discovered template as a lazily-readable {@see ScannedTemplate}.
     *
     * @return Generator<ScannedTemplate>
     */
    public function scan(): Generator
    {
        foreach ($this->templateFiles() as $path) {
            yield new ScannedTemplate($path, $this->parser);
        }
    }
}
