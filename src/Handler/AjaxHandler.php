<?php

namespace Plugin\endereco_jtl5_client\src\Handler;

use JTL\Checkout\Lieferadresse;
use JTL\Customer\Customer;
use JTL\Customer\DataHistory;
use JTL\Helpers\Form;
use JTL\Helpers\Text;
use JTL\DB\NiceDB;
use JTL\DB\DbInterface;
use Plugin\endereco_jtl5_client\src\Helper\EnderecoService;
use Plugin\endereco_jtl5_client\src\Structures\AddressMeta;

class AjaxHandler
{
    private DbInterface $dbConnection;
    private EnderecoService $enderecoService;

    /**
     * Constructs the AjaxHandler object.
     *
     * @param DbInterface $dbConnection The database connection instance.
     * @param EnderecoService $enderecoService Then edereco service.
     */
    public function __construct(
        DbInterface $dbConnection,
        EnderecoService $enderecoService
    ) {
        $this->dbConnection = $dbConnection;
        $this->enderecoService = $enderecoService;
    }

    /**
     * Updates the address data.
     *
     * @param mixed $addressObject The address object to be updated.
     * @param array<string, string> $addressData The address data for the update.
     *
     * @return mixed Updated address object.
     */
    private function updateAddressData($addressObject, array $addressData)
    {
        if (isset($addressData['subdivisionCode'])) {
            // JTL persists plain state names ("Bayern"), not ISO codes ("DE-BY").
            $addressObject->cBundesland = $this->enderecoService->resolveSubdivisionName(
                Text::filterXSS($addressData['subdivisionCode']),
                strtoupper($addressData['countryCode'] ?? ($addressObject->cLand ?? ''))
            );
        }
        $addressObject->cStrasse      = (isset($addressData['streetName']))
            ? Text::filterXSS($addressData['streetName']) : $addressObject->cStrasse;
        $addressObject->cHausnummer   = (isset($addressData['buildingNumber']))
            ? Text::htmlentities(Text::filterXSS($addressData['buildingNumber'])) : $addressObject->cHausnummer;
        $addressObject->cAdressZusatz = (isset($addressData['additionalInfo']))
            ? Text::htmlentities(Text::filterXSS($addressData['additionalInfo'])) : $addressObject->cAdressZusatz;
        $addressObject->cPLZ          = (isset($addressData['postalCode']))
            ? Text::htmlentities(Text::filterXSS($addressData['postalCode'])) : $addressObject->cPLZ;
        $addressObject->cOrt          = (isset($addressData['locality']))
            ? Text::filterXSS($addressData['locality']) : $addressObject->cOrt;
        $addressObject->cLand         = (isset($addressData['countryCode']))
            ? strtoupper(Text::htmlentities(Text::filterXSS($addressData['countryCode']))) : $addressObject->cLand;

        return $addressObject;
    }


    /**
     * Updates the billing address.
     *
     * @param array<mixed,mixed> $params Parameters containing customerId, updatedAddress, and enderecometa.
     *
     * @return void
     */
    public function updateBillingAddress($params): void
    {
        // The posted customerId is never used to pick the persistence target. It
        // is browser-supplied, so trusting it let any logged-in customer rewrite
        // another customer's billing address. The session is the only authority.
        $sessionCustomerId  = $this->getSessionCustomerId();
        $customerExistsInDB = $sessionCustomerId > 0;
        $copyToShipping     = isset($params['copyShippingToo'])
            && 'true' === strtolower((string)$params['copyShippingToo']);

        // Load customer or fall back to the session-only guest object.
        if ($customerExistsInDB) {
            $customer = new Customer($sessionCustomerId);
        } else {
            $customer = $_SESSION['Kunde'] ?? null;
        }

        if (!empty($customer)) {
            $customer = $this->updateAddressData($customer, $params['updatedAddress']);
        }

        $addressMeta = $this->buildAddressMeta($params);

        // Update customer in the database
        if ($customerExistsInDB) {
            $this->enderecoService->updateAddressInDB($customer);
            if ($addressMeta !== null) {
                $this->enderecoService->updateAddressMetaInDB(
                    $customer,
                    $addressMeta
                );
            }
        }

        // Update customer in the session
        $this->enderecoService->updateAddressInSession($customer);

        if ($addressMeta !== null) {
            $this->enderecoService->updateAddressMetaInSession(
                'EnderecoBillingAddressMeta',
                $addressMeta
            );

            $this->enderecoService->updateAddressMetaInCache(
                $this->extractAddressData($params),
                $addressMeta
            );
        }

        if ($copyToShipping) {
            $this->updateShippingAddress($params);
        }

        return;
    }

