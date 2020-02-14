<?php

namespace Amp\Ipc\Sync;

interface ExitResult
{
    /**
     * @return mixed Return value of the callable given to the execution context.
     *
     * @throws \Amp\Ipc\Sync\PanicError If the context exited with an uncaught exception.
     */
    public function getResult();
}
