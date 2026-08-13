<?php

namespace Plugin\endereco_jtl5_client\src\Handler;

use JTL\Checkout\DeliveryAddressTemplate;
use JTL\Checkout\Lieferadresse;
use JTL\Customer\Customer;
use JTL\DB\NiceDB;
use JTL\Plugin\PluginInterface;
use Plugin\endereco_jtl5_client\src\Helper\EnderecoService;
use JTL\Smarty\JTLSmarty;
use JTL\Shop;
use InvalidArgumentException;
use JTL\DB\DbInterface;
use Plugin\endereco_jtl5_client\src\Structures\AddressCheckResult;
use Plugin\endereco_jtl5_client\src\Structures\AddressMeta;

class MetaHandler
{
    private DbInterface $dbConnection;
    private EnderecoService $enderecoService;
    private PluginInterface $plugin;

    private bool $operationProcessed = false;

    /**
     * Constructs the MetaHandler object.
     *
     * Initializes the object with necessary dependencies for its operations.
     *
     * @param PluginInterface $plugin The instance of the plugin.
     * @param DbInterface $dbConnection The database connection instance.
     */
    public function __construct(
        PluginInterface $plugin,
        DbInterface $dbConnection,
        EnderecoService $enderecoService
    ) {
        $this->plugin = $plugin;
        $this->dbConnection = $dbConnection;
        $this->enderecoService = $enderecoService;
    }

    /**
     * Clears metadata from the session.
     *
     * This method unsets the billing and shipping address metadata from the session,
     * typically invoked on a GET request.
     */
    public function clearMetaFromSession(): void
    {
        if ('GET' === $_SERVER['REQUEST_METHOD']) {
            unset($_SESSION['EnderecoBillingAddressMeta']);
            unset($_SESSION['EnderecoShippingAddressMeta']);
        }
    }

    /**
     * Clears metadata and cache related to addresses from the session.
     *
     * This method specifically removes the session variables used to store billing address metadata,
     * shipping address metadata, and request cache related to address checks. It is typically used
     * to reset the session state regarding address information, for instance, after a user logs out,
     * or when starting a new checkout process.
     *
     * @return void This method does not return any value.
     */
    public function clearMetaAndCacheFromSession(): void
    {
        unset($_SESSION['EnderecoBillingAddressMeta']);
        unset($_SESSION['EnderecoShippingAddressMeta']);
        unset($_SESSION['EnderecoRequestCache']);
    }

    /**
     * Determines if the PayPal Express Checkout check is active based on the plugin configuration.
     *
     * @return bool Returns true if the PayPal Express Checkout check is enabled in the plugin settings,
     *              false otherwise.
     */
    private function isPayPalExpressCheckoutCheckActive(): bool
    {
        $config = $this->plugin->getConfig();
        $option = $config->getOption('endereco_jtl5_client_check_paypal_express');
        return $option !== null && ('on' === $option->value);
    }

    /**
     * Determines if the existing customer check is active based on the plugin configuration.
     *
     * @return bool Returns true if the existing customer check is enabled in the plugin settings, false otherwise.
     */
    private function isExistingCustomerCheckActive(): bool
    {
        $config = $this->plugin->getConfig();
        $option = $config->getOption('endereco_jtl5_client_check_existing');
        return $option !== null && ('on' === $option->value);
    }

    /**
     * Determines if the current payment method is PayPal Express Checkout.
     *
     * This method checks the session data to see if the selected payment method (Zahlungsart)
     * corresponds to PayPal Express Checkout, indicated by specific substrings in the module ID.
     *
     * @return bool Returns true if the current payment method is PayPal Express Checkout, false otherwise.
     */
    private function isPayPalExpressCheckout(): bool
    {
        $isPayPal = false;

        // Check if the payment method in the session is set to PayPal Checkout
        if (
            isset($_SESSION['Zahlungsart']) &&
            (strpos($_SESSION['Zahlungsart']->cModulId, 'paypalcheckout') !== false)
        ) {
            $isPayPal = true;
        } elseif (
            isset($_SESSION['Zahlungsart']) &&
            (strpos($_SESSION['Zahlungsart']->cModulId, 'paypalexpress') !== false)
        ) {
            $isPayPal = true;
        }

        return $isPayPal;
    }
    /**
     * Checks if the current checkout is being processed through PayPal Express Checkout.
     * Should be extended in the future with other payment sources.
     *
     * This method determines whether the payment source for the current checkout
     * process is PayPal Express Checkout. It relies on the `isPayPalExpressCheckout()`
     * method to ascertain the payment source.
     *
     * @return bool Returns true if the current checkout is via PayPal Express Checkout, false otherwise.
     */
    private function isAnyExternalSource(): bool
    {
        $isPayPalExpress = $this->isPayPalExpressCheckout();

        return $isPayPalExpress;
    }

