<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Rule;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
use Jensderond\PhpstanCraftcms\Rule\TwigPerformanceRule;
use Jensderond\PhpstanCraftcms\Twig\TwigPerformanceAnalyzer;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateParser;
use Jensderond\PhpstanCraftcms\Twig\TwigTemplateScanner;
use PHPUnit\Framework\TestCase;

final class TwigPerformanceRuleTest extends TestCase
{
    private function rule(bool $enabled = true): TwigPerformanceRule
    {
        $registry = new RelationFieldRegistry(__DIR__.'/../fixtures/projectConfig');
        $analyzer = new TwigPerformanceAnalyzer($registry, [
            'nPlusOne' => true,
            'nestedRelationAll' => true,
            'queryInLoop' => true,
            'lengthOnQuery' => true,
            'unboundedAll' => false,
        ]);

        $scanner = new TwigTemplateScanner(
            new TwigTemplateParser,
            [__DIR__.'/../fixtures/templates'],
            [],
        );

        return new TwigPerformanceRule($scanner, $analyzer, $enabled);
    }

    public function test_reports_n_plus_one_in_template_tree(): void
    {
        $errors = $this->rule()->scanTemplates();
        $identifiers = array_map(static fn ($e) => $e->getIdentifier(), $errors);
        self::assertContains('craftcms.twigNPlusOne', $identifiers);
    }

    public function test_no_errors_when_disabled(): void
    {
        self::assertSame([], $this->rule(enabled: false)->scanTemplates());
    }
}
