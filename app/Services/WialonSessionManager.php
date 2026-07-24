<?php

namespace App\Services;

use Throwable;

class WialonSessionManager
{
    private ?string $sid = null;

    private bool $reauthorized = false;

    public function __construct(private WialonService $wialon)
    {
    }

    public function sid(): string
    {
        if ($this->sid === null) {
            $this->sid = $this->wialon->loginByToken(false);
        }

        return $this->sid;
    }

    public function reauthorizeOnce(): ?string
    {
        if ($this->reauthorized) {
            return null;
        }

        $this->reauthorized = true;
        $this->close();
        $this->sid = $this->wialon->loginByToken(false);

        return $this->sid;
    }

    public function close(): void
    {
        if ($this->sid === null) {
            return;
        }

        $sid = $this->sid;
        $this->sid = null;

        try {
            $this->wialon->logoutSession($sid);
        } catch (Throwable) {
            // Cleanup/logout must not hide a successfully saved report result.
        }
    }
}