    /**
     * Checks if the billing address metadata is present in the provided POST variables.
     *
     * This method assesses whether metadata specific to billing addresses (e.g., validation status)
     * is available in the POST request data.
     *
     * @param array<string,mixed> $postVariables The array of POST variables, typically $_POST,
     *                                           to check for address metadata.
     * @return bool Returns true if billing address metadata is present, false otherwise.
     */
    private function hasBillingAddressMeta(array $postVariables): bool
    {
        // Check for the presence of the 'enderecoamsstatus' key in the POST variables
        // This key represents a part of the billing address metadata
        return !empty($postVariables['enderecoamsstatus']);
    }

    /**
     * Checks if the shipping address metadata is present in the provided POST variables.
     *
     * This method assesses whether metadata specific to shipping addresses (e.g., validation status)
     * is available in the POST request data.
     *
     * @param array<string,mixed> $postVariables The array of POST variables, typically $_POST,
     *                                           to check for address metadata.
     * @return bool Returns true if shipping address metadata is present, false otherwise.
     */
    private function hasShippingAddressMeta(array $postVariables): bool
    {
        // Check for the presence of the 'enderecodeliveryamsstatus' key in the POST variables
        // This key represents a part of the shipping address metadata
        return !empty($postVariables['enderecodeliveryamsstatus']);
    }

    /**
     * Saves metadata submitted from a form into the database.
     *
     * This method handles different scenarios where address metadata is submitted via POST request,
     * such as editing billing or delivery addresses. It updates the database with the new metadata.
     *
     * @param array<string,mixed> $args Contextual arguments that may affect how metadata is saved,
     *                                  like page type or customer ID.
     */
    public function saveMetaFromSubmitInDatabase(array $args): void
    {
        // Return early if the request method is not POST
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }

        if ($this->operationProcessed) {
            return;
        }

        // Handling the scenario when saving the billing address in "My account"
        if (!empty($_GET['editRechnungsadresse']) && $this->hasBillingAddressMeta($_POST)) {
            $customer = $_SESSION['Kunde'] ?? null;
            $addressMeta = (new AddressMeta())->assign(
                $_POST['enderecoamsts'],
                $_POST['enderecoamsstatus'],
                $_POST['enderecoamspredictions']
            );
            // Update the billing address metadata if the customer exists
            if (!empty($customer) && !empty($customer->kKunde)) {
                $this->enderecoService->updateAddressMetaInDB(
                    $customer,
                    $addressMeta
                );
            }

            $this->operationProcessed = true;
        }

        // Handling the scenario when saving the billing address during the checkout process
        if (\PAGE_BESTELLVORGANG === $args['pageType'] && $this->hasBillingAddressMeta($_POST)) {
            $customer = $_SESSION['Kunde'] ?? null;
            $addressMeta = (new AddressMeta())->assign(
                $_POST['enderecoamsts'],
                $_POST['enderecoamsstatus'],
                $_POST['enderecoamspredictions']
            );
            // Update the billing address metadata if the customer exists
            if (!empty($customer) && !empty($customer->kKunde)) {
                $this->enderecoService->updateAddressMetaInDB(
                    $customer,
                    $addressMeta
                );
            }

            $this->operationProcessed = true;
        }

