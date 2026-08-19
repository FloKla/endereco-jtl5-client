<?php

namespace Plugin\endereco_jtl5_client\src\Handler;

use InvalidArgumentException;
use JTL\phpQuery\phpQuery;
use JTL\Plugin\PluginInterface;
use JTL\Services\JTL\AlertServiceInterface;
use JTL\phpQuery\phpQueryObject;
use Plugin\endereco_jtl5_client\src\Helper\EnderecoService;
use JTL\Smarty\JTLSmarty;
use JTL\Template\TemplateServiceInterface;
use JTL\DB\DbInterface;
use Illuminate\Support\Collection;
use JTL\Alert\Alert;
use Plugin\endereco_jtl5_client\src\Structures\AddressMeta;

class TemplateHandler
{
    private PluginInterface $plugin;
    private EnderecoService $enderecoService;
    private TemplateServiceInterface $templateService;
    private DbInterface $dbConnection;
    private AlertServiceInterface $alertService;

    private const TEMPLATE_BILLING_FORM_META = __DIR__ . '/../../smarty_templates/billing_ams_initiation.tpl';
    private const TEMPLATE_SHIPPING_FORM_META = __DIR__ . '/../../smarty_templates/shipping_ams_initiation.tpl';
    private const TEMPLATE_CHECKOUT_FAKE_BILLING_FORM = __DIR__ . '/../../smarty_templates/fake_billing_address.tpl';
    private const TEMPLATE_CHECKOUT_FAKE_SHIPPING_FORM = __DIR__ . '/../../smarty_templates/fake_shipping_address.tpl';
    private const TEMPLATE_PAYPAL_SPECIAL_LISTENER
        = __DIR__ . '/../../smarty_templates/paypal_checkout_special_listener.tpl';
    private const TEMPLATE_CONFIG = __DIR__ . '/../../smarty_templates/config.tpl';
    private const TEMPLATE_JS_BUNDLE = __DIR__ . '/../../smarty_templates/load_js.tpl';

    public function __construct(
        PluginInterface $plugin,
        EnderecoService $enderecoService,
        TemplateServiceInterface $templateService,
        DbInterface $dbConnection,
        AlertServiceInterface $alertService
    ) {
        $this->plugin = $plugin;
        $this->enderecoService = $enderecoService;
        $this->templateService = $templateService;
        $this->dbConnection = $dbConnection;
        $this->alertService = $alertService;
    }

    /**
     * Adds an error container (div element) after the parent of the specified element in the document.
     *
     * This method finds the element in the document using the provided CSS selector and inserts
     * a new div element right after its parent. The div is created with attributes specified in
     * the `$containerAttributes` array. If the element specified by the selector is not found,
     * the method does nothing.
     *
     * @param phpQueryObject $document The phpQuery object representing the DOM document.
     * @param string $selector The CSS selector used to find the target element in the document.
     * @param array<string,string> $containerAttributes An associative array of attributes for the error container.
     *                                  The array should contain key-value pairs where the key is
     *                                  the attribute name and the value is the attribute value.
     *                                  Both keys and values should be strings.
     *
     * @return void
     *
     * @example
     * ```php
     * $this->_addErrorContainer(
     *     $document,
     *     '#email[type="email"]',
     *     ['id' => 'container-email-error-messages']
     * );
     * ```
     *
     * @note This method assumes that the selected element has a parent. If the element is at the
     *       root of the document, the method may not behave as expected.
     */
    private function addErrorMessageContainer(
        phpQueryObject $document,
        string $selector,
        array $containerAttributes
    ): void {
        $attrString = "";
        foreach ($containerAttributes as $key => $value) {
            $attrString .= htmlspecialchars($key) . '="' . htmlspecialchars($value) . '" ';
        }

        // Check if the selector exists in the document
        $elements = $document->find($selector);
        if (count($elements) === 0) {
            return; // Exit if the selector is not found
        }

        // If the selector is found, proceed with DOM manipulation
        $elements->parent()->after("<div $attrString></div>");
    }

