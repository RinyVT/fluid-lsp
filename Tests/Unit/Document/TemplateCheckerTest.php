<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\Document;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RinyVT\FluidLsp\Document\TemplateChecker;
use RinyVT\FluidLsp\ViewHelper\ViewHelperRegistry;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;

final class TemplateCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function templates(): iterable
    {
        yield 'valid tag and inline' => ['<f:for each="{items}" as="item">{f:count(subject: item)}</f:for>', []];
        yield 'unknown ViewHelper' => ['<f:nope>x</f:nope>', [['f:nope', 'Unknown ViewHelper "f:nope".']]];
        yield 'unknown tag argument' => ['<f:for each="{x}" as="y" bogus="1" />', [['bogus', 'Unknown argument "bogus" for f:for.']]];
        yield 'missing required argument' => ['<f:for as="y" />', [['f:for', 'Missing required argument "each".']]];
        yield 'unknown inline argument' => ['{f:count(subjekt: x)}', [['subjekt', 'Unknown argument "subjekt" for f:count.']]];
        yield 'nested inline arguments stay with their ViewHelper' => [
            '{f:if(condition: \'{f:count(subject: a)}\', then: {a: 1, b: f:count(subject: c)})}',
            [],
        ];
        yield 'escaped quotes' => [
            '<f:alias map="{a: \'{f:count(subject: \\\'x,y\\\')}\', b: \'it\\\'s\'}">{f:count(subject: \'it\\\'s\', bogus: 1)}</f:alias>',
            [['bogus', 'Unknown argument "bogus" for f:count.']],
        ];
        yield 'tag value holding `>`' => ['<f:if condition="{x -> f:count()}" then="a" else="b" />', []];
        yield 'unregistered prefix' => ['<svg:path d="x" />{x:y(z: 1)}', []];
        yield 'CDATA' => ['<![CDATA[<f:nope />]]>', []];
        yield 'f:comment' => ["<f:comment>\n<f:nope bogus=\"1\" />{f:nope2(x: 1)}<f:for as=\"x\">\n</f:comment><f:nope />", [['f:nope', 'Unknown ViewHelper "f:nope".']]];
    }

    /**
     * @param list<array{string, string}> $expected text at the range, message
     */
    #[DataProvider('templates')]
    public function testReportsProblems(string $template, array $expected): void
    {
        $classLoader = ClassLoader::getRegisteredLoaders()[dirname(__DIR__, 3) . '/vendor'];
        $checker = new TemplateChecker(new ViewHelperRegistry($classLoader, new ViewHelperResolver()));

        $actual = array_map(
            static fn (array $problem): array => [substr($template, $problem['start'], $problem['end'] - $problem['start']), $problem['message']],
            $checker->problems($template),
        );

        self::assertSame($expected, $actual);
    }
}
