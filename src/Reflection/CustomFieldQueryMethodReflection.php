<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Reflection;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\Native\NativeParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\MixedType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Reflection for the magic query-param setter methods Craft generates on element
 * queries. When a custom field handle is called as a method on an element query
 * (e.g. `Entry::find()->removedFromSearchResults(false)`), Craft's
 * `ElementQuery::__call()` sets the corresponding query param and returns the
 * query itself for chaining.
 */
class CustomFieldQueryMethodReflection implements MethodReflection
{
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly string $name,
    ) {}

    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    public function isStatic(): bool
    {
        return false;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    public function getVariants(): array
    {
        $value = new NativeParameterReflection(
            name: 'value',
            optional: true,
            type: new MixedType,
            passedByReference: PassedByReference::createNo(),
            variadic: true,
            defaultValue: null,
        );

        return [
            new FunctionVariant(
                templateTypeMap: TemplateTypeMap::createEmpty(),
                resolvedTemplateTypeMap: null,
                parameters: [$value],
                isVariadic: true,
                returnType: $this->returnType(),
            ),
        ];
    }

    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type
    {
        return null;
    }

    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createMaybe();
    }

    private function returnType(): Type
    {
        // Craft returns the query itself for chaining.
        return new StaticType($this->declaringClass);
    }
}