    /**
     * Checks if a billing address is present in the given HTML document.
     *
     * This method evaluates the existence of specific form fields related to a billing
     * address in the provided HTML document. It uses CSS selectors to search for elements
     * with specific name attributes ('land', 'plz', 'ort', 'strasse', 'hausnummer') and
     * checks if these elements are present in the document.
     *
     * @param phpQueryObject $document The HTML document to be searched for billing address fields.
     *
     * @return bool Returns true if all required billing address fields are found, false otherwise.
     */
    private function hasBillingAddress(phpQueryObject $document): bool
    {
        return count($document->find('[name="land"]')) > 0
            && count($document->find('[name="plz"]')) > 0
            && count($document->find('[name="ort"]')) > 0
            && count($document->find('[name="strasse"]')) > 0
            && count($document->find('[name="hausnummer"]')) > 0;
    }

    /**
     * Checks if a shipping address with values is present in the given HTML document.
     *
     * This method determines the presence and non-emptiness of specific form fields related
     * to a shipping address in the provided HTML document. It focuses on fields with particular
     * name attributes (encapsulating 'plz', 'ort', 'strasse' within the 'register[shipping_address]'
     * scope) and verifies if at least two of these elements exist and have non-empty values in the document.
     *
     * @param phpQueryObject $document The HTML document to be searched for filled shipping address fields.
     *
     * @return bool Returns true if at least two required shipping address fields are found and have values,
     *              false otherwise.
     */
    private function hasContentInShippingAddress(phpQueryObject $document): bool
    {
        $fieldsWithValues = 0;

        $plzField = $document->find('[name="register[shipping_address][plz]"]');
        if (count($plzField) > 0 && trim($plzField->val()) !== '') {
            $fieldsWithValues++;
        }

        $ortField = $document->find('[name="register[shipping_address][ort]"]');
        if (count($ortField) > 0 && trim($ortField->val()) !== '') {
            $fieldsWithValues++;
        }

        $strasseField = $document->find('[name="register[shipping_address][strasse]"]');
        if (count($strasseField) > 0 && trim($strasseField->val()) !== '') {
            $fieldsWithValues++;
        }

        return $fieldsWithValues >= 2;
    }

    /**
     * Checks if any 'kLieferadresse' elements with a value greater than 0 are checked in the HTML document.
     *
     * This method searches for input elements named 'kLieferadresse' with a value greater than 0.
     * It then checks if any of these elements are checked. The method returns true if at least
     * one matching element is found to be checked, indicating a selection or filled state.
     *
     * @param phpQueryObject $document The HTML document to be searched.
     *
     * @return bool Returns true if any 'kLieferadresse' element with value > 0 is checked, false otherwise.
     */
    private function isDeliverytemplateSelected(phpQueryObject $document): bool
    {
        // Find all elements with name 'kLieferadresse' and loop through them
        $elements = $document->find('[name="kLieferadresse"]');
        foreach ($elements as $element) {
            // phpQuery objects encapsulate DOM elements, so $element is a DOMElement
            // Use pq() to convert it back to a phpQueryObject
            $pqElement = phpQuery::pq($element);

            if (!$pqElement) {
                continue;
            }

            // Check if the element's value is greater than 0 and if it is checked
            if ((int)$pqElement->val() > 0 && $pqElement->is(':checked')) {
                return true; // Return true if conditions are met
            }
        }

        // Return false if no checked elements with value > 0 are found
        return false;
    }

    /**
     * Checks if a shipping address is present in the given HTML document.
     *
     * This method determines the presence of specific form fields related to a shipping
     * address in the provided HTML document. It searches for elements with particular name
     * attributes (encapsulating 'land', 'plz', 'ort', 'strasse', 'hausnummer' within the
     * 'register[shipping_address]' scope) and verifies if these elements exist in the document.
     *
     * @param phpQueryObject $document The HTML document to be searched for shipping address fields.
     *
     * @return bool Returns true if all required shipping address fields are found, false otherwise.
     */
    private function hasShippingAddress(phpQueryObject $document): bool
    {
        return count($document->find('[name="register[shipping_address][land]"]')) > 0
            && count($document->find('[name="register[shipping_address][plz]"]')) > 0
            && count($document->find('[name="register[shipping_address][ort]"]')) > 0
            && count($document->find('[name="register[shipping_address][strasse]"]')) > 0
            && count($document->find('[name="register[shipping_address][hausnummer]"]')) > 0;
    }

