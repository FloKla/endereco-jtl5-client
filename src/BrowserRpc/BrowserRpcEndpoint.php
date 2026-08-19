<?php

declare(strict_types=1);

namespace Plugin\endereco_jtl5_client\src\BrowserRpc;

use JsonException;
use stdClass;
use Throwable;

class BrowserRpcEndpoint
{
    private const MAX_REQUEST_BYTES = 32768;
    private const ALLOWED_METHODS = [
        'postCodeAutocomplete',
        'cityNameAutocomplete',
        'streetAutocomplete',
        'splitStreet',
        'addressCheck',
        'emailCheck',
        'phoneCheck',
        'nameCheck',
    ];
    private const RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    private BrowserSessionAdmission $sessionAdmission;
    private EnderecoTransport $transport;

    public function __construct(
        BrowserSessionAdmission $sessionAdmission,
        EnderecoTransport $transport
    ) {
        $this->sessionAdmission = $sessionAdmission;
        $this->transport = $transport;
    }

    /**
     * @param array{
     *     body: string,
     *     contentType: string,
     *     token: string|null,
     *     transactionId: string|null,
     *     referer: string|null
     * } $request
     * @param array{apiKey: mixed, remoteUrl: mixed, agent: mixed} $configuration
     * @return array{
     *     response: array{
     *         status: int,
     *         document: mixed,
     *         headers: array{Cache-Control: string, X-Content-Type-Options: string, Retry-After?: string}
     *     },
     *     failure: array<string, int|string>|null
     * }
     */
    public function handle(array $request, array $configuration): array
    {
        $requestBytes = strlen($request['body']);
        $requestId = null;
        $method = null;

        try {
            if ($request['contentType'] !== 'application/json') {
                return $this->clientError(415, -32000, 'Server error', null);
            }
            if ($requestBytes > self::MAX_REQUEST_BYTES) {
                return $this->clientError(413, -32000, 'Server error', null);
            }

            try {
                $document = json_decode($request['body'], false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                return $this->clientError(400, -32700, 'Parse error', null);
            }

            if (!$document instanceof stdClass) {
                return $this->clientError(400, -32600, 'Invalid Request', null);
            }
            if (isset($document->id) && (is_string($document->id) || is_int($document->id))) {
                $requestId = $document->id;
            }
            if (
                !isset($document->jsonrpc)
                || $document->jsonrpc !== '2.0'
                || !property_exists($document, 'id')
                || (!is_string($document->id) && !is_int($document->id))
                || !property_exists($document, 'params')
                || (!is_array($document->params) && !$document->params instanceof stdClass)
                || !isset($document->method)
                || !is_string($document->method)
            ) {
                return $this->clientError(400, -32600, 'Invalid Request', $requestId);
            }

            $method = $document->method;
            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                return $this->clientError(400, -32601, 'Method not found', $requestId);
            }
            if (!self::isConfigurationValid($configuration)) {
                return $this->serverError(503, 'configuration', $requestId, $method, $requestBytes);
            }

            $transactionId = $request['transactionId'];
            if (
                $transactionId !== null
                && (strlen($transactionId) < 1
                    || strlen($transactionId) > 128
                    || preg_match('/^[A-Za-z0-9._:-]+$/', $transactionId) !== 1)
            ) {
                return $this->clientError(400, -32600, 'Invalid Request', $requestId);
            }
            $transactionId = $transactionId ?? 'not_required';
            $referer = $this->sanitizeReferer($request['referer']);

            $upstreamDocument = [
                'jsonrpc' => '2.0',
                'id' => $requestId,
                'method' => $method,
                'params' => $document->params,
            ];
            $upstreamBody = json_encode($upstreamDocument, JSON_THROW_ON_ERROR);

            $admission = $this->sessionAdmission->admit($request['token']);
            if ($admission['status'] === 'invalid_token') {
                return $this->clientError(403, -32000, 'Server error', $requestId);
            }
            if ($admission['status'] === 'rate_limited') {
                $outcome = $this->clientError(429, -32000, 'Server error', $requestId);
                $outcome['response']['headers']['Retry-After'] = (string) max(1, (int) ($admission['retryAfter'] ?? 1));
                return $outcome;
            }

            $transportResult = $this->transport->send([
                'url' => trim((string) $configuration['remoteUrl']),
                'body' => $upstreamBody,
                'apiKey' => trim((string) $configuration['apiKey']),
                'agent' => trim((string) $configuration['agent']),
                'transactionId' => $transactionId,
                'referer' => $referer,
            ]);
            $duration = isset($transportResult['durationMs']) ? (int) $transportResult['durationMs'] : null;
            $transportStatus = $transportResult['status'];
            if ($transportStatus !== 'http') {
                $status = $transportStatus === 'timeout' ? 504 : 502;
                return $this->serverError(
                    $status,
                    'transport_' . $transportStatus,
                    $requestId,
                    $method,
                    $requestBytes,
                    $duration
                );
            }

            $upstream = $this->decodeUpstreamResponse((string) ($transportResult['body'] ?? ''), $requestId);
            if ($upstream === null) {
                return $this->serverError(
                    502,
                    'upstream_response',
                    $requestId,
                    $method,
                    $requestBytes,
                    $duration
                );
            }

            return [
                'response' => [
                    'status' => (int) ($transportResult['httpStatus'] ?? 0),
                    'document' => $upstream,
                    'headers' => self::RESPONSE_HEADERS,
                ],
                'failure' => null,
            ];
        } catch (Throwable $exception) {
            return $this->serverError(500, 'endpoint', $requestId, $method, $requestBytes);
        }
    }

