<?php

namespace Amp\Ipc\Sync;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\DeferredFuture;
use AssertionError;

final class ChannelledSocket implements Channel
{
    private const ESTABLISHED = 0;

    private const GOT_FIN_MASK = 1;
    private const GOT_ACK_MASK = 2;

    private const GOT_ALL_MASK = 3;

    private ChannelledStream $channel;

    private ReadableResourceStream $read;

    private WritableResourceStream $write;

    private bool $closed = false;

    private int $state = self::ESTABLISHED;

    private ?DeferredFuture $closePromise = null;

    private bool $reading = false;

    /**
     * @param resource $read Readable stream resource.
     * @param resource $write Writable stream resource.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($read, $write)
    {
        $this->channel = new ChannelledStream(
            $this->read = new ReadableResourceStream($read),
            $this->write = new WritableResourceStream($write)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): mixed
    {
        if ($this->closed) {
            return null;
        }
        $this->reading = true;
        $data = $this->channel->receive();
        $this->reading = false;

        if ($data instanceof ChannelCloseReq) {
            $this->channel->send(new ChannelCloseAck);
            $this->state = self::GOT_FIN_MASK;
            $this->disconnect();
            if ($this->closePromise) {
                $this->closePromise->complete($data);
            }
            return null;
        } elseif ($data instanceof ChannelCloseAck) {
            if (!$this->closePromise) {
                throw new AssertionError('Must have a close promise!');
            }
            $this->closePromise->complete($data);
            return null;
        }

        return $data;
    }

    /**
     * Cleanly disconnect from other endpoint.
     */
    public function disconnect(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->reading) {
            $this->closePromise = new DeferredFuture;
        }
        $this->channel->send(new ChannelCloseReq);
        do {
            $data = ($this->closePromise ? $this->closePromise->getFuture()->await() : $this->channel->receive());
            if ($this->closePromise) {
                $this->closePromise = null;
            }
            if ($data instanceof ChannelCloseReq) {
                $this->channel->send(new ChannelCloseAck);
                $this->state |= self::GOT_FIN_MASK;
            } elseif ($data instanceof ChannelCloseAck) {
                $this->state |= self::GOT_ACK_MASK;
            }
        } while ($this->state !== self::GOT_ALL_MASK);

        $this->closed = true;

        $this->close();
    }

    /**
     * {@inheritdoc}
     */
    public function send(mixed $data): void
    {
        if ($this->closed) {
            throw new ChannelException('The channel was already closed!');
        }
        $this->channel->send($data);
    }

    /**
     * {@inheritdoc}
     */
    public function unreference(): void
    {
        $this->read->unreference();
    }

    /**
     * {@inheritdoc}
     */
    public function reference(): void
    {
        $this->read->reference();
    }

    /**
     * Closes the read and write resource streams.
     */
    private function close(): void
    {
        $this->read->close();
        $this->write->close();
    }
}
