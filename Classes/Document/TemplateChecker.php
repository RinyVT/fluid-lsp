<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Document;

use ReflectionMethod;
use RinyVT\FluidLsp\ViewHelper\ViewHelperRegistry;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Finds unknown ViewHelpers, unknown arguments and missing required arguments,
 * with exact positions. Fluid's own parser stops at the first error and keeps no positions.
 */
final class TemplateChecker
{
    public function __construct(
        private readonly ViewHelperRegistry $registry,
    ) {
    }

    /**
     * @return list<array{start: int, end: int, message: string}> byte offsets
     */
    public function problems(string $template): array
    {
        $namespaces = $this->registry->namespacesFor($template);
        $viewHelpers = $this->registry->all($namespaces);
        // Fluid leaves CDATA alone, and Fluid 4+ skips f:comment. Blanking them keeps the offsets intact.
        $text = (string)preg_replace_callback('/<!\[CDATA\[.*?]]>|<f:comment>.*?<\/f:comment>/s', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $template);
        // Unregistered prefixes are plain XML; resolver delegates (components) are registered as class names.
        $isChecked = array_map(static fn (array $phpNamespaces): bool => $phpNamespaces !== [] && array_filter($phpNamespaces, 'class_exists') === [], $namespaces);

        $problems = [];
        preg_match_all('/<(\w+:[\w.]+)|(?<![\w:.-])(\w+:[\w.]+)\(/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $isTag = !isset($match[2]);
            [$name, $start] = $isTag ? $match[1] : $match[2];
            $end = $start + strlen($name);
            if (!($isChecked[strstr($name, ':', true)] ?? false)) {
                continue;
            }

            $className = $viewHelpers[$name] ?? null;
            if ($className === null) {
                $problems[] = ['start' => $start, 'end' => $end, 'message' => sprintf('Unknown ViewHelper "%s".', $name)];
                continue;
            }

            $definitions = $this->registry->argumentsOf($className);
            // Empty also means the ViewHelper couldn't be built, so nothing is known.
            if ($definitions === []) {
                continue;
            }
            $arguments = $isTag ? self::tagArguments($text, $end) : self::inlineArguments($text, $end + 1);
            $isStrict = (new ReflectionMethod($className, 'validateAdditionalArguments'))->getDeclaringClass()->getName() === AbstractViewHelper::class;
            foreach ($arguments as $argument => $offset) {
                if ($isStrict && !isset($definitions[$argument])) {
                    $problems[] = [
                        'start' => $offset,
                        'end' => $offset + strlen($argument),
                        'message' => sprintf('Unknown argument "%s" for %s.', $argument, $name),
                    ];
                }
            }
            foreach ($definitions as $argument => $definition) {
                if ($definition->isRequired() && !isset($arguments[$argument])) {
                    $problems[] = ['start' => $start, 'end' => $end, 'message' => sprintf('Missing required argument "%s".', $argument)];
                }
            }
        }

        return $problems;
    }

    /**
     * @return array<string, int> name => offset
     */
    private static function tagArguments(string $text, int $offset): array
    {
        // Up to the closing `>`, or the next `<` while the tag is still being typed.
        $quoted = '"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'';
        preg_match('/\G(?:[^<>"\']|' . $quoted . ')*/', $text, $body, 0, $offset);
        preg_match_all('/([\w:.-]+)\s*=\s*(?:' . $quoted . ')/', $body[0], $matches, PREG_OFFSET_CAPTURE);

        $arguments = [];
        foreach ($matches[1] as [$name, $nameOffset]) {
            $arguments[$name] = $offset + $nameOffset;
        }

        return $arguments;
    }

    /**
     * @return array<string, int> name => offset
     */
    private static function inlineArguments(string $text, int $offset): array
    {
        $arguments = [];
        $readName = static function (int $at) use ($text, &$arguments): void {
            if (preg_match('/\G\s*(\w+)\s*:/', $text, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
                $arguments[$match[1][0]] = $match[1][1];
            }
        };

        $readName($offset);
        $depth = 0;
        $quote = null;
        $backslashes = 0;
        for ($i = $offset, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\') {
                $backslashes++;
                continue;
            }
            // Nested strings escape their quotes once per level: `'{f:x(a: \'b\')}'`.
            // A quote only closes on the same quote with the same number of backslashes.
            $escaped = str_repeat('\\', $backslashes) . $char;
            $backslashes = 0;
            if ($quote !== null) {
                if ($escaped === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === '\'') {
                $quote = $escaped;
            } elseif (str_contains('({[', $char)) {
                $depth++;
            } elseif (str_contains(')}]', $char)) {
                if ($depth-- === 0) {
                    break;
                }
            } elseif ($char === ',' && $depth === 0) {
                $readName($i + 1);
            }
        }

        return $arguments;
    }
}
