<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Helper;

class CustomFieldHandles
{
    /** @var array<string, true>|null */
    private ?array $handles = null;

    public function __construct(
        private readonly string $projectConfigPath,
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

        if (! is_dir($this->projectConfigPath)) {
            return $this->handles = $handles;
        }

        $root = rtrim($this->projectConfigPath, '/');

        foreach (glob($root.'/fields/*.yaml') ?: [] as $file) {
            $handle = $this->extractTopLevelHandle($file);
            if ($handle !== null) {
                $handles[$handle] = true;
            }
        }

        foreach (
            array_merge(
                glob($root.'/entryTypes/*.yaml') ?: [],
                glob($root.'/sections/*.yaml') ?: [],
                glob($root.'/volumes/*.yaml') ?: [],
                glob($root.'/users.yaml') ? [$root.'/users.yaml'] : [],
            ) as $file
        ) {
            foreach ($this->extractFieldLayoutHandles($file) as $handle) {
                $handles[$handle] = true;
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

    private function extractTopLevelHandle(string $file): ?string
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

    /**
     * Pulls handle overrides defined inside field layout entries. Each entry has a
     * `fieldUid:` reference to a global field followed by a `handle:` line giving
     * it a layout-local handle. We deliberately ignore top-level `handle:` keys
     * (entry type / section / volume handles) — only field handles are of interest.
     *
     * @return list<string>
     */
    private function extractFieldLayoutHandles(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $handles = [];

        if (preg_match_all('/^\s+fieldUid:[^\n]*\n\s+handle:\s*(\S+)\s*$/m', $contents, $matches) > 0) {
            foreach ($matches[1] as $value) {
                $value = trim($value, "\"'");
                if ($value !== '' && $value !== 'null' && $value !== '~') {
                    $handles[] = $value;
                }
            }
        }

        return $handles;
    }
}
