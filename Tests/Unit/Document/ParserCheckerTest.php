<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RinyVT\FluidLsp\Document\ParserChecker;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;

final class ParserCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, array{string, string}|null}>
     */
    public static function templates(): iterable
    {
        yield 'valid' => ['<f:if condition="1">x</f:if>', true, null];
        yield 'badly nested' => [
            "<f:if condition=\"1\">\n  <f:for each=\"{a}\" as=\"b\">\n    </f:if>\n</f:for>",
            true,
            ['</f:if>', 'Templating tags not properly nested.'],
        ];
        yield 'closed but never opened' => ["a\n\tb </f:if>", true, ['b </f:if>', 'You closed a templating tag which you never opened!']];
        yield 'unknown namespace' => ["x\n{foo:bar()}", true, ['{foo:bar()}', 'Unknown Namespace: foo']];
        yield 'ViewHelper error' => ["\n<f:nope />", true, ['<f:nope />', 'The ViewHelper "<f:nope>" could not be resolved.']];
        yield 'ViewHelper error left to TemplateChecker' => ["\n<f:nope />", false, null];
    }

    /**
     * @param array{string, string}|null $expected text at the range, start of the message
     */
    #[DataProvider('templates')]
    public function testReportsFirstError(string $template, bool $isViewHelperErrorIncluded, ?array $expected): void
    {
        $problem = (new ParserChecker(static fn () => new RenderingContext()))->problem($template, $isViewHelperErrorIncluded);

        if ($expected === null) {
            self::assertNull($problem);
            return;
        }
        self::assertNotNull($problem);
        self::assertSame($expected[0], substr($template, $problem['start'], $problem['end'] - $problem['start']));
        self::assertStringStartsWith($expected[1], $problem['message']);
    }
}
