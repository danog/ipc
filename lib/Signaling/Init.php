<?php

namespace Amp\Ipc\Signaling;

final class Init
{
    const MAIN = 0;
    const STREAM = 1;

    /** @var string Password */
    private $password;
    /** @var int Channel type */
    private $type;
    /** @var int Channel version */
    private $version;

    /**
     * Constructor function
     *
     * @param string  $password Server password
     * @param integer $type     Chanel type
     * @param integer $version  Chanel version
     */
    public function __construct(string $password, int $type, int $version)
    {
        $this->password = $password;
        $this->type = $type;
        $this->version = $version;
    }
    /**
     * Get init password
     *
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }
    /**
     * Get channel type
     *
     * @return integer
     */
    public function getType(): int
    {
        return $this->type;
    }
    /**
     * Get channel version
     *
     * @return integer
     */
    public function getVersion(): int
    {
        return $this->version;
    }
}
