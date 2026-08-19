<?php

namespace Plugin\endereco_jtl5_client;

use JTL\DB\DbInterface;
use JTL\Plugin\Bootstrapper;
use JTL\Plugin\PluginInterface;
use JTL\Services\DefaultServicesInterface;
use JTL\Services\JTL\AlertServiceInterface;
use JTL\Services\JTL\CryptoServiceInterface;
use JTL\Shop;
use JTL\Events\Dispatcher;
use JTL\Router\Router;
use JTL\Template\TemplateServiceInterface;
use Plugin\endereco_jtl5_client\src\BrowserRpc\BrowserRpcEndpoint;
use Plugin\endereco_jtl5_client\src\BrowserRpc\CurlEnderecoTransport;
use Plugin\endereco_jtl5_client\src\BrowserRpc\JtlBrowserSessionAdmission;
use Plugin\endereco_jtl5_client\src\Handler\AjaxHandler;
use Plugin\endereco_jtl5_client\src\Handler\AttributeHandler;
use Plugin\endereco_jtl5_client\src\Handler\BrowserRpcRouteHandler;
use Plugin\endereco_jtl5_client\src\Handler\CommentHandler;
use Plugin\endereco_jtl5_client\src\Handler\TemplateHandler;
use Plugin\endereco_jtl5_client\src\Handler\MetaHandler;
use Plugin\endereco_jtl5_client\src\Handler\SessionHandler;
use Plugin\endereco_jtl5_client\src\Helper\EnderecoService;
use JTL\Smarty\JTLSmarty;

/**
 * Bootstrap class for the endereco_jtl5_client plugin.
 * This class initializes and sets up the plugin by registering various handlers and services.
 */
class Bootstrap extends Bootstrapper
{
    /**
     * Bootstraps the plugin.
     * This method sets up handlers and services and registers necessary events and hooks.
     *
     * @param Dispatcher $dispatcher The event dispatcher.
     */
    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);

        if (!Shop::isFrontend()) {
            return;
        }

        /** @var PluginInterface $plugin */
        $plugin = $this->getPlugin();

        /** @var DefaultServicesInterface $container */
        $container =  Shop::Container();

        /** @var TemplateServiceInterface $templateService */
        $templateService = $container->getTemplateService();

        /** @var DbInterface $dbConnection */
        $dbConnection = $container->getDB();

        /** @var AlertServiceInterface $alertService */
        $alertService = $container->getAlertService();

        $enderecoService = new EnderecoService(
            $plugin,
            $dbConnection
        );

        // This service handles all the hooks.
        $templateHandler = new TemplateHandler(
            $plugin,
            $enderecoService,
            $templateService,
            $dbConnection,
            $alertService
        );

        // This service handles all the hooks.
        $ajaxHandler = new AjaxHandler(
            $dbConnection,
            $enderecoService,
        );

        // This service handles the meta, except for ajax requests.
        $metaHandler = new MetaHandler(
            $plugin,
            $dbConnection,
            $enderecoService
        );

        $sessionHandler = new SessionHandler(
            $enderecoService
        );

        $attributeHandler = new AttributeHandler(
            $dbConnection,
            $enderecoService,
            $plugin
        );

        $commentHandler = new CommentHandler(
            $plugin,
            $enderecoService
        );

        $browserRpcRouteHandler = new BrowserRpcRouteHandler(
            $plugin,
            new BrowserRpcEndpoint(
                new JtlBrowserSessionAdmission(),
                new CurlEnderecoTransport()
            )
        );

        $dispatcher->listen(
            'shop.hook.' . \HOOK_ROUTER_PRE_DISPATCH,
            static function (array $args) use ($browserRpcRouteHandler): void {
                /** @var Router $router */
                $router = $args['router'];
                $router->addRoute(
                    BrowserRpcRouteHandler::ROUTE_SLUG,
                    [$browserRpcRouteHandler, 'handle'],
                    BrowserRpcRouteHandler::ROUTE_NAME,
                    ['POST']
                );
            }
        );

        // Extend the templates with necessary template extensions.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER,
            [
                $templateHandler, 'generalTemplateIntegration'
            ]
        );
        $dispatcher->listen(
            'shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER,
            [
                $templateHandler, 'addSpecialPayPalCheckoutListener'
            ]
        );

        // Register io request listeners.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_IO_HANDLE_REQUEST,
            [
                $ajaxHandler, 'registerAjaxMethods'
            ]
        );

        // Clear address meta from session, when loading registration page.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_REGISTRIEREN_PAGE,
            [
                $metaHandler, 'clearMetaFromSession'
            ]
        );

        // Close sessions.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_SHOP_SET_PAGE_TYPE,
            [
                $sessionHandler, 'closeSessions'
            ]
        );

        // Save meta from submit in the database.
        if (defined('HOOK_REGISTRATION_CUSTOMER_CREATED')) {
            $dispatcher->listen(
                'shop.hook.' . \HOOK_REGISTRATION_CUSTOMER_CREATED,
                [
                    $metaHandler, 'saveMetaFromSubmitInDatabase'
                ]
            );
        }
        $dispatcher->listen(
            'shop.hook.' . \HOOK_SHOP_SET_PAGE_TYPE,
            [
                $metaHandler, 'saveMetaFromSubmitInDatabase'
            ]
        );

        $dispatcher->listen(
            'shop.hook.' . \HOOK_SHOP_SET_PAGE_TYPE,
            [
                $metaHandler, 'saveMetaFromSubmitInCache'
            ]
        );

        // Load meta from database into session.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_JTL_PAGE,
            [
                $metaHandler, 'loadMetaFromDatabase'
            ]
        );
        $dispatcher->listen(
            'shop.hook.' . \HOOK_BESTELLVORGANG_PAGE,
            [
                $metaHandler, 'loadMetaFromDatabase'
            ]
        );
        $dispatcher->listen(
            'shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPBESTAETIGUNG,
            [
                $metaHandler, 'loadMetaFromDatabase'
            ]
        );

        // Save attributes.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE,
            [
                $attributeHandler, 'saveOrderAttribute'
            ]
        );

        // Extend comment.
        $dispatcher->listen(
            'shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE,
            [
                $commentHandler, 'extendOrderComment'
            ]
        );
        $dispatcher->listen(
            'shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE,
            [
                $metaHandler, 'clearMetaAndCacheFromSession'
            ]
        );
    }
}
