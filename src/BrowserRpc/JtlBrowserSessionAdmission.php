<?php

declare(strict_types=1);

namespace Plugin\endereco_jtl5_client\src\BrowserRpc;

use JTL\Helpers\Form;

class JtlBrowserSessionAdmission implements BrowserSessionAdmission
{
    private const SESSION_KEY = 'endereco_browser_rpc_window';
    private const WINDOW_SECONDS = 60;
    private const WINDOW_LIMIT = 120;

    public function admit(?string $token): array
    {
        try {
            if ($token === null || $token === '' || !Form::validateToken($token)) {
                return ['status' => 'invalid_token'];
            }

            $now = time();
            $window = $_SESSION[self::SESSION_KEY] ?? null;
            if (
                !is_array($window)
                || !isset($window['startedAt'], $window['count'])
                || !is_int($window['startedAt'])
                || !is_int($window['count'])
                || $now - $window['startedAt'] >= self::WINDOW_SECONDS
                || $now < $window['startedAt']
            ) {
                $window = ['startedAt' => $now, 'count' => 0];
            }

            if ($window['count'] >= self::WINDOW_LIMIT) {
                return [
                    'status' => 'rate_limited',
                    'retryAfter' => max(1, (int) ceil($window['startedAt'] + self::WINDOW_SECONDS - $now)),
                ];
            }

            ++$window['count'];
            $_SESSION[self::SESSION_KEY] = $window;
            return ['status' => 'accepted'];
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }
}
