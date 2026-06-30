<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Twig;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
use Jensderond\PhpstanCraftcms\Twig\Finding;
use Jensderond\PhpstanCraftcms\Twig\TwigPerformanceAnalyzer;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
use PHPUnit\Framework\TestCase;

final class TwigPerformanceAnalyzerTest extends TestCase
{
    /**
     * @param  array<string, bool>  $checks
     * @return list<Finding>
     */
    private function analyze(string $code, array $checks = ['nPlusOne' => true]): array
    {
        $module = (new TwigTemplateParser)->parseSource($code);
        self::assertNotNull($module);

        $registry = new RelationFieldRegistry(__DIR__.'/../fixtures/projectConfig');

        return (new TwigPerformanceAnalyzer($registry, $checks))->analyze($module);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function identifiers(array $findings): array
    {
        return array_map(static fn (Finding $f): string => $f->identifier, $findings);
    }

    public function test_flags_relational_access_in_loop(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.section('news').all() %}
          {{ entry.author.fullName }}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_when_eager_loaded_with_with(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.section('news').with(['author']).all() %}
          {{ entry.author.fullName }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_when_access_uses_eagerly(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.all() %}
          {{ entry.author.eagerly().one().fullName }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_when_with_args_are_dynamic(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.with(eagerFields).all() %}
          {{ entry.author.fullName }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_non_relational_access(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.all() %}
          {{ entry.summary }}{{ entry.title }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_resolves_loop_source_from_set_one_level_back(): void
    {
        $code = <<<'TWIG'
        {% set entries = craft.entries.all() %}
        {% for entry in entries %}
          {{ entry.author.fullName }}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }
}
