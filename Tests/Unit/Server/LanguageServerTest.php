<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RinyVT\FluidLsp\Server\LanguageServer;

final class LanguageServerTest extends TestCase
{
    public function testPositionsCountUtf16UnitsByDefault(): void
    {
        $server = (new ReflectionClass(LanguageServer::class))->newInstanceWithoutConstructor();
        $call = static fn (string $method, mixed ...$arguments): mixed => (new ReflectionMethod($server, $method))->invoke($server, ...$arguments);
        $text = "a\n🎉 b";

        self::assertSame(['line' => 1, 'character' => 4], $call('position', $text, strlen($text)));
        self::assertSame(strlen($text), $call('offset', $text, ['line' => 1, 'character' => 4]));
        self::assertSame(2, $call('offset', $text, ['line' => 1, 'character' => 0]));
        self::assertSame(6, $call('offset', $text, ['line' => 1, 'character' => 2]));
    }
}
