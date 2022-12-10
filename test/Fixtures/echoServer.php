<?php
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/amphp.log');
error_log('Inited IPC test!');

use Amp\Ipc\IpcServer;
use Amp\Ipc\Sync\ChannelledSocket;

use function Amp\async;
use function Amp\delay;

require 'vendor/autoload.php';

$server = new IpcServer($argv[1], (int) $argv[2]);

$socket = async($server->accept(...));

delay(0.001);
echo $server->getUri().PHP_EOL;

$socket = $socket->await();

if (!$socket instanceof ChannelledSocket) {
    throw new \RuntimeException('Socket is not instance of ChannelledSocket');
}

while ($socket->receive());
$socket->disconnect();

$server->close();

$server->accept();
