<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\ViewHelper;

use PHPUnit\Framework\TestCase;
use RinyVT\FluidLsp\ViewHelper\ViewHelperRegistry;

final class ViewHelperRegistryTest extends TestCase
{
    public function testReadsNamespacesDeclaredInTemplate(): void
    {
        $template = <<<'HTML'
            {namespace my=Vendor\My\ViewHelpers}
            <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
                  xmlns="http://www.w3.org/1999/xhtml"
                  data-namespace-typo3-fluid="true">
            HTML;

        self::assertSame(
            ['f' => 'TYPO3\CMS\Fluid\ViewHelpers', 'my' => 'Vendor\My\ViewHelpers'],
            ViewHelperRegistry::declaredNamespaces($template),
        );
    }
}
