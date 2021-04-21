<?php
\error_reporting(E_ALL);
\ini_set('log_errors', 1);
\ini_set('error_log', '/tmp/amphp.log');
\error_log('Inited IPC test!');

use Amp\Ipc\IpcServer;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Parallel\Sync\Channel;

use function Amp\delay;

return function (Channel $channel) use ($argv) {
    $server = new IpcServer($argv[1], (int) $argv[2]);

    yield $channel->send($server->getUri());

    $socket = yield $server->accept();

    if (!$socket instanceof ChannelledSocket) {
        throw new \RuntimeException('Socket is not instance of ChannelledSocket');
    }

    while (yield $socket->receive());
    yield $socket->disconnect();

    $server->close();

    return $server->accept();
};