    /**
     * Determines if either a billing or shipping address is present in the given HTML document.
     *
     * This method checks the provided HTML document for the presence of billing and shipping
     * address fields. It utilizes two internal methods: `hasBillingAddress` and `hasShippingAddress`,
     * to perform these checks separately. The function returns true if either a billing address
     * or a shipping address is found in the document.
     *
     * @param phpQueryObject $document The HTML document to be searched for address fields.
     *
     * @return bool Returns true if either billing or shipping address fields are found, false otherwise.
     */
    private function hasAnyAddress(phpQueryObject $document): bool
    {
        return $this->hasBillingAddress($document) || $this->hasShippingAddress($document);
    }

    /**
     * Checks if the given HTML document represents a confirmation page.
     *
     * This method assesses whether the provided HTML document contains specific elements
     * that are indicative of a confirmation page. It iterates over an array of CSS selectors
     * corresponding to these elements (defined in `$markerElementSelectors`). If any of these
     * elements are found within the document, the method concludes that the page is a confirmation
     * page and returns true.
     *
     * @param phpQueryObject $document The HTML document to be examined.
     *
     * @return bool Returns true if the document is identified as a confirmation page, false otherwise.
     */
    private function isConfirmationPage(phpQueryObject $document): bool
    {
        $markerElementSelectors = [
            '#order-confirm',
        ];
        $anyFound = false;
        foreach ($markerElementSelectors as $markerElementSelector) {
            $anyFound = $anyFound || (count($document->find($markerElementSelector)) > 0);
        }

        return $anyFound;
    }

    /**
     * Adds billing address metadata to the address form in the provided HTML document.
     *
     * This method checks if the HTML document contains a billing address form. If found, it
     * assigns certain metadata (timestamp, status, and serialized predictions) related to the
     * billing address to the Smarty template engine. The method then fetches the HTML content
     * for these metadata from a specified template and inserts it after the 'strasse' field in
     * the billing address form.
     *
     * @param phpQueryObject $document The HTML document containing the billing address form.
     * @param JTLSmarty $smarty The Smarty template engine instance for rendering the metadata.
     * @param AddressMeta $addressMeta Address metadata.
     */
    private function addBillingAMSMetaToAddressForm(
        phpQueryObject $document,
        JTLSmarty $smarty,
        AddressMeta $addressMeta
    ): void {

        if (!$this->hasBillingAddress($document)) {
            return;
        }

        $smarty->assign('endereco_amsts', $addressMeta->getTimestamp())
            ->assign('endereco_amsstatus', $addressMeta->getStatusAsString())
            ->assign('endereco_amspredictions', $addressMeta->getPredictionsAsString());

        $file = self::TEMPLATE_BILLING_FORM_META;
        $html = $smarty->fetch($file);

        $document->find('[name="strasse"]')->after($html);
    }

    /**
     * Appends shipping address metadata to the address form within the provided HTML document.
     *
     * This method first checks if the HTML document contains a shipping address form. If present,
     * it proceeds to assign metadata (timestamp, status, and serialized predictions) related to the
     * shipping address to the Smarty template engine. The method then fetches the HTML content for
     * these metadata from a specified template and inserts it after the 'strasse' field in the
     * shipping address form. This addition of metadata is useful for enhancing form functionality
     * or providing additional context to users.
     *
     * @param phpQueryObject $document The HTML document containing the shipping address form.
     * @param JTLSmarty $smarty The Smarty template engine instance used for rendering the metadata.
     * @param AddressMeta $addressMeta Address metadata.
     */
    private function addShippingAMSMetaToAddressForm(
        phpQueryObject $document,
        JTLSmarty $smarty,
        AddressMeta $addressMeta
    ): void {

        if (!$this->hasShippingAddress($document)) {
            return;
        }

        $smarty->assign('endereco_delivery_amsts', $addressMeta->getTimestamp())
            ->assign('endereco_delivery_amsstatus', $addressMeta->getStatusAsString())
            ->assign('endereco_delivery_amspredictions', $addressMeta->getPredictionsAsString());

        $file = self::TEMPLATE_SHIPPING_FORM_META;
        $html = $smarty->fetch($file);

        $document->find('[name="register[shipping_address][strasse]"]')->after($html);
    }

