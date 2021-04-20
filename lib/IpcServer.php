<?php

namespace Amp\Ipc;

use Amp\Deferred;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

class IpcServer
{
    /** @var resource|null */
    private $server;

    /** @var Deferred */
    private $acceptor;

    /** @var string|null */
    private $watcher;

    /** @var string|null */
    private $uri;

    /**
     * @param string  $uri     Local endpoint on which to listen for requests
     * @param boolean $useFIFO Whether to use FIFOs instead of the more reliable UNIX socket server (CHOSEN AUTOMATICALLY, only for testing purposes)
     */
    public function __construct(string $uri = '', bool $useFIFO = false)
    {
        if (!$uri) {
            $suffix = \bin2hex(\random_bytes(10));
            $uri = \sys_get_temp_dir()."/amp-ipc-".$suffix.".sock";
        }
        if (\file_exists($uri)) {
            @\unlink($uri);
        }
        $this->uri = $uri;


        $isWindows = \strncasecmp(\PHP_OS, "WIN", 3) === 0;

        if ($isWindows) {
            if ($useFIFO) {
                throw new \RuntimeException("Cannot use FIFOs on windows");
            }
            $listenUri = "tcp://127.0.0.1:0";
        } else {
            $listenUri = "unix://".$uri;
        }

        if (!$useFIFO) {
            try {
                $this->server = \stream_socket_server($listenUri, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
            } catch (\Throwable $e) {
            }
        }

        $fifo = false;
        if (!$this->server) {
            if ($isWindows) {
                throw new \RuntimeException(\sprintf("Could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            if (!\posix_mkfifo($uri, 0777)) {
                throw new \RuntimeException(\sprintf("Could not create the FIFO socket, and could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            if (!$this->server = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                throw new \RuntimeException(\sprintf("Could not connect to the FIFO socket, and could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            \stream_set_blocking($this->server, false);
            $fifo = true;
        }

        if ($isWindows) {
            $name = \stream_socket_get_name($this->server, false);
            $port = \substr($name, \strrpos($name, ":") + 1);
            \file_put_contents($this->uri, "tcp://127.0.0.1:".$port);
        }

        $acceptor = &$this->acceptor;
        $this->watcher = Loop::onReadable($this->server, static function (string $watcher, $server) use (&$acceptor, $fifo): void {
            if ($fifo) {
                $length = \unpack('v', \fread($server, 2))[1];
                if (!$length) {
                    return; // Could not accept, wrong length read
                }

                $prefix = \fread($server, $length);
                $sockets = [
                    $prefix.'1',
                    $prefix.'2',
                ];

                foreach ($sockets as $k => &$socket) {
                    if (@\filetype($socket) !== 'fifo') {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Is not a FIFO
                    }

                    // Open in either read or write mode to send a close signal when done
                    if (!$socket = \fopen($socket, $k ? 'w' : 'r')) {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Could not open fifo
                    }
                }
                $channel = new ChannelledSocket(...$sockets);
            } else {
                // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
                if (!$client = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                    return; // Accepting client failed.
                }
                $channel = new ChannelledSocket($client, $client);
            }

            $deferred = $acceptor;
            $acceptor = null;

            \assert($deferred !== null);

            $deferred->resolve($channel);

            if (!$acceptor) {
                Loop::disable($watcher);
            }
        });

        Loop::disable($this->watcher);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return Promise<ChannelledSocket|null>
     *
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(): Promise
    {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->server) {
            return new Success(); // Resolve with null when server is closed.
        }

        $this->acceptor = new Deferred;
        Loop::enable($this->watcher);

        return $this->acceptor->promise();
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close(): void
    {
        Loop::cancel($this->watcher);

        if ($this->acceptor) {
            $acceptor = $this->acceptor;
            $this->acceptor = null;
            $acceptor->resolve();
        }

        if ($this->server) {
            \fclose($this->server);
            $this->server = null;
        }
        if ($this->uri !== null) {
            @\unlink($this->uri);
            $this->uri = null;
        }
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->server === null;
    }

    /**
     * References the accept watcher.
     *
     * @see Loop::reference()
     */
    final public function reference(): void
    {
        Loop::reference($this->watcher);
    }

    /**
     * Unreferences the accept watcher.
     *
     * @see Loop::unreference()
     */
    final public function unreference(): void
    {
        Loop::unreference($this->watcher);
    }

    /**
     * Get endpoint to which clients should connect.
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }
}
