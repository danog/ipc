<?php

namespace Amp\Ipc;

use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Promise;

use function Amp\call;

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
        $type = \filetype($uri);
        if ($type === 'fifo') {
            $suffix = \bin2hex(\random_bytes(10));
            $prefix = \sys_get_temp_dir()."/amp-".$suffix.".fifo";

            if (\strlen($prefix) > 0xFFFF) {
                \trigger_error("Prefix is too long!", E_USER_ERROR);
                exit(1);
            }

            $sockets = [
                $prefix."2",
                $prefix."1",
            ];

            foreach ($sockets as $k => &$socket) {
                if (!\posix_mkfifo($socket, 0777)) {
                    \trigger_error("Could not create FIFO client socket", E_USER_ERROR);
                    exit(1);
                }

                \register_shutdown_function(static function () use ($socket): void {
                    @\unlink($socket);
                });

                if (!$socket = \fopen($socket, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                    \trigger_error("Could not open FIFO client socket", E_USER_ERROR);
                    exit(1);
                }
            }

            if (!$tempSocket = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                \trigger_error("Could not connect to FIFO server", E_USER_ERROR);
                exit(1);
            }
            \stream_set_blocking($tempSocket, false);
            \stream_set_write_buffer($tempSocket, 0);

            if (!\fwrite($tempSocket, \pack('v', \strlen($prefix)).$prefix)) {
                \trigger_error("Failure sending request to FIFO server", E_USER_ERROR);
                exit(1);
            }
            \fclose($tempSocket);
            $tempSocket = null;

            return new ChannelledSocket(...$sockets);
        }
        if ($type === 'file') {
            $uri = \file_get_contents($uri);
        } else {
            $uri = "unix://$uri";
        }
        if (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
            \trigger_error("Could not connect to IPC socket", E_USER_ERROR);
            exit(1);
        }
        return new ChannelledSocket($socket, $socket);
    });
}
