<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Document;

use Closure;
use DOMDocument;
use DOMElement;

/**
 * Labels from XLIFF files, for `f:translate` and `LLL:` references.
 */
final class Translations
{
    /** @var array<string, array{int, array<string, array{line: int, text: string}>}> path => [mtime, units] */
    private array $unitsByFile = [];

    /**
     * @param Closure(string): ?string $resolveFile `EXT:` path or translation domain (TYPO3 14) => absolute path
     */
    public function __construct(
        private readonly Closure $resolveFile,
    ) {
    }

    /**
     * The file `f:translate` reads a short key from: the domain, the extension, or the extension owning the template.
     */
    public function fileForKey(string $templatePath, string $extensionName, string $domain): ?string
    {
        if ($domain !== '') {
            return ($this->resolveFile)($domain);
        }
        if ($extensionName !== '') {
            // As GeneralUtility::camelCaseToLowerCaseUnderscored(): "MyExt" => "my_ext".
            $extensionKey = strtolower((string)preg_replace('/(?<=\w)([A-Z])/', '_$1', $extensionName));

            return ($this->resolveFile)('EXT:' . $extensionKey . '/Resources/Private/Language/locallang.xlf');
        }
        $extensionDirectory = TemplateFiles::extensionDirectoryOf($templatePath);

        return $extensionDirectory === null ? null : $extensionDirectory . '/Resources/Private/Language/locallang.xlf';
    }

    /**
     * @param string $reference the file part of `LLL:EXT:…/file.xlf:key` or `LLL:domain:key`
     */
    public function fileForReference(string $reference): ?string
    {
        return ($this->resolveFile)($reference);
    }

    /**
     * @return array<string, array{line: int, text: string}> id => unit, in file order
     */
    public function unitsIn(?string $file): array
    {
        $modified = $file === null ? false : @filemtime($file);
        if ($file === null || $modified === false) {
            return [];
        }
        if (($this->unitsByFile[$file][0] ?? null) === $modified) {
            return $this->unitsByFile[$file][1];
        }

        $units = [];
        $document = new DOMDocument();
        if (@$document->load($file, LIBXML_NONET) === true) {
            /** @var DOMElement $unit */
            foreach ($document->getElementsByTagName('trans-unit') as $unit) {
                $units[$unit->getAttribute('id')] = [
                    'line' => $unit->getLineNo(),
                    'text' => trim($unit->getElementsByTagName('source')->item(0)->textContent ?? ''),
                ];
            }
        }
        $this->unitsByFile[$file] = [$modified, $units];

        return $units;
    }
}
