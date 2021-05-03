<?php

namespace Amp\Ipc\Test;

use Amp\Ipc\IpcServer;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Parallel\Context\Process;
use Amp\PHPUnit\AsyncTestCase;

use function Amp\asyncCall;
use function Amp\Ipc\connect;

class IpcTest extends AsyncTestCase
{
    /** @dataProvider provideUriType */
    public function testBasicIPC(string $uri, int $type)
    {
        $process = new Process([__DIR__.'/Fixtures/server.php', $uri, $type]);
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

    /** @dataProvider provideUriType */
    public function testIPCDisconectWhileReading(string $uri, int $type)
    {
        $process = new Process([__DIR__.'/Fixtures/echoServer.php', $uri, $type]);
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

    public function provideUriType(): \Generator
    {
        foreach (['', \sys_get_temp_dir().'/pony', \sys_get_temp_dir().'/'.\str_repeat('a', 200)] as $uri) {
            if (\strncasecmp(\PHP_OS, "WIN", 3) === 0) {
                yield [$uri, IpcServer::TYPE_AUTO];
                yield [$uri, IpcServer::TYPE_TCP];
            } else {
                yield [$uri, IpcServer::TYPE_AUTO];
                yield [$uri, IpcServer::TYPE_TCP];
                if (\strlen($uri) < 200) {
                    yield [$uri, IpcServer::TYPE_UNIX];
                }
                yield [$uri, IpcServer::TYPE_FIFO];
            }
        }
    }
}