    /**
     * Includes the SDK necessary for handling addresses in the provided HTML document.
     *
     * This method integrates the SDK into the HTML document if the document contains any address forms
     * or is a confirmation page. It prepares necessary data such as country mapping, plugin information,
     * and URLs for API calls. The method then assigns these data to the Smarty template engine and fetches
     * the required HTML content to be included in the document's head and body. This content includes
     * configuration settings, localized texts, and JavaScript bundles necessary for the SDK's functionality.
     *
     * @param phpQueryObject $document The HTML document where the SDK is to be included.
     * @param JTLSmarty $smarty The Smarty template engine instance used for rendering HTML content.
     */
    private function includeSDK($document, $smarty): void
    {
        if (
            !$this->hasAnyAddress($document) &&
            !$this->isConfirmationPage($document)
        ) {
            return;
        }

        // Get template name.
        $templateName = $this->templateService->getActiveTemplate()->getDir();

        // Get country mapping.
        $countries = $this->dbConnection->queryPrepared(
            "SELECT * FROM `tland`",
            [],
            2
        );

        if (!is_array($countries)) {
            $countries = [];
        }

        $countryMapping = [];
        foreach ($countries as $country) {
            if (!empty($_SESSION['cISOSprache']) && 'ger' === $_SESSION['cISOSprache']) {
                $countryMapping[$country->cISO] = $country->cDeutsch;
            } else {
                $countryMapping[$country->cISO] = $country->cEnglisch;
            }
        }

        $pluginIOPath = URL_SHOP . '/plugins/endereco_jtl5_client/io.php';

        $agentInfo = "Endereco JTL5 Client v" . $this->plugin->getMeta()->getVersion();

        $countryMappingJSON = json_encode($countryMapping);
        if (!$countryMappingJSON) {
            $countryMappingJSON = '[]';
        }

        $smarty->assign('endereco_theme_name', strtolower($templateName))
            ->assign('endereco_plugin_config', $this->plugin->getConfig())
            ->assign('endereco_locales', $this->plugin->getLocalization())
            ->assign('endereco_plugin_ver', $this->plugin->getMeta()->getVersion())
            ->assign('endereco_agent_info', $agentInfo)
            ->assign('endereco_api_url', $pluginIOPath)
            ->assign(
                'endereco_jtl5_client_country_mapping',
                str_replace('\'', '\\\'', $countryMappingJSON)
            );

        $html = $smarty->fetch(self::TEMPLATE_CONFIG);
        $document->find('head')->prepend($html);

        $html = $smarty->fetch(self::TEMPLATE_JS_BUNDLE);
        $document->find('body')->append($html);
    }

