<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Reflection;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use Jensderond\PhpstanCraftcms\Helper\CustomFieldHandles;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;

class CustomFieldPropertiesExtension implements PropertiesClassReflectionExtension
{
    public function __construct(
        private readonly CustomFieldHandles $handles,
    ) {}

    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (! $this->handles->isAvailable()) {
            return false;
        }

        if (! $this->appliesTo($classReflection)) {
            return false;
        }

        return $this->handles->has($propertyName);
    }

    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return new CustomFieldPropertyReflection($classReflection);
    }

    private function appliesTo(ClassReflection $classReflection): bool
    {
        $name = $classReflection->getName();

        if ($name === Element::class || $name === ElementInterface::class || $name === ElementQueryInterface::class) {
            return true;
        }

        return $classReflection->is(Element::class)
            || $classReflection->is(ElementInterface::class)
            || $classReflection->is(ElementQueryInterface::class);
    }
}
