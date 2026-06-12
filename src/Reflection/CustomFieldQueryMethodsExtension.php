<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Reflection;

use craft\elements\db\ElementQueryInterface;
use Jensderond\PhpstanCraftcms\Helper\CustomFieldHandles;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

/**
 * Teaches PHPStan about the magic query-param setter methods Craft exposes on
 * element queries. Any custom field handle can be called as a method on an
 * element query to filter by that field (handled by `ElementQuery::__call()`),
 * which the analyser would otherwise report as `method.notFound`.
 *
 * Note: on elements themselves a custom field handle is a property, not a method,
 * so this extension deliberately only applies to element queries.
 * See {@see CustomFieldPropertiesExtension} for the element-side property reflection.
 */
class CustomFieldQueryMethodsExtension implements MethodsClassReflectionExtension
{
    public function __construct(
        private readonly CustomFieldHandles $handles,
    ) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (! $this->handles->isAvailable()) {
            return false;
        }

        if (! $this->appliesTo($classReflection)) {
            return false;
        }

        return $this->handles->has($methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new CustomFieldQueryMethodReflection($classReflection, $methodName);
    }

    private function appliesTo(ClassReflection $classReflection): bool
    {
        return $classReflection->getName() === ElementQueryInterface::class
            || $classReflection->is(ElementQueryInterface::class);
    }
}
