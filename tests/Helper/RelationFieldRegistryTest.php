<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Helper;

use Jensderond\PhpstanCraftcms\Helper\RelationFieldRegistry;
use PHPUnit\Framework\TestCase;

final class RelationFieldRegistryTest extends TestCase
{
    private function registry(): RelationFieldRegistry
    {
        return new RelationFieldRegistry(__DIR__.'/../fixtures/projectConfig');
    }

    public function test_relational_custom_fields_are_detected(): void
    {
        self::assertTrue($this->registry()->isRelation('relatedEntries'));
        self::assertTrue($this->registry()->isRelation('heroImage'));
    }

    public function test_non_relational_fields_are_not_detected(): void
    {
        self::assertFalse($this->registry()->isRelation('summary'));
        self::assertFalse($this->registry()->isRelation('title')); // native, non-relational
    }

    public function test_built_in_relation_accessors_are_detected(): void
    {
        self::assertTrue($this->registry()->isRelation('author'));
        self::assertTrue($this->registry()->isRelation('children'));
        self::assertTrue($this->registry()->isRelation('parent'));
    }

    public function test_falls_back_to_built_ins_when_config_missing(): void
    {
        $registry = new RelationFieldRegistry('/does/not/exist');
        self::assertTrue($registry->isRelation('author'));
        self::assertFalse($registry->isRelation('relatedEntries'));
    }
}
