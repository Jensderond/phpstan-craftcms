<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Twig\Node\ModuleNode;

/**
 * A single `.twig` file discovered by {@see TwigTemplateScanner}, exposing its
 * raw contents (for regex-based consumers) and its parsed AST (for the
 * performance analyzer). Both are read/parsed lazily and cached, so a consumer
 * that only needs one of them never pays for the other.
 */
final class ScannedTemplate
{
    private string|false|null $contents = null;

    private ?ModuleNode $module = null;

    private bool $parsed = false;

    public function __construct(
        private readonly string $path,
        private readonly TwigTemplateParser $parser,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Raw file contents, or null when the file could not be read.
     */
    public function contents(): ?string
    {
        if ($this->contents === null) {
            $this->contents = @file_get_contents($this->path);
        }

        return $this->contents === false ? null : $this->contents;
    }

    /**
     * The parsed Twig module, or null when the file could not be read or parsed.
     */
    public function module(): ?ModuleNode
    {
        if (! $this->parsed) {
            $this->parsed = true;

            $contents = $this->contents();
            if ($contents !== null) {
                $this->module = $this->parser->parseSource($contents, $this->path);
            }
        }

        return $this->module;
    }
}
