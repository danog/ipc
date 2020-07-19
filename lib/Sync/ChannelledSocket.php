<?php

namespace Amp\Ipc\Sync;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Deferred;
use Amp\Ipc\Signaling\CloseAck;
use Amp\Ipc\Signaling\CloseReq;
use Amp\Ipc\Signaling\Inband;
use Amp\Ipc\Signaling\StreamMsg;
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

    /** @var int */
    private $state = self::ESTABLISHED;

    /** @var Deferred */
    private $closePromise;

    /** @var bool */
    private $reading = false;

    /** @var string Server password */
    private $password = '';

    /**
     * @param string   $pwd  Server password.
     * @param resource $read Readable stream resource.
     * @param resource $write Writable stream resource.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($pwd, $read, $write)
    {
        $this->password = $pwd;
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
        if (!$this->channel) {
            return new Success();
        }
        return call(function (): \Generator {
            $this->reading = true;
            $data = yield $this->channel->receive();
            $this->reading = false;

            if (!$data instanceof Inband) {
                return $data;
            }

            if ($data instanceof CloseReq) {
                yield $this->channel->send(new CloseAck);
                $this->state = self::GOT_FIN_MASK;
                yield $this->disconnect();
            } elseif ($data instanceof CloseAck) {
                $closePromise = $this->closePromise;
                $this->closePromise = null;
                $closePromise->resolve($data);
            }
        });
    }

    /**
     * Cleanly disconnect from other endpoint.
     *
     * @return Promise
     */
    public function disconnect(): Promise
    {
        if (!$this->channel) {
            return new Success();
        }
        $channel = $this->channel;
        $this->channel = null;
        return call(function () use ($channel): \Generator {
            yield $channel->send(new CloseReq);

            if ($this->reading) {
                $this->closePromise = new Deferred;
            }
            do {
                $data = yield ($this->closePromise ? $this->closePromise->promise() : $channel->receive());
                if ($data instanceof CloseReq) {
                    yield $channel->send(new CloseAck);
                    $this->state |= self::GOT_FIN_MASK;
                } elseif ($data instanceof CloseAck) {
                    $this->state |= self::GOT_ACK_MASK;
                }
            } while ($this->state !== self::GOT_ALL_MASK);


            $this->close();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function send($data): Promise
    {
        if (!$this->channel) {
            throw new ChannelException('The channel was already closed!');
        }
        return $this->channel->send($data);
    }

    /**
     * Wrap stream for usage over IPC channel
     *
     * @param InputStream|OutputStream|mixed $stream Stream
     * 
     * @return inpu
     */
    public function wrap($stream): Promise
    {

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
