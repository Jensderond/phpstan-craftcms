<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Twig;

use Jensderond\PhpstanCraftcms\Twig\ScannedTemplate;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateScanner;
use PHPUnit\Framework\TestCase;
use Twig\Node\ModuleNode;

final class TwigTemplateScannerTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/templates';

    private function scanner(array $actionInputPaths, array $performancePaths = [], array $excludeDirectories = []): TwigTemplateScanner
    {
        return new TwigTemplateScanner(new TwigTemplateParser, $actionInputPaths, $performancePaths, $excludeDirectories);
    }

    public function test_discovers_all_twig_files(): void
    {
        $files = $this->scanner([self::FIXTURES])->templateFiles();

        $basenames = array_map('basename', $files);
        self::assertContains('clean.twig', $basenames);
        self::assertContains('nplusone.twig', $basenames);
    }

    public function test_prunes_excluded_directories(): void
    {
        $basenames = array_map('basename', $this->scanner([self::FIXTURES], [], ['vendor'])->templateFiles());

        self::assertNotContains('bundled.twig', $basenames);
        self::assertContains('clean.twig', $basenames);
    }

    public function test_excluded_directories_are_scanned_when_not_configured(): void
    {
        $basenames = array_map('basename', $this->scanner([self::FIXTURES])->templateFiles());

        self::assertContains('bundled.twig', $basenames);
    }

    public function test_returns_stable_sorted_order(): void
    {
        $files = $this->scanner([self::FIXTURES])->templateFiles();

        $sorted = $files;
        sort($sorted);
        self::assertSame($sorted, $files);
    }

    public function test_merges_and_deduplicates_both_path_lists(): void
    {
        // Same directory in both lists must not yield duplicate entries.
        $files = $this->scanner([self::FIXTURES], [self::FIXTURES])->templateFiles();

        self::assertSame(array_values(array_unique($files)), $files);
    }

    public function test_covers_directories_from_both_configs(): void
    {
        // A directory configured only for performance must still be scanned.
        $files = $this->scanner([], [self::FIXTURES])->templateFiles();

        self::assertNotSame([], $files);
    }

    public function test_ignores_missing_directories(): void
    {
        $files = $this->scanner(['/does/not/exist'])->templateFiles();

        self::assertSame([], $files);
    }

    public function test_scan_yields_lazily_readable_templates(): void
    {
        $templates = iterator_to_array($this->scanner([self::FIXTURES])->scan());

        self::assertNotEmpty($templates);
        self::assertContainsOnlyInstancesOf(ScannedTemplate::class, $templates);

        $first = $templates[0];
        self::assertIsString($first->contents());
        self::assertInstanceOf(ModuleNode::class, $first->module());
    }
}
