<?php

namespace Amp\Ipc;

use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Promise;

use function Amp\call;

/**
 * Create IPC server.
 *
 * @param string $uri Local endpoint on which to listen for requests
 *
 * @return IpcServer
 */
function listen(string $uri): IpcServer
{
    return new IpcServer($uri);
}

/**
 * Connect to IPC server.
 *
 * @param string $uri URI
 *
 * @return Promise<ChannelledSocket>
 */
function connect(string $uri): Promise
{
    return call(static function () use ($uri) {
        if (!\file_exists($uri)) {
            throw new \RuntimeException("The endpoint does not exist!");
        }

        $type = \filetype($uri);
        if ($type !== 'fifo') {
            if ($type === 'file') {
                $uri = \file_get_contents($uri);
            } else {
                $uri = "unix://$uri";
            }
            if (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
                $message = "Could not connect to IPC socket";
                if ($error = \error_get_last()) {
                    $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                }
                throw new \RuntimeException($message);
            }
            return new ChannelledSocket($socket, $socket);
        }

        $suffix = \bin2hex(\random_bytes(10));
        $prefix = \sys_get_temp_dir()."/amp-".$suffix.".fifo";

        if (\strlen($prefix) > 0xFFFF) {
            throw new \RuntimeException('Prefix is too long!');
        }

        $sockets = [
            $prefix."2",
            $prefix."1",
        ];

        foreach ($sockets as $k => &$socket) {
            if (!\posix_mkfifo($socket, 0777)) {
                throw new \RuntimeException('Could not create FIFO client socket!');
            }

            \register_shutdown_function(static function () use ($socket): void {
                @\unlink($socket);
            });

            if (!$socket = \fopen($socket, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                throw new \RuntimeException("Could not open FIFO client socket");
            }
        }

        if (!$tempSocket = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
            throw new \RuntimeException("Could not connect to FIFO server");
        }
        \stream_set_blocking($tempSocket, false);
        \stream_set_write_buffer($tempSocket, 0);

        if (!\fwrite($tempSocket, \pack('v', \strlen($prefix)).$prefix)) {
            \fclose($tempSocket);
            $tempSocket = null;
            throw new \RuntimeException("Failure sending request to FIFO server");
        }
        \fclose($tempSocket);
        $tempSocket = null;

        return new ChannelledSocket(...$sockets);
    });
}