    /**
     * Adds billing address information to the confirmation page in the provided HTML document.
     *
     * This method is responsible for appending billing address details to the confirmation page.
     * It first checks if the given document is a confirmation page. If true, it sets various billing
     * address attributes (like country code, postal code, locality, etc.) to the Smarty template engine.
     * These attributes are then used to render HTML content, which is prepended to the body of the
     * document. This function is crucial for displaying confirmed billing address details on the
     * confirmation page.
     *
     * @param phpQueryObject $document The HTML document representing the confirmation page.
     * @param JTLSmarty $smarty The Smarty template engine instance for rendering HTML content.
     * @param string $countryCode The country code of the billing address.
     * @param string $postalCode The postal code of the billing address.
     * @param string $locality The locality or city of the billing address.
     * @param string $streetName The street name of the billing address.
     * @param string $buildingNumber The building number of the billing address.
     * @param string $additionalInfo Any additional information for the billing address.
     * @param string $timestamp The timestamp related to the billing address.
     * @param string $status The status of the billing address.
     * @param string $predictionsSerialized Serialized prediction data for the billing address.
     * @param string $state The stored state of the billing address (name or ISO code).
     */
    private function addBillingAddressToConfirmationPage(
        phpQueryObject $document,
        JTLSmarty $smarty,
        string $countryCode,
        string $postalCode,
        string $locality,
        string $streetName,
        string $buildingNumber,
        string $additionalInfo,
        string $timestamp,
        string $status,
        string $predictionsSerialized,
        string $state = ''
    ): void {
        if (!$this->isConfirmationPage($document)) {
            return;
        }

        // The fake form has to expose exactly the same optional address
        // structure as the normal billing form, otherwise the review check
        // produces different cache keys and payloads.
        $hasSubdivision = $this->enderecoService->isSubdivisionFieldEnabled(false)
            && $this->enderecoService->countryHasSubdivisions($countryCode);
        $subdivisionCode = $hasSubdivision
            ? $this->enderecoService->resolveSubdivisionCode($state, $countryCode)
            : '';

        // Session values arrive HTML-entity-encoded on most core paths but raw on
        // others ($htmlentities = false call sites). Decoding here and escaping in
        // the template produces identical DOM values either way and matches the
        // html_entity_decode() treatment of the server-side API payload builder.
        $smarty->assign('endereco_billing_countrycode', html_entity_decode($countryCode))
            ->assign('endereco_billing_postal_code', html_entity_decode($postalCode))
            ->assign('endereco_billing_locality', html_entity_decode($locality))
            ->assign('endereco_billing_street_name', html_entity_decode($streetName))
            ->assign('endereco_billing_building_number', html_entity_decode($buildingNumber))
            ->assign('endereco_billing_addinfo', html_entity_decode($additionalInfo))
            ->assign('endereco_billing_has_addinfo', $this->enderecoService->isAdditionalInfoFieldEnabled(false))
            ->assign('endereco_billing_has_subdivision', $hasSubdivision)
            ->assign('endereco_billing_subdivision_code', $subdivisionCode)
            ->assign('endereco_billing_ts', $timestamp)
            ->assign('endereco_billing_status', $status)
            ->assign('endereco_billing_predictions', $predictionsSerialized)
            ->assign(
                'endereco_shipping_address_is_different',
                $this->enderecoService->isBillingDifferentFromShipping()
            );

        $html = $smarty->fetch(self::TEMPLATE_CHECKOUT_FAKE_BILLING_FORM);

        $document->find('body')->prepend($html);
    }

