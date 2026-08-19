<?php

declare(strict_types=1);

namespace Plugin\endereco_jtl5_client\src\BrowserRpc;

interface BrowserSessionAdmission
{
    /**
     * @return array{status: 'accepted'|'invalid_token'|'rate_limited', retryAfter?: int}
     */
    public function admit(?string $token): array;
}
