<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Helper;

final class RelationFieldRegistry
{
    /**
     * Craft field type classes whose values are element relations.
     *
     * @var list<string>
     */
    private const RELATIONAL_FIELD_TYPES = [
        'craft\\fields\\Entries',
        'craft\\fields\\Categories',
        'craft\\fields\\Assets',
        'craft\\fields\\Users',
        'craft\\fields\\Tags',
        'craft\\fields\\Matrix',
        'craft\\fields\\Addresses',
    ];

    /**
     * Native element accessors that lazily query related elements.
     *
     * @var array<string, true>
     */
    private const BUILT_IN_RELATIONS = [
        'author' => true,
        'parent' => true,
        'children' => true,
        'ancestors' => true,
        'descendants' => true,
        'siblings' => true,
        'prevSibling' => true,
        'nextSibling' => true,
        'currentRevision' => true,
    ];

    /** @var array<string, true>|null */
    private ?array $relationHandles = null;

    public function __construct(private readonly string $projectConfigPath) {}

    public function isRelation(string $handle): bool
    {
        if (isset(self::BUILT_IN_RELATIONS[$handle])) {
            return true;
        }

        return isset($this->relationalHandles()[$handle]);
    }

    /**
     * @return array<string, true>
     */
    private function relationalHandles(): array
    {
        if ($this->relationHandles !== null) {
            return $this->relationHandles;
        }

        $handles = [];
        $root = rtrim($this->projectConfigPath, '/');

        foreach (glob($root.'/fields/*.yaml') ?: [] as $file) {
            $handle = $this->relationalHandleFromFile($file);
            if ($handle !== null) {
                $handles[$handle] = true;
            }
        }

        return $this->relationHandles = $handles;
    }

    private function relationalHandleFromFile(string $file): ?string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/^type:\s*(\S+)\s*$/m', $contents, $typeMatch) !== 1) {
            return null;
        }

        $type = trim($typeMatch[1], "\"'");
        if (! in_array($type, self::RELATIONAL_FIELD_TYPES, true)) {
            return null;
        }

        if (preg_match('/^handle:\s*(\S+)\s*$/m', $contents, $handleMatch) !== 1) {
            return null;
        }

        return trim($handleMatch[1], "\"'");
    }
}
