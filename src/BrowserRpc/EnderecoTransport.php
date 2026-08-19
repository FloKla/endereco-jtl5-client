<?php

declare(strict_types=1);

namespace Plugin\endereco_jtl5_client\src\BrowserRpc;

interface EnderecoTransport
{
    /**
     * @param array{
     *     url: string,
     *     body: string,
     *     apiKey: string,
     *     agent: string,
     *     transactionId: string,
     *     referer: string
     * } $request
     * @return array{
     *     status: 'http'|'timeout'|'dns'|'connection'|'tls'|'other',
     *     httpStatus?: int,
     *     body?: string,
     *     durationMs?: int
     * }
     */
    public function send(array $request): array;
}