    /**
     * Appends shipping address information to the confirmation page in the provided HTML document.
     *
     * This method adds shipping address details to the confirmation page of an order. It first verifies
     * that the given document is indeed a confirmation page and that the billing and shipping addresses
     * are different. After these checks, it assigns various shipping address attributes (such as country
     * code, postal code, locality, etc.) to the Smarty template engine. These details are then rendered
     * into HTML content, which is prepended to the body of the document. This addition is essential for
     * displaying the shipping address details on the confirmation page, especially when it differs from
     * the billing address.
     *
     * @param phpQueryObject $document The HTML document representing the confirmation page.
     * @param JTLSmarty $smarty The Smarty template engine instance for rendering HTML content.
     * @param string $countryCode The country code of the shipping address.
     * @param string $postalCode The postal code of the shipping address.
     * @param string $locality The locality or city of the shipping address.
     * @param string $streetName The street name of the shipping address.
     * @param string $buildingNumber The building number of the shipping address.
     * @param string $additionalInfo Any additional information for the shipping address.
     * @param string $timestamp The timestamp related to the shipping address.
     * @param string $status The status of the shipping address.
     * @param string $predictionsSerialized Serialized prediction data for the shipping address.
     * @param string $state The stored state of the shipping address (name or ISO code).
     */
    private function addShippingAddressToConfirmationPage(
        phpQueryObject $document,
        JTLSmarty $smarty,
        string $countryCode,
        string $postalCode,
        string $locality,
        string $streetName,
        string $buildingNumber,
        string $additionalInfo,
        string $timestamp,
        string $status,
        string $predictionsSerialized,
        string $state = ''
    ): void {
        if (!$this->isConfirmationPage($document)) {
            return;
        }

        if (!$this->enderecoService->isBillingDifferentFromShipping()) {
            return;
        }

        // See addBillingAddressToConfirmationPage() for the optional-field rules.
        $hasSubdivision = $this->enderecoService->isSubdivisionFieldEnabled(true)
            && $this->enderecoService->countryHasSubdivisions($countryCode);
        $subdivisionCode = $hasSubdivision
            ? $this->enderecoService->resolveSubdivisionCode($state, $countryCode)
            : '';

        // See addBillingAddressToConfirmationPage() for the decode-then-escape rules.
        $smarty->assign('endereco_shipping_countrycode', html_entity_decode($countryCode))
            ->assign('endereco_shipping_postal_code', html_entity_decode($postalCode))
            ->assign('endereco_shipping_locality', html_entity_decode($locality))
            ->assign('endereco_shipping_street_name', html_entity_decode($streetName))
            ->assign('endereco_shipping_building_number', html_entity_decode($buildingNumber))
            ->assign('endereco_shipping_addinfo', html_entity_decode($additionalInfo))
            ->assign('endereco_shipping_has_addinfo', $this->enderecoService->isAdditionalInfoFieldEnabled(true))
            ->assign('endereco_shipping_has_subdivision', $hasSubdivision)
            ->assign('endereco_shipping_subdivision_code', $subdivisionCode)
            ->assign('endereco_shipping_ts', $timestamp)
            ->assign('endereco_shipping_status', $status)
            ->assign('endereco_shipping_predictions', $predictionsSerialized);

        $html = $smarty->fetch(self::TEMPLATE_CHECKOUT_FAKE_SHIPPING_FORM);

        $document->find('body')->prepend($html);
    }

    /**
     * Integrates various address management functionalities into the general template.
     *
     * This method orchestrates the integration of multiple functionalities related to address
     * management within the template. It includes adding error message containers for email fields,
     * appending address metadata, and incorporating address details into the confirmation page.
     * The method checks if the enderecoService is ready before proceeding with the integration.
     * It then utilizes session data for various address-related attributes to perform these integrations.
     *
     * @param array<string,mixed> $args An associative array containing 'smarty' (the Smarty template engine instance)
     *                    and 'document' (the HTML document to be modified).
     */
    public function generalTemplateIntegration(array $args): void
    {
        // Set variables.
        $smarty = $args['smarty'];
        $document = $args['document'];

        if (!$this->enderecoService->isReady()) {
            return;
        }

        $this->addErrorMessageContainer(
            $document,
            '#email[type="email"]',
            [
                'id' => 'container-email-error-messages'
            ]
        );

        $this->addErrorMessageContainer(
            $document,
            '[name="register[shipping_address][email]"]',
            [
                'id' => 'container-shipping-email-error-messages'
            ]
        );

        $billingAddressMeta = (new AddressMeta())->assign(
            $_SESSION['EnderecoBillingAddressMeta']['enderecoamsts'] ?? '',
            $_SESSION['EnderecoBillingAddressMeta']['enderecoamsstatus'] ?? '',
            $_SESSION['EnderecoBillingAddressMeta']['enderecoamspredictions'] ?? ''
        );

        $this->addBillingAMSMetaToAddressForm(
            $document,
            $smarty,
            $billingAddressMeta
        );

        $shippingAddressMeta = new AddressMeta();

        if ($this->hasContentInShippingAddress($document) && !$this->isDeliverytemplateSelected($document)) {
            $shippingAddressMeta->assign(
                $_SESSION['EnderecoShippingAddressMeta']['enderecoamsts'] ?? '',
                $_SESSION['EnderecoShippingAddressMeta']['enderecoamsstatus'] ?? '',
                $_SESSION['EnderecoShippingAddressMeta']['enderecoamspredictions'] ?? ''
            );
        }

        $this->addShippingAMSMetaToAddressForm(
            $document,
            $smarty,
            $shippingAddressMeta
        );

        $this->addBillingAddressToConfirmationPage(
            $document,
            $smarty,
            $_SESSION['Kunde']->cLand ?? '',
            $_SESSION['Kunde']->cPLZ ?? '',
            $_SESSION['Kunde']->cOrt ?? '',
            $_SESSION['Kunde']->cStrasse ?? '',
            $_SESSION['Kunde']->cHausnummer ?? '',
            $_SESSION['Kunde']->cAdressZusatz ?? '',
            $_SESSION['EnderecoBillingAddressMeta']['enderecoamsts'] ?? '',
            $_SESSION['EnderecoBillingAddressMeta']['enderecoamsstatus'] ?? '',
            $_SESSION['EnderecoBillingAddressMeta']['enderecoamspredictions'] ?? '',
            $_SESSION['Kunde']->cBundesland ?? ''
        );

        $this->addShippingAddressToConfirmationPage(
            $document,
            $smarty,
            $_SESSION['Lieferadresse']->cLand ?? '',
            $_SESSION['Lieferadresse']->cPLZ ?? '',
            $_SESSION['Lieferadresse']->cOrt ?? '',
            $_SESSION['Lieferadresse']->cStrasse ?? '',
            $_SESSION['Lieferadresse']->cHausnummer ?? '',
            $_SESSION['Lieferadresse']->cAdressZusatz ?? '',
            $_SESSION['EnderecoShippingAddressMeta']['enderecoamsts'] ?? '',
            $_SESSION['EnderecoShippingAddressMeta']['enderecoamsstatus'] ?? '',
            $_SESSION['EnderecoShippingAddressMeta']['enderecoamspredictions'] ?? '',
            $_SESSION['Lieferadresse']->cBundesland ?? ''
        );

        $this->includeSDK($document, $smarty);
    }

