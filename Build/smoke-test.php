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
$send = static function (string $method, array $params, bool $isRequest = true) use ($stdin, $stdout, &$nextId): mixed {
    $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
    if ($isRequest) {
        $message['id'] = $nextId++;
    }
    $body = json_encode($message, JSON_THROW_ON_ERROR);
    fwrite($stdin, 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
    if (!$isRequest) {
        return null;
    }
    $length = 0;
    while (($line = fgets($stdout)) !== false && trim($line) !== '') {
        if (preg_match('/^Content-Length: (\d+)/', $line, $matches) === 1) {
            $length = (int)$matches[1];
        }
    }
    $response = json_decode(stream_get_contents($stdout, $length), true, 512, JSON_THROW_ON_ERROR);
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

$definition = $send('textDocument/definition', $at(3, 45));
$check('definition of closing tag', str_ends_with($definition['uri'] ?? '', 'Format/DateViewHelper.php') && is_file(urldecode(substr($definition['uri'], 7))), $definition);

$send('shutdown', []);
$send('exit', [], false);
fclose($stdin);
$errors = stream_get_contents($stderr);
$exitCode = proc_close($process);
$check('clean exit', $exitCode === 0, $errors);

exit($failures === 0 ? 0 : 1);
