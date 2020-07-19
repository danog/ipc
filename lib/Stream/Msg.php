<?php

namespace Amp\Ipc\Stream;

use Amp\Ipc\Signaling\Generic;

final class StreamMsg implements Generic
{
    /** @var int stream ID */
    private $streamId;
    /** @var mixed Message payload */
    private $payload;
    /**
     * Constructo stream message.
     *
     * @param int   $streamId Stream ID
     * @param mixed $payload  Payload
     */
    public function __construct(int $streamId, $payload)
    {
        $this->streamId = $streamId;
        $this->payload = $payload;
    }
    /**
     * Get stream ID.
     *
     * @return int
     */
    public function getStreamId(): int
    {
        return $this->streamId;
    }
    /**
     * Get message payload.
     *
     * @return mixed
     */
    public function getPayload()
    {
        return $this->payload;
    }
}
