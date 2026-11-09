<?php declare(strict_types=1);

namespace Amp\Ipc\Sync;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use SplQueue;

/**
 * An asynchronous channel for sending data between threads and processes.
 *
 * Supports full duplex read and write.
 *
 * @api
 */
final class ChannelledSocket implements Channel
{
    private SplQueue $received;

    private ChannelParser $parser;

    private bool $closed = false;

    /**
     * Creates a new channel from the given stream objects. Note that $read and $write can be the same object.
     *
     */
    public function __construct(private ReadableStream $read, private WritableStream $write)
    {
        $this->received = new \SplQueue;
        $this->parser = new ChannelParser($this->received->push(...));
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function send(mixed $data): void
    {
        if ($this->closed) {
            throw new ChannelException('The channel was already closed!');
        }
        try {
            $this->write->write($this->parser->encode($data));
        } catch (StreamException $exception) {
            throw new ChannelException("Sending on the channel failed. Did the context die?", 0, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function receive(): mixed
    {
        while ($this->received->isEmpty()) {
            try {
                if ($this->closed) {
                    return null;
                }
                $chunk = $this->read->read();
            } catch (StreamException $exception) {
                throw new ChannelException("Reading from the channel failed. Did the context die?", 0, $exception);
            }

            if ($chunk === null) {
                $this->disconnect();
                return null;
            }

            $this->parser->push($chunk);
        }

        $received = $this->received->shift();
        if ($received instanceof ChannelCloseReq) {
            $this->disconnect();
            return null;
        }
        return $received;
    }

    /**
     * Keep the event loop running while a receive() waits for data (the default).
     */
    public function reference(): void
    {
        if ($this->read instanceof ResourceStream) {
            $this->read->reference();
        }
    }

    /**
     * Don't keep the event loop running while a receive() waits for data.
     *
     * A receive() can't be cancelled: a caller that stopped waiting for it (a timeout) leaves a read pending, which
     * would keep the event loop running until the other end sends something or disconnects.
     */
    public function unreference(): void
    {
        if ($this->read instanceof ResourceStream) {
            $this->read->unreference();
        }
    }

    /**
     * Cleanly disconnect from other endpoint.
     */
    public function disconnect(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            $this->write->write($this->parser->encode(new ChannelCloseReq));
        } catch (\Throwable) {
        }
        $this->read->close();
        $this->write->close();
    }
}