    /**
     * Updates the shipping address information.
     *
     * This method handles the update of a shipping address based on the provided parameters.
     * It is capable of updating both a preset shipping address (if available) and the current
     * shipping address in the session. The method updates the address details in the database
     * (if a preset is known) and also in the current session. Additionally, it updates the
     * address metadata in both the database and the session.
     *
     * @param array<mixed,mixed> $params An associative array containing the necessary parameters to update
     *                      the shipping address. Expected keys are:
     *                      - 'updatedAddress': An array with the updated address information.
     *                      - 'enderecometa': An array with metadata related to the address.
     *
     * @return void This method does not return a value.
     *
     */
    public function updateShippingAddress($params): void
    {
        if (empty($_SESSION['Lieferadresse'] ?? null)) {
            return;
        }

        $sessionCustomerId = $this->getSessionCustomerId();
        $isPresetKnown     = !empty($_SESSION['shippingAddressPresetID']);

        $deliveryAddress = $this->updateAddressData($_SESSION['Lieferadresse'], $params['updatedAddress']);
        $addressMeta     = $this->buildAddressMeta($params);

        if ($isPresetKnown && class_exists('JTL\Checkout\DeliveryAddressTemplate')) {
            $presetAddress = new \JTL\Checkout\DeliveryAddressTemplate(
                $this->dbConnection,
                $_SESSION['shippingAddressPresetID']
            );

            // The session preset ID is not trustworthy: JTL writes the posted
            // kLieferadresse into the session before its own ownership check and
            // leaves a foreign value there when that check fails, while
            // DeliveryAddressTemplate::load() queries without a kKunde filter.
            // Prove the preset belongs to the session customer before writing.
            // Declared int in 5.3/5.4 and ?int from 5.5 on, so cast without a
            // null coalesce: the property always exists, it is only sometimes null.
            $presetOwnerId = (int)$presetAddress->kKunde;
            $isPresetOwned = $presetOwnerId > 0 && $presetOwnerId === $sessionCustomerId;

            if (!empty($presetAddress->kLieferadresse) && $isPresetOwned) {
                $presetAddress = $this->updateAddressData($presetAddress, $params['updatedAddress']);
                $this->enderecoService->updateAddressInDB($presetAddress);
                if ($addressMeta !== null) {
                    $this->enderecoService->updateAddressMetaInDB(
                        $presetAddress,
                        $addressMeta
                    );
                }
            }
        }

        if ($addressMeta !== null && !empty($_SESSION['Lieferadresse']->kLieferadresse)) {
            $this->enderecoService->updateAddressMetaInDB(
                $_SESSION['Lieferadresse'],
                $addressMeta
            );
        }

        // Update delivery address in the session
        $this->enderecoService->updateAddressInSession($deliveryAddress);

        if ($addressMeta !== null) {
            $this->enderecoService->updateAddressMetaInSession(
                'EnderecoShippingAddressMeta',
                $addressMeta
            );

            $this->enderecoService->updateAddressMetaInCache(
                $this->extractAddressData($params),
                $addressMeta
            );
        }

        return;
    }

    /**
     * Resolves the customer the current session is authenticated as.
     *
     * Guest checkout puts an unsaved Customer into the session, whose kKunde stays
     * at 0. Callers use that to tell the database path from the session-only path.
     *
     * @return int The session customer id, or 0 when no persisted customer exists.
     */
    private function getSessionCustomerId(): int
    {
        return (int)($_SESSION['Kunde']->kKunde ?? 0);
    }

    /**
     * Builds the address metadata, or returns null when it cannot be built safely.
     *
     * AddressMeta::getTimestamp() is typed int and JTL's IO dispatcher only catches
     * Exception, not Error. A missing or non-numeric timestamp would therefore
     * surface as an HTTP 500 rather than a skipped metadata write.
     *
     * @param array<mixed,mixed> $params The request parameters.
     *
     * @return AddressMeta|null The metadata, or null when it must be skipped.
     */
    private function buildAddressMeta($params): ?AddressMeta
    {
        $meta = $params['enderecometa'] ?? null;

        if (!is_array($meta) || !isset($meta['ts']) || !is_numeric($meta['ts'])) {
            return null;
        }

        return (new AddressMeta())->assign(
            (int)$meta['ts'],
            $meta['status'] ?? null,
            $meta['predictions'] ?? null
        );
    }

    /**
     * Checks whether the request payload is shaped well enough to be processed.
     *
     * This is deliberately narrow. Individual address fields stay the concern of
     * EnderecoService, which already type-guards them; this only rejects payloads
     * whose shape would crash the update methods.
     *
     * @param array<mixed,mixed> $params The request parameters.
     *
     * @return bool True when the payload may be processed.
     */
    private function hasProcessablePayload(array $params): bool
    {
        if (empty($params['updatedAddress']) || !is_array($params['updatedAddress'])) {
            return false;
        }

        if (isset($params['copyShippingToo']) && !is_scalar($params['copyShippingToo'])) {
            return false;
        }

        if (isset($params['enderecometa']) && !is_array($params['enderecometa'])) {
            return false;
        }

        return true;
    }

