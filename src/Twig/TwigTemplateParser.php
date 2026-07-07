<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\ModuleNode;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Parses Twig templates into an AST with a Craft-tolerant environment:
 * unknown tags, functions and filters resolve to inert nodes instead of
 * throwing, so any real Craft template yields a traversable tree.
 */
final class TwigTemplateParser
{
    private readonly Environment $twig;

    public function __construct()
    {
        $this->twig = new Environment(new ArrayLoader, [
            'cache' => false,
            'autoescape' => false,
            'strict_variables' => false,
            'optimizations' => 0,
        ]);

        // Any tag we do not model becomes an inert node.
        $this->twig->registerUndefinedTokenParserCallback(
            static fn (string $name): GenericTagTokenParser => new GenericTagTokenParser($name),
        );

        // Any unknown function/filter resolves to a no-op so parsing succeeds.
        $this->twig->registerUndefinedFunctionCallback(
            static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): string => ''),
        );
        $this->twig->registerUndefinedFilterCallback(
            static fn (string $name): TwigFilter => new TwigFilter($name, static fn ($v) => $v),
        );
    }

    public function parseFile(string $path): ?ModuleNode
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }

        return $this->parseSource($code, $path);
    }

    public function parseSource(string $code, string $name = 'template'): ?ModuleNode
    {
        try {
            return $this->twig->parse($this->twig->tokenize(new Source($code, $name)));
        } catch (Throwable) {
            return null;
        }
    }
}
