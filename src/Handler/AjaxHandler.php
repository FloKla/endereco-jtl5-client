<?php

namespace Plugin\endereco_jtl5_client\src\Handler;

use JTL\Checkout\Lieferadresse;
use JTL\Customer\Customer;
use JTL\Customer\DataHistory;
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
        $customerExistsInDB = !empty($params['customerId']);
        $copyToShipping = strtolower($params['copyShippingToo']) === 'true';

        // Load customer or create a new customer object.
        if ($customerExistsInDB) {
            $customer = new Customer($params['customerId']);
        } else {
            $customer = $_SESSION['Kunde'] ?? null;
        }

        if (!empty($customer)) {
            $customer = $this->updateAddressData($customer, $params['updatedAddress']);
        }

        $addressMeta = (new AddressMeta())->assign(
            $params['enderecometa']['ts'],
            $params['enderecometa']['status'],
            $params['enderecometa']['predictions']
        );

        // Update customer in the database
        if ($customerExistsInDB) {
            $this->enderecoService->updateAddressInDB($customer);
            $this->enderecoService->updateAddressMetaInDB(
                $customer,
                $addressMeta
            );
        }

        // Update customer in the session
        $this->enderecoService->updateAddressInSession($customer);
        $this->enderecoService->updateAddressMetaInSession(
            'EnderecoBillingAddressMeta',
            $addressMeta
        );

        $addressData = $this->extractAddressData($params);

        $this->enderecoService->updateAddressMetaInCache(
            $addressData,
            $addressMeta
        );

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

        $isPresetKnown = !empty($_SESSION['shippingAddressPresetID']);

        $deliveryAddress = $this->updateAddressData($_SESSION['Lieferadresse'], $params['updatedAddress']);
        $addressMeta = (new AddressMeta())->assign(
            $params['enderecometa']['ts'],
            $params['enderecometa']['status'],
            $params['enderecometa']['predictions']
        );

        if ($isPresetKnown && class_exists('JTL\Checkout\DeliveryAddressTemplate')) {
            $presetAddress = new \JTL\Checkout\DeliveryAddressTemplate(
                $this->dbConnection,
                $_SESSION['shippingAddressPresetID']
            );

            if (!empty($presetAddress->kLieferadresse)) {
                $presetAddress = $this->updateAddressData($presetAddress, $params['updatedAddress']);
                $this->enderecoService->updateAddressInDB($presetAddress);
                $this->enderecoService->updateAddressMetaInDB(
                    $presetAddress,
                    $addressMeta
                );
            }
        }

        if (!empty($_SESSION['Lieferadresse']->kLieferadresse)) {
            $this->enderecoService->updateAddressMetaInDB(
                $_SESSION['Lieferadresse'],
                $addressMeta
            );
        }

        // Update delivery address in the session
        $this->enderecoService->updateAddressInSession($deliveryAddress);
        $this->enderecoService->updateAddressMetaInSession(
            'EnderecoShippingAddressMeta',
            $addressMeta
        );

        $addressData = $this->extractAddressData($params);

        $this->enderecoService->updateAddressMetaInCache(
            $addressData,
            $addressMeta
        );

        return;
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

        $inputContent = file_get_contents('php://input');
        // Check if $inputContent is a valid string
        if ($inputContent === false) {
            // Handle the error or return early
            return;
        }

        $postData = json_decode($inputContent, true);
        if ($postData === null) {
            // json_decode failed, handle the error or return early
            return;
        }

        // Register the method and provide
        $args['request'] = json_encode([
            'name' => $postData['method'],
            'params' => [
                'params' => $postData['params']
            ]
        ]);

        $args['io']->register($postData['method'], [$this, $postData['method']]);
    }
}
