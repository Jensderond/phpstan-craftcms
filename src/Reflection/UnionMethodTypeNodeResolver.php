<?php
declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Reflection;

use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

abstract class UnionMethodTypeNodeResolver implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
	private TypeNodeResolver $typeNodeResolver;

	/**
	 * @return class-string
	 */
	abstract public function getQualifyingName(): string;

	public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
	{
		$this->typeNodeResolver = $typeNodeResolver;
	}

	/**
	 * @param Type[] $types
	 * @return Type[]
	 */
	abstract public function resolveTypes(array $types): array;

	/** @return int */
	abstract public function getNodeCount(): int;

	public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
	{
		if ($typeNode instanceof UnionTypeNode) {
			if (count($typeNode->types) !== $this->getNodeCount()) return null;

			$nodeTypes = array_filter($typeNode->types, function(TypeNode $unionTypeNode) use ($nameScope): bool {
				if ($unionTypeNode::class !== ArrayTypeNode::class) return false;
				$type = $this->typeNodeResolver->resolve($unionTypeNode->type, $nameScope);
				if (!$type->isObject()->yes()) return false;
				if ($type->getClassName() !== $this->getQualifyingName()) return false;

				return true;
			});

			if (empty($nodeTypes)) {
				return null;
			}

			$types = $this->typeNodeResolver->resolveMultiple($typeNode->types, $nameScope);

			return new UnionType($this->resolveTypes($types), true);
		}

		return null;
	}
}
