<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Document;

/**
 * Works out what the cursor is on from the text before it. Fluid's own parser
 * keeps no source positions, so this is a deliberately small scanner.
 */
final class CursorContext
{
    // Enough to reach back over a long multi-line tag without scanning whole templates.
    private const LOOKBEHIND = 4000;

    /**
     * @param string $partial the identifier typed so far, replaced on completion
     * @param list<string> $usedArguments
     */
    private function __construct(
        public readonly ContextKind $kind,
        public readonly string $partial,
        public readonly string $viewHelper = '',
        public readonly array $usedArguments = [],
    ) {
    }

    public static function at(string $textBeforeCursor): ?self
    {
        $text = substr($textBeforeCursor, -self::LOOKBEHIND);

        return self::tagAttribute($text)
            ?? self::inlineArgument($text)
            ?? self::name($text, '/<\/?([\w:.]*)$/', ContextKind::TagName)
            ?? self::name($text, '/(?:\{|->)\s*([\w:.]*)$/', ContextKind::InlineName);
    }

    private static function tagAttribute(string $text): ?self
    {
        if (preg_match_all('/<(\w+:[\w.]+)/', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        [$viewHelper, $offset] = end($matches[1]);
        $rest = substr($text, $offset + strlen($viewHelper));

        // Quoted values may hold `>` (as in `{x -> f:y()}`), so drop them first;
        // a quote left over means the cursor is inside a value.
        $unquoted = (string)preg_replace('/"[^"]*"|\'[^\']*\'/', '', $rest);
        if (strpbrk($unquoted, '>"\'') !== false || preg_match('/\s([\w-]*)$/', $unquoted, $partial) !== 1) {
            return null;
        }
        preg_match_all('/([\w:.-]+)\s*=/', $unquoted, $used);

        return new self(ContextKind::TagAttribute, $partial[1], $viewHelper, $used[1]);
    }

    private static function inlineArgument(string $text): ?self
    {
        if (preg_match('/(\w+:[\w.]+)\(([^()]*)$/', $text, $matches) !== 1) {
            return null;
        }
        if (preg_match('/(?:^|,)\s*(\w*)$/', $matches[2], $partial) !== 1) {
            return null;
        }
        preg_match_all('/(\w+)\s*:/', $matches[2], $used);

        return new self(ContextKind::InlineArgument, $partial[1], $matches[1], $used[1]);
    }

    private static function name(string $text, string $pattern, ContextKind $kind): ?self
    {
        return preg_match($pattern, $text, $matches) === 1 ? new self($kind, $matches[1]) : null;
    }
}
