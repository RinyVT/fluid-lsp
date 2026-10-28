<?php

declare(strict_types=1);

// Drives bin/fluid-lsp against a TYPO3 instance like an editor would.
// Usage: php Build/smoke-test.php Build/v13

$projectDirectory = $argv[1] ?? exit("Usage: php smoke-test.php <project-dir>\n");

$process = proc_open(
    [PHP_BINARY, __DIR__ . '/../bin/fluid-lsp', $projectDirectory],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
);
[$stdin, $stdout, $stderr] = $pipes;

$nextId = 1;
$diagnostics = [];
$send = static function (string $method, array $params, bool $isRequest = true) use ($stdin, $stdout, &$nextId, &$diagnostics): mixed {
    $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
    if ($isRequest) {
        $message['id'] = $nextId++;
    }
    $body = json_encode($message, JSON_THROW_ON_ERROR);
    fwrite($stdin, 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
    if (!$isRequest) {
        return null;
    }
    do {
        $length = 0;
        while (($line = fgets($stdout)) !== false && trim($line) !== '') {
            if (preg_match('/^Content-Length: (\d+)/', $line, $matches) === 1) {
                $length = (int)$matches[1];
            }
        }
        $response = json_decode(stream_get_contents($stdout, $length), true, 512, JSON_THROW_ON_ERROR);
        if (($response['method'] ?? '') === 'textDocument/publishDiagnostics') {
            $diagnostics = $response['params']['diagnostics'];
        }
    } while (!isset($response['id']));
    if (isset($response['error'])) {
        throw new RuntimeException($method . ': ' . $response['error']['message']);
    }

    return $response['result'];
};

$template = <<<'HTML'
    <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" data-namespace-typo3-fluid="true">
    <f:for each="{items}" as="item" it
    {f:format.date(da
    <f:format.date format="d.m.Y">{date}</f:format.date>
    {f:translate(key: 'x', extensionName: 'y')}
    <f:link.page pageUid="1" class="x" data-foo="y">{f:nope()}</f:link.page>
    HTML;
$uri = 'file:///tmp/Template.fluid.html';
$at = static fn (int $line, int $character): array => [
    'textDocument' => ['uri' => $uri],
    'position' => ['line' => $line, 'character' => $character],
];
$labels = static fn (array $items): array => array_column($items, 'label');
$failures = 0;
$check = static function (string $name, bool $isOk, mixed $detail = '') use (&$failures): void {
    echo ($isOk ? 'ok   ' : 'FAIL ') . $name . ($isOk ? '' : ' ' . json_encode($detail)) . "\n";
    $failures += $isOk ? 0 : 1;
};

$init = $send('initialize', ['capabilities' => ['general' => ['positionEncodings' => ['utf-8', 'utf-16']]]]);
$check('initialize uses utf-8', $init['capabilities']['positionEncoding'] === 'utf-8', $init);
$send('initialized', [], false);
$send('textDocument/didOpen', ['textDocument' => ['uri' => $uri, 'languageId' => 'fluid', 'version' => 1, 'text' => $template]], false);

$start = microtime(true);
$names = $labels($send('textDocument/completion', $at(3, 3)));
$check(sprintf('tag name completion (%d items, %.2fs)', count($names), microtime(true) - $start), in_array('f:format.date', $names, true) && in_array('f:link.page', $names, true), array_slice($names, 0, 10));

$attributes = $labels($send('textDocument/completion', $at(1, 34)));
$check('attribute completion skips used', in_array('iteration', $attributes, true) && !in_array('each', $attributes, true), $attributes);

$arguments = $labels($send('textDocument/completion', $at(2, 17)));
$check('inline argument completion', in_array('date', $arguments, true) && in_array('format', $arguments, true), $arguments);

$resolved = $send('completionItem/resolve', ['label' => 'f:format.date', 'data' => 'TYPO3\CMS\Fluid\ViewHelpers\Format\DateViewHelper']);
$check('completion resolve adds docs', str_contains($resolved['documentation']['value'] ?? '', 'date'), $resolved);

$hover = $send('textDocument/hover', $at(3, 5));
$check('hover on tag', str_contains($hover['contents']['value'] ?? '', 'DateViewHelper'), $hover);

$hover = $send('textDocument/hover', $at(3, 18));
$check('hover on attribute', str_starts_with($hover['contents']['value'] ?? '', '**format**'), $hover);

$hover = $send('textDocument/hover', $at(4, 26));
$check('hover on inline argument', str_starts_with($hover['contents']['value'] ?? '', '**extensionName**'), $hover);

$messages = array_column($diagnostics, 'message');
$check('diagnostics', $messages === ['Unknown ViewHelper "f:nope".'] && $diagnostics[0]['range']['start'] === ['line' => 5, 'character' => 49], $diagnostics);

$send('textDocument/didOpen', ['textDocument' => ['uri' => 'file:///tmp/Broken.fluid.html', 'languageId' => 'fluid', 'version' => 1, 'text' => "<f:if condition=\"1\">\n  </f:for>"]], false);
$send('textDocument/hover', $at(0, 0));
$check('parser diagnostics', str_starts_with($diagnostics[0]['message'] ?? '', 'Templating tags not properly nested') && $diagnostics[0]['range']['start'] === ['line' => 1, 'character' => 2], $diagnostics);

$pagePath = realpath(__DIR__ . '/../Tests/Fixtures/my_ext/Resources/Private/Templates/Page.html');
$pageUri = 'file://' . $pagePath;
$page = explode("\n", (string)file_get_contents($pagePath));
$send('textDocument/didOpen', ['textDocument' => ['uri' => $pageUri, 'languageId' => 'fluid', 'version' => 1, 'text' => implode("\n", $page)]], false);
$inPage = static fn (int $line, string $before): array => [
    'textDocument' => ['uri' => $pageUri],
    'position' => ['line' => $line, 'character' => strpos($page[$line], $before) + strlen($before)],
];
$check('layout completion', $labels($send('textDocument/completion', $inPage(0, 'name="'))) === ['Default']);
$check('partial completion', $labels($send('textDocument/completion', $inPage(3, 'partial="'))) === ['Card', 'Form/Field']);
$check('section completion from partial', $labels($send('textDocument/completion', $inPage(3, 'section="'))) === ['Header', 'Body']);
$location = $send('textDocument/definition', $inPage(3, 'partial="Ca'));
$check('partial definition', str_ends_with($location['uri'] ?? '', '/Partials/Card.html'), $location);
$location = $send('textDocument/definition', $inPage(3, 'section="He'));
$check('section definition', str_ends_with($location['uri'] ?? '', '/Partials/Card.html') && $location['range']['start']['line'] === 0, $location);

$check('translate key completion', $labels($send('textDocument/completion', $inPage(5, 'key="'))) === ['card.title', 'card.more']);
$hover = $send('textDocument/hover', $inPage(5, 'key="card'));
$check('translate key hover', ($hover['contents']['value'] ?? '') === 'Card title', $hover);
$location = $send('textDocument/definition', $inPage(5, 'key="card'));
$check('translate key definition', str_ends_with($location['uri'] ?? '', 'my_ext/Resources/Private/Language/locallang.xlf') && $location['range']['start']['line'] === 4, $location);
$check('LLL key completion', in_array('clearcache.title', $labels($send('textDocument/completion', $inPage(6, 'locallang.xlf:'))), true));
$hover = $send('textDocument/hover', $inPage(6, 'clearcache.ti'));
$check('LLL hover', ($hover['contents']['value'] ?? '') === 'Page cache', $hover);
$location = $send('textDocument/definition', $inPage(6, 'clearcache.ti'));
$check('LLL definition', str_ends_with($location['uri'] ?? '', 'cms-backend/Resources/Private/Language/locallang.xlf'), $location);
$changed = "<f:translate key=\"clearcache.title\" extensionName=\"Backend\" />\n{f:translate(key: 'LLL:backend.messages:clearcache.title')}";
$send('textDocument/didChange', ['textDocument' => ['uri' => $pageUri, 'version' => 2], 'contentChanges' => [['text' => $changed]]], false);
$hoverAt = static fn (int $line, int $character): mixed => $send('textDocument/hover', ['textDocument' => ['uri' => $pageUri], 'position' => ['line' => $line, 'character' => $character]]);
$hover = $hoverAt(0, 22);
$check('translate extensionName hover', ($hover['contents']['value'] ?? '') === 'Page cache', $hover);
if (is_file($projectDirectory . '/vendor/typo3/cms-core/Classes/Localization/TranslationDomainMapper.php')) {
    $hover = $hoverAt(1, 45);
    $check('LLL domain hover (v14)', ($hover['contents']['value'] ?? '') === 'Page cache', $hover);
}

$definition = $send('textDocument/definition', $at(3, 45));
$check('definition of closing tag', str_ends_with($definition['uri'] ?? '', 'Format/DateViewHelper.php') && is_file(urldecode(substr($definition['uri'], 7))), $definition);

$send('shutdown', []);
$send('exit', [], false);
fclose($stdin);
$errors = stream_get_contents($stderr);
$exitCode = proc_close($process);
$check('clean exit', $exitCode === 0, $errors);

exit($failures === 0 ? 0 : 1);
