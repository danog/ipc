<?php

namespace Amp\Ipc\Sync;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use SplQueue;

/**
 * An asynchronous channel for sending data between threads and processes.
 *
 * Supports full duplex read and write.
 */
final class ChannelledStream implements Channel
{
    private SplQueue $received;

    private ChannelParser $parser;

    /**
     * Creates a new channel from the given stream objects. Note that $read and $write can be the same object.
     */
    public function __construct(private ReadableStream $read, private WritableStream $write)
    {
        $this->received = new \SplQueue;
        $this->parser = new ChannelParser($this->received->push(...));
    }

    /**
     * {@inheritdoc}
     */
    public function send(mixed $data): void
    {
        try {
            $this->write->write($this->parser->encode($data));
        } catch (StreamException $exception) {
            throw new ChannelException("Sending on the channel failed. Did the context die?", 0, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): mixed
    {
        while ($this->received->isEmpty()) {
            try {
                $chunk = $this->read->read();
            } catch (StreamException $exception) {
                throw new ChannelException("Reading from the channel failed. Did the context die?", 0, $exception);
            }

            if ($chunk === null) {
                throw new ChannelException("The channel closed unexpectedly. Did the context die?");
            }

            $this->parser->push($chunk);
        }

        return $this->received->shift();
    }
}
