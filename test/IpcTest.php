<?php declare(strict_types=1);

namespace Amp\Ipc\Test;

use Amp\Ipc\IpcServer;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Process\Process;

use function Amp\async;
use function Amp\ByteStream\splitLines;
use function Amp\Ipc\connect;

class IpcTest extends AsyncTestCase
{
    /** @dataProvider provideUriType */
    public function testBasicIPC(string $uri, int $type)
    {
        $process = Process::start([PHP_BINARY, __DIR__.'/Fixtures/server.php', $uri, $type]);

        foreach (splitLines($process->getStdout()) as $recvUri) {
            break;
        }
        if ($uri) {
            $this->assertEquals($uri, $recvUri);
        }

        $client = connect($recvUri);
        $this->assertInstanceOf(ChannelledSocket::class, $client);

        $client->send('ping');
        $this->assertEquals('pong', $client->receive());

        $client->disconnect();

        $this->assertEquals(0, $process->join());
    }

    /** @dataProvider provideUriType */
    public function testIPCDisconectWhileReading(string $uri, int $type)
    {
        $process = Process::start([PHP_BINARY, __DIR__.'/Fixtures/echoServer.php', $uri, $type]);

        foreach (splitLines($process->getStdout()) as $recvUri) {
            break;
        }
        if ($uri) {
            $this->assertEquals($uri, $recvUri);
        }

        $client = connect($recvUri);
        $this->assertInstanceOf(ChannelledSocket::class, $client);

        async(
            static function () use ($client) {
                while ($client->receive());
            }
        );
        $client->disconnect();

        $this->assertEquals(0, $process->join());
    }

    public function testWindowsPrefersUnixSockets()
    {
        if (\PHP_OS_FAMILY !== 'Windows' || !\extension_loaded('sockets')) {
            $this->markTestSkipped('Requires Windows and ext-sockets');
        }
        $server = new IpcServer(\sys_get_temp_dir().'/'.\bin2hex(\random_bytes(10)));
        $this->assertStringStartsWith('unix://', \file_get_contents($server->getUri()));

        $accepted = async($server->accept(...));
        $client = connect($server->getUri());
        $socket = $accepted->await();

        $client->send('ping');
        $this->assertEquals('ping', $socket->receive());
        $socket->send('pong');
        $this->assertEquals('pong', $client->receive());

        $client->disconnect();
        $this->assertNull($socket->receive());

        $server->close();
        $this->assertFileDoesNotExist($server->getUri());
    }

    public function provideUriType(): \Generator
    {
        foreach (['', \sys_get_temp_dir().'/pony', \sys_get_temp_dir().'/'.\str_repeat('a', 200)] as $uri) {
            if (\strncasecmp(\PHP_OS, "WIN", 3) === 0) {
                yield [$uri, IpcServer::TYPE_AUTO];
                yield [$uri, IpcServer::TYPE_TCP];
                if (\extension_loaded('sockets')) {
                    yield [$uri, IpcServer::TYPE_UNIX];
                }
            } else {
                yield [$uri, IpcServer::TYPE_AUTO];
                yield [$uri, IpcServer::TYPE_TCP];
                if (\strlen($uri) < 200) {
                    yield [$uri, IpcServer::TYPE_UNIX];
                }
                if (\strncasecmp(\PHP_OS, "LINUX", 5) === 0) {
                    yield [$uri, IpcServer::TYPE_FIFO];
                }
            }
        }
    }
}
