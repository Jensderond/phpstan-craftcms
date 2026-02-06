<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ReflectionMethod;

/**
 * Collects all public action* methods from classes extending yii\web\Controller.
 *
 * @implements Collector<InClassNode, array{string, list<string>}>
 */
final class ControllerActionCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param  InClassNode  $node
     * @return array{string, list<string>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $classReflection = $node->getClassReflection();

        if ($classReflection->isAbstract()) {
            return null;
        }

        if (! $classReflection->isSubclassOf('yii\\web\\Controller')) {
            return null;
        }

        $className = $classReflection->getName();
        $nativeReflection = $classReflection->getNativeReflection();

        /** @var list<string> $actionMethods */
        $actionMethods = [];

        foreach ($nativeReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            // Must start with "action" and be longer than just "action" or "actions"
            if (! str_starts_with($methodName, 'action')) {
                continue;
            }

            if ($methodName === 'action' || $methodName === 'actions') {
                continue;
            }

            $actionMethods[] = $methodName;
        }

        if ($actionMethods === []) {
            return null;
        }

        return [$className, $actionMethods];
    }
}