    /**
     * Records that a request was refused, without registering any method.
     *
     * Only the reason class is logged. Payload contents, the request token, address
     * data and customer identifiers are deliberately never written, because a
     * rejection is invisible to the customer and this log is the only way a silent
     * failure in the field becomes discoverable at all.
     *
     * @param string $reason One of 'token', 'method' or 'payload'.
     *
     * @return void
     */
    private function refuseRequest(string $reason): void
    {
        $this->enderecoService->plugin->getLogger()->notice(
            'Endereco address update refused',
            ['reason' => $reason]
        );
    }

    /**
     * Extracts and returns the updated address data from the given parameters.
     *
     * This method assumes that the input array contains an 'updatedAddress' key
     * which holds the address data to be extracted. It directly returns the value
     * associated with this key. If the 'updatedAddress' key does not exist, the behavior
     * will depend on how the array is structured and how it handles missing keys.
     *
     * @param array<mixed,mixed> $params An associative array containing at least the 'updatedAddress' key.
     *
     * @return array<string,string> The address data extracted from the input parameters.
     */
    public function extractAddressData($params): array
    {
        return $params['updatedAddress'];
    }

    /**
     * Registers AJAX methods for handling specific AJAX requests within the JTL5 plugin.
     *
     * This method is invoked as a listener to the 'shop.hook.HOOK_IO_HANDLE_REQUEST' event.
     * It checks if the incoming request is an 'endereco_inner_request' and a POST request.
     * If so, it decodes the JSON payload from the request body and registers the specified
     * method for execution. This registration allows the 'handleRequest' method of the IO
     * handling class to execute the method dynamically using reflection, based on the
     * method name provided in the AJAX request.
     *
     * The method expects the request data to be in a specific format, where 'method'
     * indicates the method to be called, and 'params' contains the parameters for that method.
     *
     * @param array<string,mixed> $args An associative array containing the necessary parameters, including:
     *                    - 'request': The request type to check. It should match 'endereco_inner_request'
     *                                for the method to proceed with registration.
     *                    - 'io': The IO handling object responsible for registering the method.
     *
     * @return void This method does not return a value.
     *
     * Example Usage:
     * ```
     * $dispatcher->listen('shop.hook.' . \HOOK_IO_HANDLE_REQUEST, [$ajaxHandler, 'registerAjaxMethods']);
     * ```
     * Where $ajaxHandler is an instance of AjaxHandler, and $dispatcher is the event dispatcher.
     *
     * Note: This method is tightly coupled with the internal workings of the JTL5 plugin's
     *       event handling and IO processing system. Ensure that the AJAX request conforms
     *       to the expected format for seamless operation.
     */
    public function registerAjaxMethods(array $args): void
    {
        $isEnderecoRequest = 'endereco_inner_request' === $args['request'];
        $isPostRequest = 'POST' === $_SERVER['REQUEST_METHOD'];

        if (!$isEnderecoRequest || !$isPostRequest) {
            return;
        }

        // The request token travels as a header: the body is JSON, so
        // Form::validateToken()'s own $_POST and $_GET fallbacks cannot see it and
        // the value has to be handed over explicitly. The browser already sends
        // this header on every request through the shared Axios instance.
        if (!Form::validateToken($_SERVER['HTTP_X_ENDERECO_TOKEN'] ?? null)) {
            $this->refuseRequest('token');

            return;
        }

        $inputContent = file_get_contents('php://input');
        // Check if $inputContent is a valid string
        if ($inputContent === false) {
            $this->refuseRequest('payload');

            return;
        }

        $postData = json_decode($inputContent, true);
        if (!is_array($postData)) {
            // json_decode failed or the body was not an object
            $this->refuseRequest('payload');

            return;
        }

        // Only the plugin's own update methods may be registered at the IO
        // dispatcher; every other requested method name is ignored.
        $allowedMethods = ['updateBillingAddress', 'updateShippingAddress'];
        if (
            !isset($postData['method']) ||
            !is_string($postData['method']) ||
            !in_array($postData['method'], $allowedMethods, true)
        ) {
            $this->refuseRequest('method');

            return;
        }

        $params = $postData['params'] ?? [];
        if (!is_array($params) || !$this->hasProcessablePayload($params)) {
            $this->refuseRequest('payload');

            return;
        }

        // Register the method and provide
        $args['request'] = json_encode([
            'name' => $postData['method'],
            'params' => [
                'params' => $params
            ]
        ]);

        $args['io']->register($postData['method'], [$this, $postData['method']]);
    }
}