        // Handling the scenario when a specific customer ID is provided
        if (!empty($args['customerID']) && $this->hasBillingAddressMeta($_POST)) {
            $customer = new Customer($args['customerID']);
            $addressMeta = (new AddressMeta())->assign(
                $_POST['enderecoamsts'],
                $_POST['enderecoamsstatus'],
                $_POST['enderecoamspredictions']
            );
            // Update the billing address metadata for the specified customer
            $this->enderecoService->updateAddressMetaInDB(
                $customer,
                $addressMeta
            );

            $this->operationProcessed = true;
        }

        // Handling the scenario when editing a specific delivery address
        if (
            !empty($_GET['editLieferadresse']) &&
            !empty($_GET['editAddress']) &&
            class_exists('JTL\Checkout\DeliveryAddressTemplate')
        ) {
            $deliveryAddress = new \JTL\Checkout\DeliveryAddressTemplate(
                $this->dbConnection,
                $_GET['editAddress']
            );
            $addressMeta = (new AddressMeta())->assign(
                $_POST['enderecodeliveryamsts'],
                $_POST['enderecodeliveryamsstatus'],
                $_POST['enderecodeliveryamspredictions']
            );
            // Update the delivery address metadata
            $this->enderecoService->updateAddressMetaInDB(
                $deliveryAddress,
                $addressMeta
            );

            $this->operationProcessed = true;
        }
    }

    /**
     * Saves metadata submitted from a form into the database.
     *
     * This method handles different scenarios where address metadata is submitted via POST request,
     * such as editing billing or delivery addresses. It updates the database with the new metadata.
     */
    public function saveMetaFromSubmitInCache(): void
    {
        // Return early if the request method is not POST
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }

        // Handling the scenario when saving the billing address during the checkout process
        if ($this->hasBillingAddressMeta($_POST)) {
            // Update the billing address metadata in session variable
            $address = $this->extractBillingAddressFromPost($_POST);
            $addressMeta = (new AddressMeta())->assign(
                $_POST['enderecoamsts'],
                $_POST['enderecoamsstatus'],
                $_POST['enderecoamspredictions']
            );

            if ($addressMeta->hasAnyStatus()) {
                $this->enderecoService->updateAddressMetaInCache(
                    $address,
                    $addressMeta
                );
            }
        }

        if ($this->hasShippingAddressMeta($_POST)) {
            // Update the shipping address metadata in session variable
            $address = $this->extractShippingAddressFromPost($_POST);
            $addressMeta = (new AddressMeta())->assign(
                $_POST['enderecodeliveryamsts'],
                $_POST['enderecodeliveryamsstatus'],
                $_POST['enderecodeliveryamspredictions']
            );
            if ($addressMeta->hasAnyStatus()) {
                $this->enderecoService->updateAddressMetaInCache(
                    $address,
                    $addressMeta
                );
            }
        }
    }

    /**
     * Extracts billing address information from a post request variable.
     *
     * This method parses a given post request variable array to extract billing address
     * details, including country code, postal code, locality (city), street name,
     * building number, and any additional address information if available. It expects
     * each piece of address information to be provided under specific keys.
     *
     * @param array<string,string> $postVariable The post request variable containing billing address information.
     *                            Expected keys are 'land' (country code), 'plz' (postal code),
     *                            'ort' (locality), 'strasse' (street name), 'hausnummer' (building number),
     *                            'adresszusatz' (additional info) and 'bundesland' (state), which are optional.
     * @return array<string,string> An associative array containing the extracted billing address, formatted
     *               with keys as 'countryCode', 'postalCode', 'locality', 'streetName',
     *               'buildingNumber' and, when submitted, 'additionalInfo' and 'subdivisionCode'.
     */
    public function extractBillingAddressFromPost($postVariable): array
    {
        $countryCode = strtoupper($postVariable['land']);

        $address = [
            'countryCode' => $countryCode,
            'postalCode' => $postVariable['plz'],
            'locality' => $postVariable['ort'],
            'streetName' => $postVariable['strasse'],
            'buildingNumber' => $postVariable['hausnummer'],
        ];

        // Optional fields stay absent when the form did not submit them, so the
        // rebuilt cache key matches the browser's request. The state only takes
        // part when the country has ISO subdivisions - NOVA's free-text fallback
        // is not part of the frontend check either.
        if (isset($postVariable['bundesland']) && $this->enderecoService->countryHasSubdivisions($countryCode)) {
            $address['subdivisionCode'] = $this->enderecoService->resolveSubdivisionCode(
                $postVariable['bundesland'],
                $countryCode
            );
        }

        if (isset($postVariable['adresszusatz'])) {
            $address['additionalInfo'] = $postVariable['adresszusatz'];
        }

        return $address;
    }

    /**
     * Extracts shipping address information from a nested structure within a post request variable.
     *
     * Similar to the billing address extraction, this method focuses on extracting shipping
     * address details from a nested array structure within the given post request variable.
     * It requires the shipping address to be located under 'register' and then 'shipping_address',
     * with specific keys for each piece of address information.
     *
     * @param array<string,mixed> $postVariable The post request variable containing nested shipping address
     *                            information. Expected structure is $postVariable['register']['shipping_address']
     *                            with keys 'land' (country code), 'plz' (postal code), 'ort' (locality),
     *                            'strasse' (street name), 'hausnummer' (building number), and the optional
     *                            keys 'adresszusatz' (additional info) and 'bundesland' (state).
     * @return array<string,string> An associative array containing the extracted shipping address, with keys
     *               as 'countryCode', 'postalCode', 'locality', 'streetName', 'buildingNumber'
     *               and, when submitted, 'additionalInfo' and 'subdivisionCode'.
     */
    public function extractShippingAddressFromPost($postVariable): array
    {
        $shippingPost = $postVariable['register']['shipping_address'];
        $countryCode = strtoupper($shippingPost['land']);

        $address = [
            'countryCode' => $countryCode,
            'postalCode' => $shippingPost['plz'],
            'locality' => $shippingPost['ort'],
            'streetName' => $shippingPost['strasse'],
            'buildingNumber' => $shippingPost['hausnummer'],
        ];

        // See extractBillingAddressFromPost() for the optional-field rules.
        if (isset($shippingPost['bundesland']) && $this->enderecoService->countryHasSubdivisions($countryCode)) {
            $address['subdivisionCode'] = $this->enderecoService->resolveSubdivisionCode(
                $shippingPost['bundesland'],
                $countryCode
            );
        }

        if (isset($shippingPost['adresszusatz'])) {
            $address['additionalInfo'] = $shippingPost['adresszusatz'];
        }

        return $address;
    }

    /**
     * Processes the address check for a given address object.
     *
     * This method checks the address using the enderecoService, applies any automatic corrections,
     * and updates the address in the session. It supports different types of address objects.
     *
     * @param mixed &$address The address object to be checked and updated.
     *
     * @return AddressMeta The address metadata after processing.
     */
    private function processAddressCheck(&$address): AddressMeta
    {
        if (
            !$this->enderecoService->isObjectCustomer($address) &&
            !$this->enderecoService->isObjectDeliveryAddress($address) &&
            !$this->enderecoService->isObjectDeliveryAddressTemplate($address)
        ) {
            return new AddressMeta();
        }

        // Perform an address check using the endereco service
        $checkResult = $this->enderecoService->checkAddress($address);
        $addressMeta = $checkResult->getMeta();

        return $addressMeta;
    }

    /**
     * Applies address metadata to the given address object and updates it if necessary.
     *
     * This method checks if the address object is of a type that can be updated (customer, delivery address,
     * or delivery address template).
     * If the address object is eligible, it converts the AddressMeta to an AddressCheckResult, applies automatic
     * corrections if necessary, and updates the address and its metadata accordingly.
     *
     * @param mixed &$address The address object to be updated. This parameter is passed by reference to allow updating.
     * @param AddressMeta $addressMeta The metadata associated with the address, used to determine corrections.
     *
     * @return AddressMeta The updated or original AddressMeta object.
     */
    private function applyAddressMetaToAddress(&$address, AddressMeta $addressMeta): AddressMeta
    {
        if (
            !$this->enderecoService->isObjectCustomer($address) &&
            !$this->enderecoService->isObjectDeliveryAddress($address) &&
            !$this->enderecoService->isObjectDeliveryAddressTemplate($address)
        ) {
            return $addressMeta; // Return original meta.
        }

        $checkResult = AddressCheckResult::createFromMeta($addressMeta);

        // Apply automatic corrections if necessary and update the address
        if ($checkResult->isAutomaticCorrection() && !$checkResult->isConfirmedByCustomer()) {
            $addressMeta = $checkResult->getMetaAfterAutomaticCorrection();
            $address = $this->enderecoService->applyAutocorrection($address, $checkResult);
            $this->enderecoService->updateAddressInSession($address);
        } else {
            $addressMeta = $checkResult->getMeta();
        }

        return $addressMeta;
    }

    /**
     * Attempts to retrieve metadata from a cached result of a previous address check.
     *
     * This method is designed to check if there is a cached result for an address check performed
     * earlier, and if so, to retrieve the metadata associated with that result. It accepts an
     * address object, which could be an instance of Lieferadresse, DeliveryAddressTemplate, or
     * Customer. The method leverages the `lookupInCache` method of the `enderecoService` to perform
     * this operation.
     *
     * If a cached result is found, the method extracts and returns the metadata part of the address
     * check result. The metadata typically includes information about the validation process, such as
     * validation status, suggestions for corrections, or any warnings or errors encountered during the
     * address check.
     *
     * @param mixed $address The address object for which the metadata is to be retrieved.
     *
     * @return AddressMeta An object containing the metadata from the cached address check result.
     */
    private function tryToLoadMetaFromCache($address): AddressMeta
    {
        if (
            !$this->enderecoService->isObjectCustomer($address) &&
            !$this->enderecoService->isObjectDeliveryAddress($address) &&
            !$this->enderecoService->isObjectDeliveryAddressTemplate($address)
        ) {
            return new AddressMeta(); // Return empty.
        }

        // Perform an address check using the endereco service
        $lookupResult = $this->enderecoService->lookupInCache($address);
        $addressMeta = $lookupResult->getMeta();
        return $addressMeta;
    }

    /**
     * Loads billing address metadata into the session for a given customer.
     *
     * This method queries for existing metadata and updates it based on the customer's current billing
     * address. It handles different scenarios, such as PayPal Express Checkout, and updates both the
     * database and session with the new metadata.
     *
     * @param Customer $customer The customer whose billing address metadata needs to be updated.
     */
    private function loadBillingAddressMetaToSession($customer): void
    {
        if (!$this->enderecoService->isObjectCustomer($customer)) {
            // Update the address metadata in the session
            $this->enderecoService->updateAddressMetaInSession(
                'EnderecoBillingAddressMeta',
                new AddressMeta()
            );
            return;
        }

        $canLoadFromDB = !empty($customer->kKunde);
        $shouldCheckAgainstAPI = false;

        $addressMeta = new AddressMeta();

        if ($canLoadFromDB) {
            $addressMetaData = $this->dbConnection->queryPrepared(
                "SELECT `xplugin_endereco_jtl5_client_tams`.*
             FROM `xplugin_endereco_jtl5_client_tams`
             WHERE `kKunde` = :id",
                [':id' => $customer->kKunde],
                1
            );

            $addressMeta = $this->enderecoService->createAddressMetaFromDBData($addressMetaData);
        }

        // Determine if web api should be used.
        if (!$addressMeta->hasAnyStatus()) {
            if ($canLoadFromDB) {
                // Existing customer in DB.
                $shouldCheckAgainstAPI = $this->isExistingCustomerCheckActive();
            } else {
                // Guest or paypal express checkout import.
                $isPayPalExpressCheck = $this->isPayPalExpressCheckout() && $this->isPayPalExpressCheckoutCheckActive();
                $isGuestServersideCheck = $this->isExistingCustomerCheckActive() && !$this->isAnyExternalSource();
                $shouldCheckAgainstAPI = $isPayPalExpressCheck || $isGuestServersideCheck;
            }
        }

        // If no valid metadata found, try to load from cache or process address check
        if (!$addressMeta->hasAnyStatus()) {
            $addressMeta = $this->tryToLoadMetaFromCache($customer);
        }

        if (!$addressMeta->hasAnyStatus() && $shouldCheckAgainstAPI) {
            $addressMeta = $this->processAddressCheck($customer);
        }

        $addressMeta = $this->applyAddressMetaToAddress($customer, $addressMeta);

        // Update database and session if applicable
        if ($canLoadFromDB && $addressMeta->hasAnyStatus()) {
            $this->enderecoService->updateAddressMetaInDB($customer, $addressMeta);
        }

        // Update the address metadata in the session
        $this->enderecoService->updateAddressMetaInSession(
            'EnderecoBillingAddressMeta',
            $addressMeta
        );
    }

    /**
     * Loads shipping address metadata into the session for a given delivery address.
     *
     * This method handles different scenarios such as existing customer checks and PayPal Express Checkout.
     * It updates the database and session with the shipping address metadata.
     *
     * @param mixed $deliveryAddress The delivery address whose metadata needs to be updated.
     */
    private function loadShippingAddressMetaToSession($deliveryAddress): void
    {
        if (
            !$this->enderecoService->isObjectDeliveryAddress($deliveryAddress) &&
            !$this->enderecoService->isObjectDeliveryAddressTemplate($deliveryAddress)
        ) {
            // Update the address metadata in the session
            $this->enderecoService->updateAddressMetaInSession(
                'EnderecoShippingAddressMeta',
                new AddressMeta()
            );
            return;
        }

        $canLoadFromDB = !empty($deliveryAddress->kLieferadresse);
        $shouldCheckAgainstAPI = false;

        $addressMeta = new AddressMeta();

        if ($canLoadFromDB) {
            $addressMetaData = $this->dbConnection->queryPrepared(
                "SELECT `xplugin_endereco_jtl5_client_tams`.*
             FROM `xplugin_endereco_jtl5_client_tams`
             WHERE `kLieferadresse` = :id",
                [':id' => $deliveryAddress->kLieferadresse],
                1
            );
            $addressMeta = $this->enderecoService->createAddressMetaFromDBData($addressMetaData);
        }

        // Determine if web api should be used.
        if (!$addressMeta->hasAnyStatus()) {
            if ($canLoadFromDB) {
                // Existing customer in DB.
                $shouldCheckAgainstAPI = $this->isExistingCustomerCheckActive();
            } else {
                // Guest or paypal express checkout import.
                $isPayPalExpressCheck = $this->isPayPalExpressCheckout() && $this->isPayPalExpressCheckoutCheckActive();
                $isGuestServersideCheck = $this->isExistingCustomerCheckActive() && !$this->isAnyExternalSource();
                $shouldCheckAgainstAPI = $isPayPalExpressCheck || $isGuestServersideCheck;
            }
        }

        // If no valid metadata found, try to load from cache or process address check
        if (!$addressMeta->hasAnyStatus()) {
            $addressMeta = $this->tryToLoadMetaFromCache($deliveryAddress);
        }

        if (
            !$addressMeta->hasAnyStatus() &&
            $shouldCheckAgainstAPI &&
            $this->enderecoService->isBillingDifferentFromShipping()
        ) {
            $addressMeta = $this->processAddressCheck($deliveryAddress);
        }

        $addressMeta = $this->applyAddressMetaToAddress($deliveryAddress, $addressMeta);

        // Update database and session if applicable
        if ($canLoadFromDB && $addressMeta->hasAnyStatus()) {
            $this->enderecoService->updateAddressMetaInDB($deliveryAddress, $addressMeta);
        }

        // Update the address metadata in the session
        $this->enderecoService->updateAddressMetaInSession(
            'EnderecoShippingAddressMeta',
            $addressMeta
        );
    }

    /**
     * Loads metadata from the database based on the current context within the user interface.
     *
     * This method handles different scenarios such as editing billing or delivery addresses in the user's account,
     * or processing addresses during the checkout. It updates the session with the relevant metadata from the database.
     *
     * @param array<int,mixed> $args Contextual arguments that may affect how metadata is loaded.
     */
    public function loadMetaFromDatabase(array $args): void
    {
        // Clear any existing metadata from the session. But only once.
        if (!$this->operationProcessed) {
            $this->enderecoService->clearMetaFromSession();
            $this->operationProcessed = true;
        }

        // Handling the scenario when editing the billing address in "My account" (optionally the shipping too)
        if (!empty($_GET['editRechnungsadresse'])) {
            // Load billing address metadata for the current customer
            $customer = $_SESSION['Kunde'] ?? null;
            if (!empty($customer)) {
                $this->loadBillingAddressMetaToSession($customer);
            }

            // If a delivery address is set in the session, load its metadata as well
            if (
                isset($_SESSION['Lieferadresse']) &&
                empty($_SESSION['Lieferadresse']->kLieferadresse) &&
                empty($_SESSION['shippingAddressPresetID']) &&
                empty(Shop::Smarty()->getTemplateVars('shippingAddressPresetID'))
            ) {
                $deliveryAddress = $_SESSION['Lieferadresse'];
                $this->loadShippingAddressMetaToSession($deliveryAddress);
            }
        }

        // Handling the case of being in checkout after clicking on "edit delivery address" in payment page.
        if (!empty($_GET['editLieferadresse']) && (\HOOK_BESTELLVORGANG_PAGE === (int) $args[0])) {
            $customer = $_SESSION['Kunde'] ?? null;
            if (!empty($customer)) {
                $this->loadBillingAddressMetaToSession($customer);
            }

            $deliveryAddress = $_SESSION['Lieferadresse'] ?? null;
            if (!empty($deliveryAddress)) {
                $this->loadShippingAddressMetaToSession($deliveryAddress);
            }
        }

        // Handling the scenario when editing a specific delivery address in "My account"
        if (
            !empty($_GET['editLieferadresse']) &&
            !empty($_GET['editAddress']) &&
            class_exists('JTL\Checkout\DeliveryAddressTemplate')
        ) {
            // Load metadata for the specified delivery address
            $deliveryAddressId = intval($_GET['editAddress']);
            $deliveryAddress = new \JTL\Checkout\DeliveryAddressTemplate($this->dbConnection, $deliveryAddressId);
            $this->loadShippingAddressMetaToSession($deliveryAddress);
        }

        // Handling the scenario in "Checkout" on the last page.
        if (\HOOK_BESTELLVORGANG_PAGE_STEPBESTAETIGUNG === (int) $args[0]) {
            // Early stop for any possible address correction logic in the last step. We just empty the session.
            if ($this->isPreAuthBlacklist()) {
                $this->enderecoService->updateAddressMetaInSession(
                    'EnderecoBillingAddressMeta',
                    new AddressMeta()
                );
                $this->enderecoService->updateAddressMetaInSession(
                    'EnderecoShippingAddressMeta',
                    new AddressMeta()
                );
                return;
            }

            // Load billing address metadata for the current customer
            $customer = $_SESSION['Kunde'] ?? null;
            if (!empty($customer)) {
                $this->loadBillingAddressMetaToSession($customer);
            }

            // Load metadata for the Lieferadresse in SESSION
            $deliveryAddress = $_SESSION['Lieferadresse'] ?? null;
            if (!empty($deliveryAddress)) {
                $this->loadShippingAddressMetaToSession($deliveryAddress);
            }
        }
    }

    /**
     * Checks if the currently selected payment method is in the pre-authorization blacklist.
     *
     * Some payment methods are currently not compatible with Endereco's address validation
     * and correction process. For these methods, address changes (corrections) might lead
     * to authorization problems with the payment provider, as such changes could be
     * recognized as potential fraud.
     *
     * Until we extend our correction strategies in the plugin, these payments should be
     * excluded from the address validation logic. This ensures that no unexpected address
     * changes occur on the final checkout page for these payment methods.
     *
     * Currently, this method only checks for Klarna payments. If more payment methods
     * need to be added to the blacklist in the future, this method should be updated.
     *
     * @return bool Returns true if the current payment method is in the blacklist, false otherwise.
     */
    public function isPreAuthBlacklist()
    {
        $paymentMethodId = $_SESSION['Zahlungsart']->cModulId;

        // Check for incompatible payment methods
        $blacklistedKeywords = ['klarnapaylater', 'klarnapaynow', 'klarnasliceit'];
        foreach ($blacklistedKeywords as $keyword) {
            if (stripos($paymentMethodId, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
