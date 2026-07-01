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

    public function test_does_not_flag_relation_off_external_variable_chain(): void
    {
        // A partial (e.g. navigation/node.twig) receives an element that was
        // eager-loaded in a parent template and passed across an {% include %}.
        // `node` is undefined in this template, so its eager-load state is
        // unknowable here and must not be assumed absent.
        $code = <<<'TWIG'
        {% for child in node.children %}
          {{ child.children | length }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_relation_off_external_bare_variable(): void
    {
        // `entries` is supplied from outside this template (include var/global);
        // we cannot see whether the upstream query eager-loaded `author`.
        $code = <<<'TWIG'
        {% for entry in entries %}
          {{ entry.author.fullName }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_eager_loaded_relation_when_source_has_fallback(): void
    {
        // The `... .all() ?? []` idiom must not hide the query: `children` IS
        // eager-loaded here (footer/careers navigation pattern), so accessing it
        // on the loop items is not an N+1.
        $code = <<<'TWIG'
        {% set nodes = craft.navigation.nodes().handle('main').with(['children']).all() ?? [] %}
        {% for node in nodes %}
          {{ node.children | length }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_still_flags_deeper_relation_not_eager_loaded_behind_fallback(): void
    {
        // Only `children` (level 2) is eager-loaded; `child.children` (level 3)
        // is a genuine N+1 and must still fire even through the `?? []` fallback.
        $code = <<<'TWIG'
        {% set nodes = craft.navigation.nodes().handle('main').with(['children']).all() ?? [] %}
        {% for node in nodes %}
          {% for child in node.children %}
            {{ child.children | length }}
          {% endfor %}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_still_flags_nested_relation_when_source_is_visible_query(): void
    {
        // The outer collection comes from a query we can see, so a nested
        // relation that is not eager-loaded is a genuine N+1 and must still fire.
        $code = <<<'TWIG'
        {% for entry in craft.entries.all() %}
          {% for related in entry.relatedEntries %}
            {{ related.author.fullName }}
          {% endfor %}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_first_segment_of_nested_eager_path(): void
    {
        // `with(['children.children'])` eager-loads the top-level `children`
        // relation (and its `children`), so accessing `node.children` on the
        // loop items is served from the eager-load cache — not an N+1.
        $code = <<<'TWIG'
        {% set nodes = craft.entries.with(['children.children']).all() %}
        {% for node in nodes %}
          {{ node.children | length }}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_does_not_flag_nested_relations_covered_by_deep_eager_path(): void
    {
        // The navigation menu pattern: a single deep eager-load path covers
        // every level, so `children` accessed at each nesting depth is served
        // from the eager-load cache. None of these are N+1s.
        $code = <<<'TWIG'
        {% set nodes = craft.navigation.nodes('main').with(['children.children.children']).all() %}
        {% for node in nodes %}
          {% set childNodes = node.children ?? [] %}
          {% for subNode in childNodes %}
            {% set childNodes = subNode.children ?? [] %}
            {% for innerNode in childNodes %}
              {{ innerNode.children | length }}
            {% endfor %}
          {% endfor %}
        {% endfor %}
        TWIG;

        self::assertNotContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }

    public function test_still_flags_relation_beyond_nested_eager_path_depth(): void
    {
        // `with(['children.children'])` covers two levels; the third-level
        // `subNode.children` access is a genuine N+1 and must still fire.
        $code = <<<'TWIG'
        {% set nodes = craft.entries.with(['children.children']).all() %}
        {% for node in nodes %}
          {% for child in node.children %}
            {% for subNode in child.children %}
              {{ subNode.children | length }}
            {% endfor %}
          {% endfor %}
        {% endfor %}
        TWIG;

        self::assertContains('craftcms.twigNPlusOne', $this->identifiers($this->analyze($code)));
    }
}
