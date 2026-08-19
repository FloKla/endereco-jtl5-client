<?php

declare(strict_types=1);

namespace Plugin\endereco_jtl5_client\src\BrowserRpc;

use Throwable;

class CurlEnderecoTransport implements EnderecoTransport
{
    private const TIMEOUT_SECONDS = 6;
    /**
     * Numeric libcurl identities keep this classification portable when a PHP
     * version does not expose every corresponding CURLE_SSL_* constant.
     */
    private const TLS_ERROR_CODES = [
        35, // CURLE_SSL_CONNECT_ERROR
        51, // CURLE_PEER_FAILED_VERIFICATION (legacy identity)
        53, // CURLE_SSL_ENGINE_NOTFOUND
        54, // CURLE_SSL_ENGINE_SETFAILED
        58, // CURLE_SSL_CERTPROBLEM
        59, // CURLE_SSL_CIPHER
        60, // CURLE_PEER_FAILED_VERIFICATION / CURLE_SSL_CACERT
        64, // CURLE_USE_SSL_FAILED
        66, // CURLE_SSL_ENGINE_INITFAILED
        77, // CURLE_SSL_CACERT_BADFILE
        80, // CURLE_SSL_SHUTDOWN_FAILED
        82, // CURLE_SSL_CRL_BADFILE
        83, // CURLE_SSL_ISSUER_ERROR
        90, // CURLE_SSL_PINNEDPUBKEYNOTMATCH
        91, // CURLE_SSL_INVALIDCERTSTATUS
    ];

    public function send(array $request): array
    {
        $startedAt = microtime(true);
        $handle = null;

        try {
            $handle = curl_init($request['url']);
            if ($handle === false) {
                return [
                    'status' => 'other',
                    'durationMs' => $this->duration($startedAt),
                ];
            }
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request['body'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Auth-Key: ' . $request['apiKey'],
                    'X-Transaction-Id: ' . $request['transactionId'],
                    'X-Agent: ' . $request['agent'],
                    'X-Transaction-Referer: ' . $request['referer'],
                    'Content-Length: ' . strlen($request['body']),
                ],
            ]);

            $body = curl_exec($handle);
            $errorCode = curl_errno($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $duration = $this->duration($startedAt);
        } catch (Throwable $exception) {
            return [
                'status' => 'other',
                'durationMs' => $this->duration($startedAt),
            ];
        } finally {
            // Release the handle by dropping the reference. curl_close() has had no effect
            // since PHP 8.0 and is deprecated as of PHP 8.5.
            $handle = null;
        }

        if ($errorCode !== CURLE_OK) {
            return [
                'status' => $this->categorizeError($errorCode),
                'durationMs' => $duration,
            ];
        }
        if (!is_string($body) || $httpStatus < 100 || $httpStatus > 599) {
            return [
                'status' => 'other',
                'durationMs' => $duration,
            ];
        }

        return [
            'status' => 'http',
            'httpStatus' => $httpStatus,
            'body' => $body,
            'durationMs' => $duration,
        ];
    }

    private function duration(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @return 'timeout'|'dns'|'connection'|'tls'|'other'
     */
    private function categorizeError(int $errorCode): string
    {
        if ($errorCode === CURLE_OPERATION_TIMEDOUT) {
            return 'timeout';
        }
        if (in_array($errorCode, [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST], true)) {
            return 'dns';
        }
        if (
            in_array(
                $errorCode,
                [CURLE_COULDNT_CONNECT, CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR],
                true
            )
        ) {
            return 'connection';
        }
        if (in_array($errorCode, self::TLS_ERROR_CODES, true)) {
            return 'tls';
        }

        return 'other';
    }
}
