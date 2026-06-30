<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Twig;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
use Jensderond\PhpstanCraftcms\Twig\Finding;
use Jensderond\PhpstanCraftcms\Twig\TwigPerformanceAnalyzer;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
use PHPUnit\Framework\TestCase;

final class TwigPerformanceAnalyzerChecksTest extends TestCase
{
    private const ALL_CHECKS = [
        'nPlusOne' => true,
        'nestedRelationAll' => true,
        'queryInLoop' => true,
        'lengthOnQuery' => true,
        'unboundedAll' => true,
    ];

    /**
     * @param  array<string, bool>  $checks
     * @return list<string>
     */
    private function ids(string $code, array $checks = self::ALL_CHECKS): array
    {
        $module = (new TwigTemplateParser)->parseSource($code);
        self::assertNotNull($module);
        $registry = new RelationFieldRegistry(__DIR__.'/../fixtures/projectConfig');
        $findings = (new TwigPerformanceAnalyzer($registry, $checks))->analyze($module);

        return array_map(static fn (Finding $f): string => $f->identifier, $findings);
    }

    public function test_nested_relation_all_in_loop(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.all() %}
          {% for cat in entry.relatedEntries.all() %}{{ cat.title }}{% endfor %}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigNestedRelationAll', $this->ids($code));
    }

    public function test_nested_relation_all_suppressed_by_eagerly(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.all() %}
          {% for cat in entry.relatedEntries.eagerly().all() %}{{ cat.title }}{% endfor %}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNestedRelationAll', $this->ids($code));
    }

    public function test_query_built_in_loop(): void
    {
        $code = <<<'TWIG'
        {% for id in ids %}
          {% set e = craft.entries.id(id).one() %}{{ e.title }}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigQueryInLoop', $this->ids($code));
    }

    public function test_length_on_query(): void
    {
        $code = '{{ craft.entries.section("news")|length }}';
        self::assertContains('craftcms.twigLengthOnQuery', $this->ids($code));
    }

    public function test_length_on_plain_array_not_flagged(): void
    {
        $code = '{% set xs = [1, 2, 3] %}{{ xs|length }}';
        self::assertNotContains('craftcms.twigLengthOnQuery', $this->ids($code));
    }

    public function test_unbounded_all_flagged_when_enabled(): void
    {
        $code = '{% set entries = craft.entries.section("news").all() %}';
        self::assertContains('craftcms.twigUnboundedAll', $this->ids($code));
    }

    public function test_unbounded_all_silent_when_disabled(): void
    {
        $code = '{% set entries = craft.entries.section("news").all() %}';
        self::assertNotContains('craftcms.twigUnboundedAll', $this->ids($code, ['unboundedAll' => false]));
    }

    public function test_unbounded_all_not_flagged_with_limit(): void
    {
        $code = '{% set entries = craft.entries.limit(10).all() %}';
        self::assertNotContains('craftcms.twigUnboundedAll', $this->ids($code));
    }

    public function test_top_level_loop_source_query_not_flagged_as_query_in_loop(): void
    {
        $code = <<<'TWIG'
        {% for entry in craft.entries.section("news").all() %}{{ entry.title }}{% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigQueryInLoop', $this->ids($code, [
            'queryInLoop' => true,
            'unboundedAll' => false,
        ]));
    }

    public function test_nested_loop_source_query_flagged_as_query_in_loop(): void
    {
        $code = <<<'TWIG'
        {% for entry in entries %}
          {% for asset in craft.assets.volume("x").all() %}{{ asset.title }}{% endfor %}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigQueryInLoop', $this->ids($code, [
            'queryInLoop' => true,
            'unboundedAll' => false,
        ]));
    }
}
