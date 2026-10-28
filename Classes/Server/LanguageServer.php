<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Server;

use ReflectionClass;
use RinyVT\FluidLsp\Exception\MethodNotFound;
use RinyVT\FluidLsp\Document\ContextKind;
use RinyVT\FluidLsp\Document\CursorContext;
use RinyVT\FluidLsp\Document\ParserChecker;
use RinyVT\FluidLsp\Document\TemplateChecker;
use RinyVT\FluidLsp\ViewHelper\ViewHelperRegistry;
use Throwable;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final class LanguageServer
{
    private const COMPLETION_KIND_FUNCTION = 3;
    private const COMPLETION_KIND_PROPERTY = 10;
    private const INSERT_FORMAT_SNIPPET = 2;
    private const SEVERITY_ERROR = 1;
    private const METHOD_NOT_FOUND = -32601;
    private const INTERNAL_ERROR = -32603;

    /** @var array<string, string> uri => text */
    private array $documents = [];

    private bool $hasUtf8Positions = false;

    private bool $isShutdown = false;

    public function __construct(
        private readonly Transport $transport,
        private readonly ViewHelperRegistry $registry,
        private readonly TemplateChecker $checker,
        private readonly ParserChecker $parserChecker,
    ) {
    }

    public function run(): int
    {
        while (($message = $this->transport->read()) !== null) {
            $method = $message['method'] ?? null;
            if ($method === 'exit') {
                return $this->isShutdown ? 0 : 1;
            }
            // We never send requests, so anything without a method is noise.
            if (!is_string($method)) {
                continue;
            }

            $id = $message['id'] ?? null;
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];
            try {
                $result = $this->handle($method, $params, $id !== null);
                if ($id !== null) {
                    $this->transport->send(['id' => $id, 'result' => $result]);
                }
            } catch (Throwable $exception) {
                if ($id === null) {
                    fwrite(STDERR, $exception . "\n");
                    continue;
                }
                $code = $exception instanceof MethodNotFound ? self::METHOD_NOT_FOUND : self::INTERNAL_ERROR;
                $this->transport->send(['id' => $id, 'error' => ['code' => $code, 'message' => $exception->getMessage()]]);
            }
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $params
     * @throws MethodNotFound
     */
    private function handle(string $method, array $params, bool $isRequest): mixed
    {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params);
            case 'shutdown':
                $this->isShutdown = true;
                return null;
            case 'textDocument/didOpen':
                $this->documents[$params['textDocument']['uri']] = $params['textDocument']['text'];
                $this->publishDiagnostics($params['textDocument']['uri']);
                return null;
            case 'textDocument/didChange':
                // Full sync: the last change holds the whole text.
                $this->documents[$params['textDocument']['uri']] = end($params['contentChanges'])['text'];
                $this->publishDiagnostics($params['textDocument']['uri']);
                return null;
            case 'textDocument/didClose':
                unset($this->documents[$params['textDocument']['uri']]);
                $this->publishDiagnostics($params['textDocument']['uri']);
                return null;
            case 'textDocument/completion':
                return $this->completion($params);
            case 'completionItem/resolve':
                return $this->resolveCompletion($params);
            case 'textDocument/hover':
                return $this->hover($params);
            case 'textDocument/definition':
                return $this->definition($params);
        }
        if ($isRequest) {
            throw new MethodNotFound($method);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $this->hasUtf8Positions = in_array('utf-8', $params['capabilities']['general']['positionEncodings'] ?? [], true);

        return [
            'capabilities' => [
                'positionEncoding' => $this->hasUtf8Positions ? 'utf-8' : 'utf-16',
                'textDocumentSync' => 1,
                'completionProvider' => [
                    'triggerCharacters' => ['<', ':', '.', ' ', '(', ','],
                    'resolveProvider' => true,
                ],
                'hoverProvider' => true,
                'definitionProvider' => true,
            ],
            'serverInfo' => ['name' => 'fluid-lsp'],
        ];
    }

    private function publishDiagnostics(string $uri): void
    {
        $text = $this->documents[$uri] ?? '';
        $problems = $this->checker->problems($text);
        // Fluid stops at the first ViewHelper problem, and TemplateChecker already reports those with exact ranges.
        $problems[] = $this->parserChecker->problem($text, $problems === []);
        $diagnostics = [];
        foreach (array_filter($problems) as $problem) {
            $diagnostics[] = [
                'range' => ['start' => $this->position($text, $problem['start']), 'end' => $this->position($text, $problem['end'])],
                'severity' => self::SEVERITY_ERROR,
                'source' => 'fluid-lsp',
                'message' => $problem['message'],
            ];
        }
        $this->transport->send([
            'method' => 'textDocument/publishDiagnostics',
            'params' => ['uri' => $uri, 'diagnostics' => $diagnostics],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function completion(array $params): array
    {
        $text = $this->documents[$params['textDocument']['uri']] ?? '';
        $position = $params['position'];
        $context = CursorContext::at(substr($text, 0, $this->offset($text, $position)));
        if ($context === null) {
            return [];
        }

        // Partials are plain ASCII identifiers, so bytes equal characters in every encoding.
        $range = [
            'start' => ['line' => $position['line'], 'character' => $position['character'] - strlen($context->partial)],
            'end' => $position,
        ];
        $viewHelpers = $this->registry->all($this->registry->namespacesFor($text));

        $items = [];
        if ($context->kind === ContextKind::TagName || $context->kind === ContextKind::InlineName) {
            foreach ($viewHelpers as $name => $className) {
                $items[] = [
                    'label' => $name,
                    'kind' => self::COMPLETION_KIND_FUNCTION,
                    'detail' => $className,
                    'textEdit' => ['range' => $range, 'newText' => $name],
                    'data' => $className,
                ];
            }
            return $items;
        }

        $className = $viewHelpers[$context->viewHelper] ?? null;
        if ($className === null) {
            return [];
        }
        foreach ($this->registry->argumentsOf($className) as $name => $argument) {
            if (in_array($name, $context->usedArguments, true)) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => self::COMPLETION_KIND_PROPERTY,
                'detail' => $argument->getType() . ($argument->isRequired() ? ' (required)' : ''),
                'documentation' => ['kind' => 'markdown', 'value' => $argument->getDescription()],
                'sortText' => ($argument->isRequired() ? '0' : '1') . $name,
                'insertTextFormat' => self::INSERT_FORMAT_SNIPPET,
                'textEdit' => [
                    'range' => $range,
                    'newText' => $context->kind === ContextKind::TagAttribute ? $name . '="$1"' : $name . ': $1',
                ],
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resolveCompletion(array $item): array
    {
        if (is_string($item['data'] ?? null) && class_exists($item['data'])) {
            $item['documentation'] = ['kind' => 'markdown', 'value' => ViewHelperRegistry::descriptionOf($item['data'])];
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function hover(array $params): ?array
    {
        $target = $this->targetAt($params);
        if ($target === null) {
            return null;
        }
        [$name, $className, $argument] = $target;

        if ($argument === null) {
            $value = sprintf("**%s** `%s`\n\n%s", $name, $className, ViewHelperRegistry::descriptionOf($className));
        } else {
            $value = sprintf(
                "**%s** `%s`%s\n\n%s",
                $argument->getName(),
                $argument->getType(),
                $argument->isRequired() ? ' (required)' : '',
                $argument->getDescription(),
            );
        }

        return ['contents' => ['kind' => 'markdown', 'value' => $value]];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function definition(array $params): ?array
    {
        $target = $this->targetAt($params);
        if ($target === null) {
            return null;
        }
        $class = new ReflectionClass($target[1]);
        $line = max(0, (int)$class->getStartLine() - 1);

        return [
            'uri' => 'file://' . str_replace('%2F', '/', rawurlencode((string)$class->getFileName())),
            'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 0]],
        ];
    }

    /**
     * The ViewHelper, or ViewHelper argument, under the cursor.
     *
     * @param array<string, mixed> $params
     * @return array{string, class-string, ArgumentDefinition|null}|null
     */
    private function targetAt(array $params): ?array
    {
        $text = $this->documents[$params['textDocument']['uri']] ?? '';
        $offset = $this->offset($text, $params['position']);

        $start = $offset;
        while ($start > 0 && preg_match('/[\w:.-]/', $text[$start - 1]) === 1) {
            $start--;
        }
        $end = $offset;
        while ($end < strlen($text) && preg_match('/[\w:.-]/', $text[$end]) === 1) {
            $end++;
        }
        // Inline arguments are written `name: value`, so the colon is not part of the name.
        $token = rtrim(substr($text, $start, $end - $start), ':.');
        if ($token === '') {
            return null;
        }

        $viewHelpers = $this->registry->all($this->registry->namespacesFor($text));
        if (isset($viewHelpers[$token])) {
            return [$token, $viewHelpers[$token], null];
        }

        $context = CursorContext::at(substr($text, 0, $start) . $token);
        $className = $viewHelpers[$context->viewHelper ?? ''] ?? null;
        if ($className === null || $context->partial !== $token) {
            return null;
        }
        $argument = $this->registry->argumentsOf($className)[$token] ?? null;

        return $argument === null ? null : [$context->viewHelper, $className, $argument];
    }

    /**
     * @param array{line: int, character: int} $position
     */
    private function offset(string $text, array $position): int
    {
        $lineStart = 0;
        for ($line = 0; $line < $position['line']; $line++) {
            $next = strpos($text, "\n", $lineStart);
            if ($next === false) {
                return strlen($text);
            }
            $lineStart = $next + 1;
        }
        if ($this->hasUtf8Positions) {
            return min($lineStart + $position['character'], strlen($text));
        }

        // Counts code points, not UTF-16 units: positions drift by one per emoji
        // earlier on the line. Clients that offer utf-8 (Neovim does) never get here.
        $lineEnd = strpos($text, "\n", $lineStart);
        $lineText = substr($text, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart);

        return $lineStart + strlen(mb_substr($lineText, 0, $position['character']));
    }

    /**
     * The reverse of offset(), with the same UTF-16 drift.
     *
     * @return array{line: int, character: int}
     */
    private function position(string $text, int $offset): array
    {
        $before = substr($text, 0, $offset);
        $lineStart = strrpos($before, "\n");
        $lineText = $lineStart === false ? $before : substr($before, $lineStart + 1);

        return [
            'line' => substr_count($before, "\n"),
            'character' => $this->hasUtf8Positions ? strlen($lineText) : mb_strlen($lineText),
        ];
    }
}
