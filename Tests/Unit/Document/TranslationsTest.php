<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\Document;

use PHPUnit\Framework\TestCase;
use RinyVT\FluidLsp\Document\Translations;

final class TranslationsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures';
    private const TEMPLATE = self::FIXTURES . '/my_ext/Resources/Private/Templates/Page.html';

    private Translations $translations;

    protected function setUp(): void
    {
        $this->translations = new Translations(static function (string $reference): ?string {
            $path = match ($reference) {
                'other_ext.messages' => 'EXT:other_ext/Resources/Private/Language/locallang.xlf',
                default => $reference,
            };

            return str_starts_with($path, 'EXT:') ? self::FIXTURES . '/' . substr($path, 4) : null;
        });
    }

    public function testReadsUnitsOfTheOwningExtension(): void
    {
        self::assertSame(
            ['card.title' => ['line' => 5, 'text' => 'Card title'], 'card.more' => ['line' => 8, 'text' => 'Read more']],
            $this->translations->unitsIn($this->translations->fileForKey(self::TEMPLATE, '', '')),
        );
    }

    public function testResolvesExtensionNameAndDomain(): void
    {
        $expected = self::FIXTURES . '/other_ext/Resources/Private/Language/locallang.xlf';

        self::assertSame($expected, $this->translations->fileForKey(self::TEMPLATE, 'OtherExt', ''));
        self::assertSame($expected, $this->translations->fileForKey(self::TEMPLATE, 'other_ext', ''));
        self::assertSame($expected, $this->translations->fileForKey(self::TEMPLATE, 'MyExt', 'other_ext.messages'));
    }

    public function testReturnsNothingForMissingFiles(): void
    {
        self::assertSame([], $this->translations->unitsIn($this->translations->fileForReference('EXT:nope/locallang.xlf')));
        self::assertSame([], $this->translations->unitsIn(null));
    }
}
