<?php

declare(strict_types=1);

namespace Plugin\endereco_jtl5_client\src\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Plugin\endereco_jtl5_client\src\BrowserRpc\BrowserRpcEndpoint;
use Psr\Http\Message\ServerRequestInterface;
use JTL\Plugin\PluginInterface;

class BrowserRpcRouteHandler
{
    public const ROUTE_SLUG = '/plugins/endereco_jtl5_client/browser-rpc';
    public const ROUTE_NAME = 'enderecoBrowserRpc';
    public const POST_ROUTE_NAME = 'enderecoBrowserRpcPOST';

    private PluginInterface $plugin;
    private BrowserRpcEndpoint $endpoint;

    public function __construct(PluginInterface $plugin, BrowserRpcEndpoint $endpoint)
    {
        $this->plugin = $plugin;
        $this->endpoint = $endpoint;
    }

    public function handle(ServerRequestInterface $request): JsonResponse
    {
        $configuration = [
            'apiKey' => $this->plugin->getConfig()->getValue('endereco_jtl5_client_api_key'),
            'remoteUrl' => $this->plugin->getConfig()->getValue('endereco_jtl5_client_remote_url'),
            'agent' => 'Endereco JTL5 Client v' . $this->plugin->getMeta()->getVersion(),
        ];
        $outcome = $this->endpoint->handle(
            [
                'body' => (string) $request->getBody(),
                'contentType' => $request->getHeaderLine('Content-Type'),
                'token' => $request->hasHeader('X-Endereco-Token')
                    ? $request->getHeaderLine('X-Endereco-Token')
                    : null,
                'transactionId' => $request->hasHeader('X-Transaction-Id')
                    ? $request->getHeaderLine('X-Transaction-Id')
                    : null,
                'referer' => $request->hasHeader('Referer')
                    ? $request->getHeaderLine('Referer')
                    : null,
            ],
            $configuration
        );

        if ($outcome['failure'] !== null) {
            $this->plugin->getLogger()->error('Browser RPC endpoint failure', $outcome['failure']);
        }

        return new JsonResponse(
            $outcome['response']['document'],
            $outcome['response']['status'],
            $outcome['response']['headers']
        );
    }
}
