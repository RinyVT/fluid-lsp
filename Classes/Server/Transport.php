<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Server;

final class Transport
{
    /**
     * @return array<string, mixed>|null null once the client closed stdin
     */
    public function read(): ?array
    {
        $length = null;
        while (($line = fgets(STDIN)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($length !== null) {
                    break;
                }
                continue;
            }
            if (preg_match('/^Content-Length:\s*(\d+)$/i', $line, $matches) === 1) {
                $length = (int)$matches[1];
            }
        }
        if ($length === null) {
            return null;
        }

        $body = '';
        while (strlen($body) < $length) {
            $chunk = fread(STDIN, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $body .= $chunk;
        }

        $message = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($message) ? $message : [];
    }

    /**
     * @param array<string, mixed> $message
     */
    public function send(array $message): void
    {
        $body = json_encode(['jsonrpc' => '2.0'] + $message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite(STDOUT, 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
        fflush(STDOUT);
    }
}
