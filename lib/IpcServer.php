<?php declare(strict_types=1);

namespace Amp\Ipc;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\DeferredFuture;
use Amp\Ipc\Sync\ChannelInitAck;
use Amp\Ipc\Sync\ChannelledSocket;
use AssertionError;
use Revolt\EventLoop;

use function Amp\async;

class IpcServer
{
    public const TYPE_AUTO = 0;
    public const TYPE_UNIX = 1 << 0;
    public const TYPE_FIFO = 1 << 1;
    public const TYPE_TCP = 1 << 2;
    /** @var resource|null */
    private $server;

    /** @var DeferredFuture<ChannelledSocket> */
    private ?DeferredFuture $acceptor = null;

    private string $watcher;

    private string $uri;

    /** UNIX socket listening with ext-sockets (Windows), accepted with socket_accept */
    private ?\Socket $listener = null;

    /** Path of the UNIX socket a Windows URI file points to */
    private ?string $socketPath = null;

    /**
     * @param string       $uri  Local endpoint on which to listen for requests
     * @param self::TYPE_* $type Server type
     */
    public function __construct(string $uri = '', int $type = self::TYPE_AUTO)
    {
        if (!$uri) {
            $suffix = \bin2hex(\random_bytes(10));
            $uri = \sys_get_temp_dir()."/amp-ipc-".$suffix.".sock";
        }
        if (\file_exists($uri)) {
            @\unlink($uri);
        }
        $this->uri = $uri;

        $isWindows = \PHP_OS_FAMILY === 'Windows';
        $isLinux = \PHP_OS_FAMILY === 'Linux';
        if ($type === self::TYPE_AUTO) {
            $type = self::TYPE_UNIX | self::TYPE_FIFO | self::TYPE_TCP;
        }
        $types = [];
        // On Windows, UNIX sockets are created with ext-sockets and have no path length limit (see listenUnixWindows)
        if ($type & self::TYPE_UNIX && ($isWindows || \strlen($uri) <= 104)) {
            $types []= self::TYPE_UNIX;
        }
        if ($type & self::TYPE_FIFO && $isLinux) {
            $types []= self::TYPE_FIFO;
        }
        if ($type & self::TYPE_TCP) {
            $types []= self::TYPE_TCP;
        }
        if (!$types && $isWindows) {
            throw new \RuntimeException("Cannot use FIFOs on windows");
        }

        $errors = [];
        foreach ($types as $type) {
            if ($type === self::TYPE_UNIX && $isWindows) {
                $error = $this->listenUnixWindows();
                if ($error === null) {
                    break;
                }
                $errors[$type] = $error;
            } elseif ($type === self::TYPE_FIFO) {
                try {
                    if (!\posix_mkfifo($uri, 0777)) {
                        $errors[$type] = "could not create the FIFO socket";
                        continue;
                    }
                } catch (\Throwable $e) {
                    $errors[$type] = "could not create the FIFO socket: $e";
                    continue;
                }
                $error = '';
                try {
                    // Open in r+w mode to prevent blocking if there is no reader
                    $this->server = \fopen($uri, 'r+');
                } catch (\Throwable $e) {
                    $error = "$e";
                }
                if ($this->server) {
                    \stream_set_blocking($this->server, false);
                    break;
                }
                $errors[$type] = "could not connect to the FIFO socket: $error";
            } else {
                $listenUri = $type === self::TYPE_TCP ? "tcp://127.0.0.1:0" : "unix://".$uri;
                $errno = -1;
                $errstr = 'no error';
                try {
                    $this->server = \stream_socket_server($listenUri, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
                } catch (\Throwable $e) {
                    $errstr = "exception: $e";
                }
                if ($this->server) {
                    if ($type === self::TYPE_TCP) {
                        try {
                            $name = \stream_socket_get_name($this->server, false);
                            $pos = \strrpos($name, ":");
                            if ($pos === false) {
                                throw new AssertionError('No port');
                            }
                            $port = \substr($name, $pos + 1);
                            if (\file_put_contents($this->uri, "tcp://127.0.0.1:".$port) === false) {
                                $errors[$type] = 'could not create URI file';
                                $this->server = null;
                            }
                        } catch (\Throwable $e) {
                            $errors[$type] = "could not create URI file: $e";
                            $this->server = null;
                        }
                        if (!$this->server) {
                            continue;
                        }
                    }
                    break;
                }
                $errors[$type] = "(errno: $errno) $errstr";
            }
        }

        if (!$this->server) {
            throw new IpcServerException($errors);
        }

        /** @psalm-suppress UnsupportedPropertyReferenceUsage */
        $acceptor = &$this->acceptor;
        $listener = $this->listener;
        $this->watcher = EventLoop::onReadable($this->server, static function (string $watcher, $server) use (&$acceptor, $type, $listener): void {
            if ($listener !== null) {
                // The listener is non-blocking: a spurious wakeup returns null
                if (!$client = acceptSocket($listener)) {
                    return;
                }
                $client = exportSocket($client);
                $channel = new ChannelledSocket(
                    new ReadableResourceStream($client),
                    new WritableResourceStream($client)
                );
            } elseif ($type === self::TYPE_FIFO) {
                $length = \unpack('v', \fread($server, 2))[1];
                if (!$length) {
                    return; // Could not accept, wrong length read
                }

                $prefix = \fread($server, $length);
                $sockets = [];

                foreach ([
                    $prefix.'1',
                    $prefix.'2',
                ] as $k => $socket) {
                    if (@\filetype($socket) !== 'fifo') {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Is not a FIFO
                    }

                    // Open in either read or write mode to send a close signal when done
                    if (!$sockets[$k] = \fopen($socket, $k ? 'w' : 'r')) {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Could not open fifo
                    }
                }
                $channel = new ChannelledSocket(
                    new ReadableResourceStream($sockets[0]),
                    new WritableResourceStream($sockets[1])
                );
                async($channel->send(...), new ChannelInitAck);
            } else {
                // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
                if (!$client = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                    return; // Accepting client failed.
                }
                $channel = new ChannelledSocket(
                    new ReadableResourceStream($client),
                    new WritableResourceStream($client)
                );
            }

            $deferred = $acceptor;
            $acceptor = null;

            \assert($deferred !== null);

            $deferred->complete($channel);

            EventLoop::disable($watcher);
        });

        EventLoop::disable($this->watcher);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(): ?ChannelledSocket
    {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->server) {
            return null; // Resolve with null when server is closed.
        }

        $this->acceptor = new DeferredFuture;
        EventLoop::enable($this->watcher);

        return $this->acceptor->getFuture()->await();
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close(): void
    {
        EventLoop::cancel($this->watcher);

        if ($this->acceptor) {
            $acceptor = $this->acceptor;
            $this->acceptor = null;
            $acceptor->complete();
        }

        if ($this->server) {
            $server = $this->server;
            $this->server = null;
            \fclose($server);
            $this->listener = null;
            @\unlink($this->uri);
            if ($this->socketPath !== null) {
                @\unlink($this->socketPath);
            }
        }
    }

    /**
     * Listens on a UNIX socket on Windows (10 1803+).
     *
     * PHP streams don't support AF_UNIX on Windows, ext-sockets does: the listening socket is exported to a stream to
     * watch it with the event loop, and accepted connections are exported to streams.
     * Clients can't recognize a socket file on Windows, so like with TCP, the URI is a file with the endpoint.
     *
     * @return string|null The error, or null on success
     */
    private function listenUnixWindows(): ?string
    {
        if (!\extension_loaded('sockets')) {
            return 'the sockets extension is not loaded';
        }
        $path = $this->uri.'.sock';
        if (\strlen($path) > 107) { // sun_path is 108 bytes long
            $path = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.'amp-ipc-'.\bin2hex(\random_bytes(10)).'.sock';
        }
        @\unlink($path);
        try {
            $socket = \socket_create(\AF_UNIX, \SOCK_STREAM, 0);
        } catch (\Throwable $e) {
            return "could not create the UNIX socket: $e";
        }
        if (!$socket) {
            return 'could not create the UNIX socket: '.\socket_strerror(\socket_last_error());
        }
        try {
            if (!\socket_bind($socket, $path)
                || !\socket_listen($socket)
                || !\socket_set_nonblock($socket)
            ) {
                $error = 'could not listen on the UNIX socket: '.\socket_strerror(\socket_last_error($socket));
            } elseif (\file_put_contents($this->uri, 'unix://'.$path) === false) {
                $error = 'could not create URI file';
            } else {
                $this->server = exportSocket($socket);
                $this->listener = $socket;
                $this->socketPath = $path;
                return null;
            }
        } catch (\Throwable $e) {
            $error = "could not listen on the UNIX socket: $e";
        }
        \socket_close($socket);
        @\unlink($path);
        return $error;
    }

    public function isClosed(): bool
    {
        return $this->server === null;
    }

    /**
     * References the accept watcher.
     *
     * @see EventLoop::reference()
     */
    final public function reference(): void
    {
        EventLoop::reference($this->watcher);
    }

    /**
     * Unreferences the accept watcher.
     *
     * @see EventLoop::unreference()
     */
    final public function unreference(): void
    {
        EventLoop::unreference($this->watcher);
    }

    /**
     * Get endpoint to which clients should connect.
     *
     */
    public function getUri(): string
    {
        return $this->uri;
    }
}
