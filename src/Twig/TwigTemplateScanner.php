<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Generator;
use RecursiveCallbackFilterIterator;
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

    /** @var list<string> */
    private readonly array $excludeDirectories;

    /**
     * The action-input and performance configs are separate parameters that
     * usually — but need not — point at the same directories. Both lists are
     * merged and de-duplicated here so the tree is walked once and every
     * consumer sees every configured directory.
     *
     * @param  list<string>  $actionInputPaths
     * @param  list<string>  $performancePaths
     * @param  list<string>  $excludeDirectories  Directory names (not paths)
     *                                            whose subtrees are skipped, e.g. `vendor`, `node_modules`.
     *                                            Prunes third-party templates a package bundles under
     *                                            `vendor/` (Craft's own CP templates, other plugins' assets)
     *                                            when a template path points at a directory containing them.
     */
    public function __construct(
        private readonly TwigTemplateParser $parser,
        array $actionInputPaths,
        array $performancePaths,
        array $excludeDirectories = [],
    ) {
        $merged = [];
        foreach ([...$actionInputPaths, ...$performancePaths] as $path) {
            $merged[$path] = true;
        }

        $this->templatePaths = array_keys($merged);
        $this->excludeDirectories = array_values($excludeDirectories);
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

            $directory = new RecursiveDirectoryIterator($templatePath, RecursiveDirectoryIterator::SKIP_DOTS);

            // Prune excluded directories during descent so their subtrees are
            // never entered — cheaper than filtering matched files afterwards.
            $filtered = new RecursiveCallbackFilterIterator(
                $directory,
                function (SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), $this->excludeDirectories, true);
                    }

                    return true;
                },
            );

            $iterator = new RecursiveIteratorIterator($filtered);

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
