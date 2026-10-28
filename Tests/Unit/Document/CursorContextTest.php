<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RinyVT\FluidLsp\Document\ContextKind;
use RinyVT\FluidLsp\Document\CursorContext;

final class CursorContextTest extends TestCase
{
    /**
     * @return array<string, array{string, ContextKind, string, string, list<string>}>
     */
    public static function contexts(): array
    {
        return [
            'tag name' => ['<div><f:for', ContextKind::TagName, 'f:for', '', []],
            'bare tag' => ['<', ContextKind::TagName, '', '', []],
            'closing tag' => ['</f:if', ContextKind::TagName, 'f:if', '', []],
            'inline name' => ['{f:format.d', ContextKind::InlineName, 'f:format.d', '', []],
            'inline chained' => ['{date -> f:', ContextKind::InlineName, 'f:', '', []],
            'inline in attribute value' => ['<f:link.page pageUid="{f:', ContextKind::InlineName, 'f:', '', []],
            'attribute' => ['<f:format.date fo', ContextKind::TagAttribute, 'fo', 'f:format.date', []],
            'attribute after values' => [
                "<f:for each=\"{items}\"\n    as=\"item\" it",
                ContextKind::TagAttribute,
                'it',
                'f:for',
                ['each', 'as'],
            ],
            'attribute after value with arrow' => [
                '<f:if condition="{x -> f:count()} > 1" ',
                ContextKind::TagAttribute,
                '',
                'f:if',
                ['condition'],
            ],
            'inline argument' => ['{f:format.date(da', ContextKind::InlineArgument, 'da', 'f:format.date', []],
            'second inline argument' => [
                "{f:translate(key: 'a', ext",
                ContextKind::InlineArgument,
                'ext',
                'f:translate',
                ['key'],
            ],
        ];
    }

    /**
     * @param list<string> $usedArguments
     */
    #[DataProvider('contexts')]
    public function testDetectsContext(string $text, ContextKind $kind, string $partial, string $viewHelper, array $usedArguments): void
    {
        $context = CursorContext::at($text);

        self::assertNotNull($context);
        self::assertSame($kind, $context->kind);
        self::assertSame($partial, $context->partial);
        self::assertSame($viewHelper, $context->viewHelper);
        self::assertSame($usedArguments, $context->usedArguments);
    }

    /**
     * @return array<string, array{string, string, string, string, array<string, string>}>
     */
    public static function argumentValues(): array
    {
        return [
            'tag' => ['<f:render partial="Ca', 'f:render', 'partial', 'Ca', []],
            'tag after values' => ['<f:render partial="Card"\n    section=\'He', 'f:render', 'section', 'He', ['partial' => 'Card']],
            'inline' => ["{f:render(partial: 'Card', section: 'He", 'f:render', 'section', 'He', ['partial' => 'Card']],
            'inline in attribute value' => [
                '<f:if condition="{f:render(partial: \\\'Form/', 'f:render', 'partial', 'Form/', [],
            ],
            'empty' => ['<f:layout name="', 'f:layout', 'name', '', []],
        ];
    }

    /**
     * @param array<string, string> $argumentValues
     */
    #[DataProvider('argumentValues')]
    public function testDetectsArgumentValue(string $text, string $viewHelper, string $argument, string $partial, array $argumentValues): void
    {
        $context = CursorContext::at($text);

        self::assertNotNull($context);
        self::assertSame(ContextKind::ArgumentValue, $context->kind);
        self::assertSame($viewHelper, $context->viewHelper);
        self::assertSame($argument, $context->argument);
        self::assertSame($partial, $context->partial);
        self::assertSame($argumentValues, $context->argumentValues);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noContexts(): array
    {
        return [
            'plain text' => ['Hello world'],
            'after closed tag' => ['<f:format.date format="d">text '],
            'quote inside attribute value' => ['<f:if condition="{x} == \'b'],
        ];
    }

    #[DataProvider('noContexts')]
    public function testIgnoresOtherPositions(string $text): void
    {
        self::assertNull(CursorContext::at($text));
    }
}
