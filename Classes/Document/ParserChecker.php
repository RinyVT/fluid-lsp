<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Document;

use Closure;
use Throwable;
use TYPO3Fluid\Fluid\Core\Exception;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Runs Fluid's own parser, which catches what TemplateChecker can't: unclosed or badly nested tags,
 * unknown namespaces. It stops at the first error.
 */
final class ParserChecker
{
    private const UNKNOWN_VIEWHELPER = 1407060572;
    private const MISSING_ARGUMENT = 1237823699;

    /**
     * @param Closure(): RenderingContextInterface $createRenderingContext a fresh context per parse, because the
     *     parser registers the template's namespaces on its resolver
     */
    public function __construct(
        private readonly Closure $createRenderingContext,
    ) {
    }

    /**
     * @return array{start: int, end: int, message: string}|null byte offsets
     */
    public function problem(string $template, bool $isViewHelperErrorIncluded): ?array
    {
        $parser = ($this->createRenderingContext)()->getTemplateParser();
        try {
            $parser->parse($template);
            return null;
        } catch (Exception $exception) {
            $error = $exception->getPrevious() ?? $exception;
        } catch (Throwable $exception) {
            // Not a template error, e.g. a ViewHelper that can't be built outside a request.
            fwrite(STDERR, $exception . "\n");
            return null;
        }

        $isViewHelperError = in_array($error->getCode(), [self::UNKNOWN_VIEWHELPER, self::MISSING_ARGUMENT], true)
            || str_starts_with($error->getMessage(), 'Undeclared arguments passed');
        if ($isViewHelperError && !$isViewHelperErrorIncluded) {
            return null;
        }

        // The pointer's character is unreliable (it can lie past the line end), so mark the whole line.
        [$line] = $parser->getCurrentParsingPointers();
        $start = 0;
        for ($i = 1; $i < $line && ($next = strpos($template, "\n", $start)) !== false; $i++) {
            $start = $next + 1;
        }
        $end = strpos($template, "\n", $start);
        $end = $end === false ? strlen($template) : $end;
        $start += strspn($template, " \t", $start, $end - $start);

        return ['start' => $start, 'end' => $end, 'message' => explode("\n", $error->getMessage())[0]];
    }
}
