<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\Document;

use PHPUnit\Framework\TestCase;
use RinyVT\FluidLsp\Document\TemplateFiles;

final class TemplateFilesTest extends TestCase
{
    private const PRIVATE = __DIR__ . '/../../Fixtures/my_ext/Resources/Private';

    public function testFindsPartialsOfTheOwningExtension(): void
    {
        self::assertSame(
            ['Card' => self::PRIVATE . '/Partials/Card.html', 'Form/Field' => self::PRIVATE . '/Partials/Form/Field.fluid.html'],
            TemplateFiles::partialsFor(self::PRIVATE . '/Templates/Page.html'),
        );
    }

    public function testFindsLayouts(): void
    {
        self::assertSame(['Default' => self::PRIVATE . '/Layouts/Default.html'], TemplateFiles::layoutsFor(self::PRIVATE . '/Templates/Page.html'));
    }

    public function testFindsNothingOutsideAnExtension(): void
    {
        self::assertSame([], TemplateFiles::partialsFor('/tmp/Page.html'));
    }

    public function testFindsSections(): void
    {
        $template = (string)file_get_contents(self::PRIVATE . '/Partials/Card.html');

        self::assertSame(['Header', 'Body'], array_keys(TemplateFiles::sections($template)));
        self::assertStringStartsWith('Body"', substr($template, TemplateFiles::sections($template)['Body']));
    }
}
