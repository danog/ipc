# IPC

[![Build Status](https://img.shields.io/travis/danog/ipc/master.svg?style=flat-square)](https://travis-ci.org/danog/ipc)
[![Build status](https://ci.appveyor.com/api/projects/status/1tcxa257p5dj52ck?svg=true)](https://ci.appveyor.com/project/danog/ipc)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`danog/ipc` provides an async IPC server.

## Installation

```bash
composer require danog/ipc
```

## Example

Server:

```php
<?php

require 'vendor/autoload.php';

use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Loop;

use function Amp\asyncCall;
use function Amp\Ipc\listen;

Loop::run(static function () {
    $clientHandler = function (ChannelledSocket $socket) {
        echo "Accepted connection".PHP_EOL;

        while ($payload = yield $socket->receive()) {
            echo "Received $payload".PHP_EOL;
            if ($payload === 'ping') {
                yield $socket->send('pong');
                yield $socket->disconnect();
            }
        }
        echo "Closed connection".PHP_EOL."==========".PHP_EOL;
    };

    $server = listen(\sys_get_temp_dir().'/test');
    while ($socket = yield $server->accept()) {
        asyncCall($clientHandler, $socket);
    }
});

```

Client:

```php
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
```

