<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Twig;

use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
use PHPUnit\Framework\TestCase;
use Twig\Node\ModuleNode;

final class TwigTemplateParserTest extends TestCase
{
    private TwigTemplateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TwigTemplateParser;
    }

    public function test_parses_plain_template(): void
    {
        $module = $this->parser->parseSource('{{ entry.title }}');
        self::assertInstanceOf(ModuleNode::class, $module);
    }

    public function test_parses_craft_custom_tags_without_error(): void
    {
        $code = <<<'TWIG'
        {% cache %}
          {% nav node in nodes %}
            {{ node.title }}
          {% endnav %}
          {% switch entry.type %}
            {% case "news" %}news{% endcase %}
          {% endswitch %}
          {% exit 404 %}
        {% endcache %}
        TWIG;

        self::assertInstanceOf(ModuleNode::class, $this->parser->parseSource($code));
    }

    public function test_parses_unknown_functions_and_filters(): void
    {
        $code = '{{ getCsrfInput() }}{{ entry.body|markdown|t }}';
        self::assertInstanceOf(ModuleNode::class, $this->parser->parseSource($code));
    }

    public function test_returns_null_on_broken_template(): void
    {
        // Unterminated block — unrecoverable even for a permissive parser.
        self::assertNull($this->parser->parseSource('{% for x in y %}'));
    }

    public function test_parses_file_from_disk(): void
    {
        // Create a temporary file with Twig content.
        $tempPath = sys_get_temp_dir().'/'.uniqid('twig_test_', true).'.twig';
        file_put_contents($tempPath, '{{ entry.title }}');

        try {
            $module = $this->parser->parseFile($tempPath);
            self::assertInstanceOf(ModuleNode::class, $module);
        } finally {
            unlink($tempPath);
        }
    }

    public function test_returns_null_for_missing_file(): void
    {
        $module = $this->parser->parseFile('/does/not/exist/nope.twig');
        self::assertNull($module);
    }
}
