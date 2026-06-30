<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

final class Finding
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $line,
        public readonly string $message,
        public readonly ?string $tip = null,
    ) {}
}
