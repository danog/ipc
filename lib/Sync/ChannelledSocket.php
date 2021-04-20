<?php

namespace Amp\Ipc\Sync;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Deferred;
use Amp\Promise;
use Amp\Success;

use function Amp\call;

final class ChannelledSocket implements Channel
{
    private const ESTABLISHED = 0;

    private const GOT_FIN_MASK = 1;
    private const GOT_ACK_MASK = 2;

    private const GOT_ALL_MASK = 3;

    /** @var ChannelledStream */
    private $channel;

    /** @var ResourceInputStream */
    private $read;

    /** @var ResourceOutputStream */
    private $write;

    /** @var bool */
    private $closed = false;

    /** @var int */
    private $state = self::ESTABLISHED;

    /** @var Deferred */
    private $closePromise;

    /** @var bool */
    private $reading = false;

    /**
     * @param resource $read Readable stream resource.
     * @param resource $write Writable stream resource.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($read, $write)
    {
        $this->channel = new ChannelledStream(
            $this->read = new ResourceInputStream($read),
            $this->write = new ResourceOutputStream($write)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): Promise
    {
        if ($this->closed) {
            return new Success();
        }
        return call(function (): \Generator {
            $this->reading = true;
            $data = yield $this->channel->receive();
            $this->reading = false;

            if ($data instanceof ChannelCloseReq) {
                yield $this->channel->send(new ChannelCloseAck);
                $this->state = self::GOT_FIN_MASK;
                yield $this->disconnect();
                if ($this->closePromise) {
                    $this->closePromise->resolve($data);
                }
                return null;
            } elseif ($data instanceof ChannelCloseAck) {
                $this->closePromise->resolve($data);
                return null;
            }

            return $data;
        });
    }

    /**
     * Cleanly disconnect from other endpoint.
     *
     * @return Promise
     */
    public function disconnect(): Promise
    {
        if ($this->closed) {
            return new Success();
        }
        return call(function (): \Generator {
            if ($this->reading) {
                $this->closePromise = new Deferred;
            }
            yield $this->channel->send(new ChannelCloseReq);
            do {
                $data = yield ($this->closePromise ? $this->closePromise->promise() : $this->channel->receive());
                if ($this->closePromise) {
                    $this->closePromise = null;
                }
                if ($data instanceof ChannelCloseReq) {
                    yield $this->channel->send(new ChannelCloseAck);
                    $this->state |= self::GOT_FIN_MASK;
                } elseif ($data instanceof ChannelCloseAck) {
                    $this->state |= self::GOT_ACK_MASK;
                }
            } while ($this->state !== self::GOT_ALL_MASK);

            $this->closed = true;

            $this->close();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function send($data): Promise
    {
        if ($this->closed) {
            throw new ChannelException('The channel was already closed!');
        }
        return $this->channel->send($data);
    }

    /**
     * {@inheritdoc}
     */
    public function unreference()
    {
        $this->read->unreference();
    }

    /**
     * {@inheritdoc}
     */
    public function reference()
    {
        $this->read->reference();
    }

    /**
     * Closes the read and write resource streams.
     */
    private function close()
    {
        $this->read->close();
        $this->write->close();
    }
}
