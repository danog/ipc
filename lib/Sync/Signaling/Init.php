<?php

namespace Amp\Ipc\Sync\Signaling;

class Init
{
    /**
     * Stream ID.
     *
     * @var string
     */
    private $streamId = '';
    /**
     * Constructor.
     *
     * @param string $streamId Optional stream ID
     */
    public function __construct(string $streamId = '')
    {
        $this->streamId =$streamId;
    }
    /**
     * Get strema ID.
     *
     * @return string
     */
    public function getStreamId(): string
    {
        return $this->streamId;
    }
}
