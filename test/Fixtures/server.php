<?php
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/amphp.log');
error_log('Inited IPC test!');

require 'vendor/autoload.php';

use Amp\Ipc\IpcServer;
use Amp\Ipc\Sync\ChannelledSocket;

use function Amp\async;
use function Amp\delay;

$server = new IpcServer($argv[1], (int) $argv[2]);

async(function () use ($server) {
    delay(1);
    echo $server->getUri().PHP_EOL;
});

$socket = $server->accept();

if (!$socket instanceof ChannelledSocket) {
    throw new \RuntimeException('Socket is not instance of ChannelledSocket');
}

$ping = $socket->receive();

if ($ping !== 'ping') {
    throw new \RuntimeException("Received $ping instead of ping!");
}

$socket->send('pong');
$socket->disconnect();

$server->close();

$server->accept();
