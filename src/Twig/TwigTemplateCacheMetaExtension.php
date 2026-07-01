<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;

/**
 * Makes PHPStan's result cache aware of the `.twig` template tree.
 *
 * PHPStan keys its result cache on the analysed PHP files + config and knows
 * nothing about `.twig` files. Because {@see \Jensderond\PhpstanCraftcms\Rule\TwigActionInputRule}
 * and {@see \Jensderond\PhpstanCraftcms\Rule\TwigPerformanceRule} scan templates
 * out-of-band, a warm result cache would otherwise serve stale Twig findings
 * (or skip the checks) after a template-only edit.
 *
 * By contributing a hash of every discovered template's path + contents to the
 * result-cache metadata (via the `phpstan.resultCacheMetaExtension` tag), any
 * template change — content edit, addition, or removal — changes this hash,
 * which PHPStan detects as differing metadata and responds to by re-running a
 * full analysis. The Twig checks therefore stay integrated in `phpstan analyse`
 * and stay correct.
 *
 * @see ResultCacheMetaExtension
 */
final class TwigTemplateCacheMetaExtension implements ResultCacheMetaExtension
{
    public function __construct(
        private readonly TwigTemplateScanner $scanner,
    ) {}

    public function getKey(): string
    {
        return 'craftTwigTemplates';
    }

    public function getHash(): string
    {
        $parts = [];

        foreach ($this->scanner->templateFiles() as $path) {
            $contents = @file_get_contents($path);
            // Fold path + content into the digest so edits, additions and
            // removals all shift the hash. Unreadable files contribute their
            // path only, so a permission flip is still observed.
            $parts[] = $path.':'.($contents === false ? '' : hash('xxh128', $contents));
        }

        return hash('xxh128', implode("\n", $parts));
    }
}
