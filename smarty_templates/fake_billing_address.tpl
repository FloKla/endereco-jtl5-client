<form class="endereco-fake-addresses" action="#" method="post">
    <div style="display: none !important">
        <input id="endereco_billing_countrycode" type="text" value="{$endereco_billing_countrycode|escape:'html'}">
        {if $endereco_billing_has_subdivision}
            <input id="endereco_billing_subdivision_code" type="text" value="{$endereco_billing_subdivision_code|escape:'html'}" data-endereco-subdivision-active="true">
        {/if}
        <input id="endereco_billing_postal_code" type="text" value="{$endereco_billing_postal_code|escape:'html'}">
        <input id="endereco_billing_locality" type="text" value="{$endereco_billing_locality|escape:'html'}">
        <input id="endereco_billing_street_name" type="text" value="{$endereco_billing_street_name|escape:'html'}">
        <input id="endereco_billing_building_number" type="text" value="{$endereco_billing_building_number|escape:'html'}">
        {if $endereco_billing_has_addinfo}
            <input id="endereco_billing_addinfo" type="text" value="{$endereco_billing_addinfo|escape:'html'}">
        {/if}

        <input id="endereco_billing_ts" type="text" value="{$endereco_billing_ts|escape:'html'}">
        <input id="endereco_billing_status" type="text" value="{$endereco_billing_status|escape:'html'}">
        <input id="endereco_billing_predictions" type="text" value="{$endereco_billing_predictions|escape:'html'}">

        <input
                id="endereco_billing_address"
                data-customer-id="{$Kunde->kKunde}"
                data-country-code="{$endereco_billing_countrycode|escape:'html'}"
                data-postal-code="{$endereco_billing_postal_code|escape:'html'}"
                data-locality="{$endereco_billing_locality|escape:'html'}"
                data-street-name="{$endereco_billing_street_name|escape:'html'}"
                data-building-number="{$endereco_billing_building_number|escape:'html'}"
                data-additional-info="{$endereco_billing_addinfo|escape:'html'}"
                data-copy-shipping="{if !$endereco_shipping_address_is_different}true{else}false{/if}"
        >
    </div>

    <script>
        {literal}
        (function() {
            var ioUrl = 'io?io=endereco_inner_request';

            function afterCreateHandler(EAO) {
                if (!EAO) {
                    return;
                }

                EAO.onEditAddress.push(function() {
                    window.location = 'bestellvorgang.php?editRechnungsadresse=1';
                });

                EAO.onAfterAddressPersisted.push(function(addressObject, result) {
                    if (!result || 'finished' !== result.processStatus) {
                        return;
                    }
                    // The SDK awaits this Promise before it continues processing.
                    return postAddressData(addressObject);
                });
            }

            function postAddressData(EAO) {
                const originalAddress = document.querySelector('#endereco_billing_address');
                if (!originalAddress) {
                    return;
                }
                const coordinator = window.EnderecoIntegrator.jtlReviewCoordinator;
                coordinator.beginUpdate();
                return EAO.util.axios({
                    method: 'post',
                    url: ioUrl,
                    data: {
                        method: 'updateBillingAddress',
                        params: {
                            customerId: originalAddress.dataset.customerId,
                            updatedAddress: EAO.address,
                            originalAddress: {
                                countryCode: originalAddress.dataset.countryCode,
                                postalCode: originalAddress.dataset.postalCode,
                                locality: originalAddress.dataset.locality,
                                streetName: originalAddress.dataset.streetName,
                                buildingNumber: originalAddress.dataset.buildingNumber,
                                additionalInfo: originalAddress.dataset.additionalInfo,
                            },
                            copyShippingToo: originalAddress.dataset.copyShipping,
                            enderecometa: {
                                ts: EAO.addressTimestamp,
                                status: EAO.addressStatus,
                                predictions: EAO.addressPredictions,
                            }
                        }
                    }
                }).then(function() {
                    coordinator.finishUpdate(true);
                }).catch(function(error) {
                    // Fail open: log the failure, never fake a success and never
                    // block the checkout on it.
                    console.error('Error during address update:', error);
                    coordinator.finishUpdate(false);
                });
            }

            enderecoInitAMS(
                {
                    countryCode: '#endereco_billing_countrycode',
                    subdivisionCode: '#endereco_billing_subdivision_code',
                    postalCode: '#endereco_billing_postal_code',
                    locality: '#endereco_billing_locality',
                    streetName: '#endereco_billing_street_name',
                    buildingNumber: '#endereco_billing_building_number',
                    additionalInfo: '#endereco_billing_addinfo',
                    addressStatus: '#endereco_billing_status',
                    addressTimestamp: '#endereco_billing_ts',
                    addressPredictions: '#endereco_billing_predictions'
                },
                {
                    name: 'billing_address_ams',
                    addressType: 'billing_address',
                    intent: 'review',
                    targetSelector: 'body',
                    insertPosition: 'beforeend'
                },
                afterCreateHandler
            ).catch(function(error) {
                console.warn('Endereco billing review initialization failed:', error);
            });
        })();
    </script>
    {/literal}
</form>
