<?php

namespace Amp\Ipc\Signaling;

final class InitAck
{
    const STATUS_OK = 0;
    const STATUS_WRONG_PASSWORD = 1;
    const STATUS_WRONG_VERSION = 2;

    /** @var int Status */
    private $status;

    /**
     * Constructor
     *
     * @param integer $status Init status
     */
    public function __construct(int $status)
    {
        $this->status = $status;
    }
    /**
     * Get init status
     *
     * @return integer
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * OK
     *
     * @return self
     */
    public static function ok(): self
    {
        return new self(self::STATUS_OK);
    }
    /**
     * Wrong password
     *
     * @return self
     */
    public static function wrongPassword(): self
    {
        return new self(self::STATUS_WRONG_PASSWORD);
    }
    /**
     * Wrong version
     *
     * @return self
     */
    public static function wrongversion(): self
    {
        return new self(self::STATUS_WRONG_VERSION);
    }
}