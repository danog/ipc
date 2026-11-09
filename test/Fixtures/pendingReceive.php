<?php declare(strict_types=1);

use Amp\Ipc\IpcServer;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\Ipc\connect;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// A receive() nobody awaits anymore, on a socket the other end never writes to: EventLoop::run() must still return
// if the socket is unreferenced.
$server = new IpcServer('', (int) $argv[1]);
$accepted = async($server->accept(...));
$client = connect($server->getUri());
$serverSide = $accepted->await();

if ($argv[2] === 'unreference') {
    $client->unreference();
}
async($client->receive(...))->ignore();

echo 'started';

EventLoop::run();
