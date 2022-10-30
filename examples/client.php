<?php

require 'vendor/autoload.php';

use Amp\Ipc\Sync\ChannelledSocket;

use function Amp\async;
use function Amp\Ipc\connect;

$clientHandler = function (ChannelledSocket $socket) {
    echo "Created connection.".PHP_EOL;

    while ($payload = $socket->receive()) {
        echo "Received $payload".PHP_EOL;
    }
    echo "Closed connection".PHP_EOL;
};

$channel = connect(sys_get_temp_dir().'/test');
async($clientHandler, $channel);
$channel->send('ping');
