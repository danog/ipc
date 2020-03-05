<?php

require 'vendor/autoload.php';

use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Loop;

use function Amp\asyncCall;
use function Amp\Ipc\connect;

Loop::run(static function () {
    $clientHandler = function (ChannelledSocket $socket) {
        echo "Created connection.".PHP_EOL;

        while ($payload = yield $socket->receive()) {
            echo "Received $payload".PHP_EOL;
        }
        echo "Closed connection".PHP_EOL;
    };

    $channel = yield connect(\sys_get_temp_dir().'/test');
    asyncCall($clientHandler, $channel);
    yield $channel->send('ping');
});
