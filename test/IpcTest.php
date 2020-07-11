<?php

namespace Amp\Ipc\Test;

use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Parallel\Context\Process;
use Amp\PHPUnit\AsyncTestCase;

use function Amp\asyncCall;
use function Amp\Ipc\connect;

class IpcTest extends AsyncTestCase
{
    /** @dataProvider provideUriFifo */
    public function testBasicIPC(string $uri, bool $fifo)
    {
        $process = new Process([__DIR__.'/Fixtures/server.php', $uri, $fifo]);
        yield $process->start();

        $recvUri = yield $process->receive();
        if ($uri) {
            $this->assertEquals($uri, $recvUri);
        }

        $client = yield connect($recvUri);
        $this->assertInstanceOf(ChannelledSocket::class, $client);

        yield $client->send('ping');
        $this->assertEquals('pong', yield $client->receive());

        yield $client->disconnect();

        $this->assertNull(yield $process->join());
    }

    /** @dataProvider provideUriFifo */
    public function testIPCDisconectWhileReading(string $uri, bool $fifo)
    {
        $process = new Process([__DIR__.'/Fixtures/echoServer.php', $uri, $fifo]);
        yield $process->start();

        $recvUri = yield $process->receive();
        if ($uri) {
            $this->assertEquals($uri, $recvUri);
        }

        $client = yield connect($recvUri);
        $this->assertInstanceOf(ChannelledSocket::class, $client);

        asyncCall(
            static function () use ($client) {
                while (yield $client->receive());
            }
        );
        yield $client->disconnect();

        $this->assertNull(yield $process->join());
    }

    public function provideUriFifo(): \Generator
    {
        foreach (['', \sys_get_temp_dir().'/pony'] as $uri) {
            if (\strncasecmp(\PHP_OS, "WIN", 3) === 0) {
                yield [$uri, false];
            } else {
                yield [$uri, true];
                yield [$uri, false];
            }
        }
    }
}
