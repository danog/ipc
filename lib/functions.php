<?php

namespace Amp\Ipc;

use Amp\Ipc\Signaling\Init;
use Amp\Ipc\Signaling\InitAck;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Promise;

use function Amp\call;

/**
 * Create IPC server.
 *
 * @param string $uri Local endpoint on which to listen for requests
 * @param string $pwd Optional authentication password
 *
 * @return IpcServer
 */
function listen(string $uri, string $pwd = ''): IpcServer
{
    return new IpcServer($uri, $pwd);
}
/**
 * Connect to IPC server.
 *
 * @param string $uri URI
 * @param string $pwd Optional authentication password
 *
 * @return Promise<ChannelledSocket>
 */
function connect(string $uri, string $pwd = ''): Promise
{
    return connectInternal($uri, $pwd, Init::MAIN);
}
/**
 * Connect to IPC server (internal function, don't use).
 *
 * @param string $uri  URI
 * @param string $pwd  Optional authentication password
 * @param int    $type Socket type
 *
 * @internal Internal method
 *
 * @return Promise<ChannelledSocket>
 */
function connectInternal(string $uri, string $pwd, int $type): Promise
{
    return call(static function () use ($uri, $pwd, $type) {
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
            throw new \RuntimeException("Failure sending request to FIFO server");
        }
        \fclose($tempSocket);
        $tempSocket = null;

        $channel = new ChannelledSocket(...$sockets);
        yield $channel->send(new Init($pwd, $type, IpcServer::VERSION));
        $ack = yield $channel->receive();
        if (!$ack instanceof InitAck) {
            throw new \RuntimeException("Received invalid init ACK!");
        }
        if ($ack->getStatus() === InitAck::STATUS_OK) {
            return $channel;
        }
        yield $channel->disconnect();
        $status = $ack->getStatus();
        if ($status === InitAck::STATUS_WRONG_PASSWORD) {
            throw new \RuntimeException("Wrong IPC server password!");
        } elseif ($status === InitAck::STATUS_WRONG_VERSION) {
            throw new \RuntimeException("Wrong IPC server version, please upgrade client or server!");
        }
        throw new \RuntimeException("Invalid IPC InitAck status: $status");
    });
}
