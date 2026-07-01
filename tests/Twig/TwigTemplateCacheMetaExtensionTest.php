<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Twig;

use Jensderond\PhpstanCraftcms\Twig\TwigTemplateCacheMetaExtension;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateScanner;
use PHPUnit\Framework\TestCase;

final class TwigTemplateCacheMetaExtensionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/'.uniqid('twig_meta_', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function extension(): TwigTemplateCacheMetaExtension
    {
        $scanner = new TwigTemplateScanner(new TwigTemplateParser, [$this->dir], []);

        return new TwigTemplateCacheMetaExtension($scanner);
    }

    public function test_key_is_stable(): void
    {
        self::assertSame('craftTwigTemplates', $this->extension()->getKey());
    }

    public function test_hash_is_deterministic_for_unchanged_tree(): void
    {
        file_put_contents($this->dir.'/a.twig', '{{ foo }}');

        self::assertSame($this->extension()->getHash(), $this->extension()->getHash());
    }

    public function test_hash_changes_when_template_content_changes(): void
    {
        $path = $this->dir.'/a.twig';
        file_put_contents($path, '{{ foo }}');
        $before = $this->extension()->getHash();

        file_put_contents($path, '{{ bar }}');
        $after = $this->extension()->getHash();

        self::assertNotSame($before, $after);
    }

    public function test_hash_changes_when_template_is_added(): void
    {
        file_put_contents($this->dir.'/a.twig', '{{ foo }}');
        $before = $this->extension()->getHash();

        file_put_contents($this->dir.'/b.twig', '{{ foo }}');
        $after = $this->extension()->getHash();

        self::assertNotSame($before, $after);
    }

    public function test_hash_changes_when_template_is_removed(): void
    {
        file_put_contents($this->dir.'/a.twig', '{{ foo }}');
        file_put_contents($this->dir.'/b.twig', '{{ foo }}');
        $before = $this->extension()->getHash();

        unlink($this->dir.'/b.twig');
        $after = $this->extension()->getHash();

        self::assertNotSame($before, $after);
    }
}
