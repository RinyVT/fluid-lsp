<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Document;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Partials and layouts by convention: `Resources/Private/{Partials,Layouts}` of the extension that owns the template.
 * The configured root paths come from TypoScript or the controller, which the template doesn't know.
 */
final class TemplateFiles
{
    /**
     * @return array<string, string> name => path, for `<f:render partial="…">`
     */
    public static function partialsFor(string $templatePath): array
    {
        return self::in($templatePath, 'Partials');
    }

    /**
     * @return array<string, string> name => path, for `<f:layout name="…">`
     */
    public static function layoutsFor(string $templatePath): array
    {
        return self::in($templatePath, 'Layouts');
    }

    /**
     * The directory holding `Resources/Private`.
     */
    public static function extensionDirectoryOf(string $templatePath): ?string
    {
        $position = strrpos($templatePath, '/Resources/Private/');

        return $position === false ? null : substr($templatePath, 0, $position);
    }

    /**
     * @return array<string, int> name => byte offset
     */
    public static function sections(string $template): array
    {
        preg_match_all('/<f:section\s+name=["\']([^"\']+)/', $template, $matches, PREG_OFFSET_CAPTURE);

        return array_column($matches[1], 1, 0);
    }

    /**
     * @return array<string, string>
     */
    private static function in(string $templatePath, string $type): array
    {
        $extensionDirectory = self::extensionDirectoryOf($templatePath);
        $directory = $extensionDirectory . '/Resources/Private/' . $type;
        if ($extensionDirectory === null || !is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            // Fluid 5 also finds `Name.fluid.html`.
            if (preg_match('/^(.+?)(?:\.fluid)?\.html$/', substr($file->getPathname(), strlen($directory) + 1), $name) === 1) {
                $files[$name[1]] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }
}
