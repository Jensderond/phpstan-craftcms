<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Helper;

class CustomFieldHandles
{
    /** @var array<string, true>|null */
    private ?array $handles = null;

    public function __construct(
        private readonly string $fieldsPath,
    ) {}

    /**
     * @return array<string, true>
     */
    public function all(): array
    {
        if ($this->handles !== null) {
            return $this->handles;
        }

        $handles = [];

        if (is_dir($this->fieldsPath)) {
            foreach (glob(rtrim($this->fieldsPath, '/').'/*.yaml') ?: [] as $file) {
                $handle = $this->extractHandle($file);
                if ($handle !== null) {
                    $handles[$handle] = true;
                }
            }
        }

        return $this->handles = $handles;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function isAvailable(): bool
    {
        return $this->all() !== [];
    }

    private function extractHandle(string $file): ?string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/^handle:\s*(\S+)\s*$/m', $contents, $matches) === 1) {
            return trim($matches[1], "\"'");
        }

        return null;
    }
}