    /**
     * @param array{apiKey: mixed, remoteUrl: mixed, agent: mixed} $configuration
     */
    public static function isConfigurationValid(array $configuration): bool
    {
        if (
            !is_string($configuration['apiKey'] ?? null)
            || trim($configuration['apiKey']) === ''
            || !is_string($configuration['agent'] ?? null)
            || trim($configuration['agent']) === ''
            || !is_string($configuration['remoteUrl'] ?? null)
        ) {
            return false;
        }

        $url = trim($configuration['remoteUrl']);
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || !isset($parts['host'])
        ) {
            return false;
        }

        $host = strtolower($parts['host']);
        return $host === 'endereco-service.de' || str_ends_with($host, '.endereco-service.de');
    }

    private function sanitizeReferer(?string $referer): string
    {
        if ($referer === null || $referer === '') {
            return 'not_set';
        }

        $parts = preg_split('/[?#]/', $referer, 2);
        return $parts === false || $parts[0] === '' ? 'not_set' : $parts[0];
    }

    /**
     * @param int|string|null $requestId
     */
    private function decodeUpstreamResponse(string $body, $requestId): ?stdClass
    {
        try {
            $document = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        if (
            !$document instanceof stdClass
            || !isset($document->jsonrpc)
            || $document->jsonrpc !== '2.0'
            || !property_exists($document, 'id')
            || $document->id !== $requestId
        ) {
            return null;
        }

        $hasResult = property_exists($document, 'result');
        $hasError = property_exists($document, 'error');
        if ($hasResult === $hasError) {
            return null;
        }
        if (
            $hasError
            && (!$document->error instanceof stdClass
                || !isset($document->error->code)
                || !is_int($document->error->code)
                || !isset($document->error->message)
                || !is_string($document->error->message))
        ) {
            return null;
        }

        return $document;
    }

    /**
     * @param int|string|null $requestId
     * @return array{
     *     response: array{
     *         status: int,
     *         document: mixed,
     *         headers: array{Cache-Control: string, X-Content-Type-Options: string, Retry-After?: string}
     *     },
     *     failure: null
     * }
     */
    private function clientError(int $status, int $code, string $message, $requestId): array
    {
        return [
            'response' => [
                'status' => $status,
                'document' => $this->errorDocument($code, $message, $requestId),
                'headers' => self::RESPONSE_HEADERS,
            ],
            'failure' => null,
        ];
    }

    /**
     * @param int|string|null $requestId
     * @return array{
     *     response: array{
     *         status: int,
     *         document: mixed,
     *         headers: array{Cache-Control: string, X-Content-Type-Options: string, Retry-After?: string}
     *     },
     *     failure: array<string, int|string>
     * }
     */
    private function serverError(
        int $status,
        string $category,
        $requestId,
        ?string $method,
        int $requestBytes,
        ?int $duration = null
    ): array {
        $correlationId = $this->createCorrelationId();
        $document = $this->errorDocument(-32000, 'Server error', $requestId);
        $document['error']['data'] = ['correlationId' => $correlationId];
        $failure = [
            'correlationId' => $correlationId,
            'category' => $category,
            'status' => $status,
            'requestBytes' => $requestBytes,
        ];
        if ($method !== null && in_array($method, self::ALLOWED_METHODS, true)) {
            $failure['method'] = $method;
        }
        if ($duration !== null) {
            $failure['upstreamDurationMs'] = $duration;
        }

        return [
            'response' => [
                'status' => $status,
                'document' => $document,
                'headers' => self::RESPONSE_HEADERS,
            ],
            'failure' => $failure,
        ];
    }

    /**
     * @param int|string|null $requestId
     * @return array{
     *     jsonrpc: string,
     *     id: int|string|null,
     *     error: array{code: int, message: string}
     * }
     */
    private function errorDocument(int $code, string $message, $requestId): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function createCorrelationId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            return str_replace('.', '', uniqid('rpc', true));
        }
    }
}
