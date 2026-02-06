<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Reflection;

use craft\elements\db\EagerLoadPlan;
use PHPStan\Type\ArrayType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;

class EagerLoadElements extends UnionMethodTypeNodeResolver
{
    public function getQualifyingName(): string
    {
        return EagerLoadPlan::class;
    }

    public function getNodeCount(): int
    {
        return 3;
    }

    public function resolveTypes(array $types): array
    {
        return [
            new ArrayType(
                new IntegerType,
                new ObjectType(EagerLoadPlan::class)
            ),
            new ArrayType(
                new IntegerType,
                new StringType,
            ),
        ];
    }
}
