<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Twig;

use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Permissive parser for any tag we do not model. It swallows the tag's tokens
 * up to the block end and returns an empty node, so unknown Craft/plugin tags
 * (and their matching end tags) never abort the parse.
 */
final class GenericTagTokenParser extends AbstractTokenParser
{
    public function __construct(private readonly string $tag) {}

    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();

        // Drop every token of this tag statement until its closing `%}`.
        while (! $stream->test(Token::BLOCK_END_TYPE)) {
            $stream->next();
        }
        $stream->expect(Token::BLOCK_END_TYPE);

        return new Nodes([], $token->getLine());
    }

    public function getTag(): string
    {
        return $this->tag;
    }
}
