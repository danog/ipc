# IPC

[![Continuous Integration](https://github.com/danog/ipc/actions/workflows/ci.yml/badge.svg)](https://github.com/danog/ipc/actions/workflows/ci.yml)
![License](https://img.shields.io/badge/license-MIT-blue.svg)

`danog/ipc` provides an async IPC server.

## Installation

```bash
composer require danog/ipc
```

## Transports

`IpcServer` picks the first transport that works (or the ones selected with the `TYPE_*` flags):

* UNIX sockets: on Windows (10 1803+) these require `ext-sockets`, since PHP streams don't support `AF_UNIX` there;
* FIFOs (Linux only);
* TCP on `127.0.0.1`, as a last resort: any local user can connect to it.

`connect()` takes the same URI as the server, whatever the transport: on Windows, and with TCP, the URI is a file
holding the endpoint.

## Example

Server:

```php
<?php declare(strict_types=1);

require 'vendor/autoload.php';

use Amp\Ipc\Sync\ChannelledSocket;

use function Amp\async;
use function Amp\Ipc\listen;

$clientHandler = function (ChannelledSocket $socket) {
    echo "Accepted connection".PHP_EOL;

    while ($payload = $socket->receive()) {
        echo "Received $payload".PHP_EOL;
        if ($payload === 'ping') {
            $socket->send('pong');
            $socket->disconnect();
        }
    }
    echo "Closed connection".PHP_EOL."==========".PHP_EOL;
};

$server = listen(sys_get_temp_dir().'/test');
while ($socket = $server->accept()) {
    async($clientHandler, $socket);
}
```

Client:

```php
<?php declare(strict_types=1);

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

$thread = async($clientHandler, $channel);

$channel->send('ping');

$thread->await();
```