    /**
     * Adds a special PayPal checkout listener to the page.
     *
     * This method initializes the process of adding a special PayPal checkout listener, if the
     * `enderecoService` is ready. It prepares necessary data and delegates the actual insertion
     * of the listener script into the page to `addSpecialPayPalCheckoutListenerScript`.
     *
     * @param array<string,mixed> $args An associative array containing setup arguments. Expected keys are:
     *                    - 'smarty': An instance of JTLSmarty, used for template rendering.
     *                    - 'document': An instance of phpQueryObject, representing the HTML document
     *                      to which the listener script will be added.
     * @return void The method does not return a value. It either triggers the addition of the
     *              PayPal checkout listener script or exits without action if the `enderecoService`
     *              is not ready.
     */
    public function addSpecialPayPalCheckoutListener(array $args)
    {
        // Set variables.
        $smarty = $args['smarty'];
        $document = $args['document'];

        if (!$this->enderecoService->isReady()) {
            return;
        }

        $this->addSpecialPayPalCheckoutListenerScript(
            $document,
            $smarty
        );
    }

    /**
     * Inserts the special PayPal checkout listener script into the HTML document.
     *
     * Called internally by `addSpecialPayPalCheckoutListener`, this method checks for relevant alerts
     * (e.g., missing payer data) and, if necessary, fetches and prepends the PayPal listener script
     * to a specific element in the document. This script enhances or modifies the PayPal checkout
     * experience based on the current state or needs (like alert conditions).
     *
     * @param phpQueryObject $document A phpQueryObject representing the HTML document to modify.
     * @param JTLSmarty $smarty An instance of JTLSmarty used to render the script template.
     *
     * @return void This method does not return a value. It directly modifies the passed `$document`
     *              by potentially prepending the PayPal listener script.
     */
    private function addSpecialPayPalCheckoutListenerScript(
        phpQueryObject $document,
        JTLSmarty $smarty
    ): void {

        /** @var Collection<int, Alert> $alertList */
        $alertList = $this->alertService->getAlertlist();
        $hasRelevantAlert = false;

        foreach ($alertList as $alert) {

            /** @var Alert $alert */
            if (in_array($alert->getKey(), ['missingPayerData'])) {
                $hasRelevantAlert = true;
            }
        }

        if ($hasRelevantAlert) {
            $html = $smarty->fetch(self::TEMPLATE_PAYPAL_SPECIAL_LISTENER);
            $document->find('#form-register')->prepend($html);
        }
    }
}
