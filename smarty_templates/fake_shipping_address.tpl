<form class="endereco-fake-addresses" action="#" method="post">
    <div style="display: none !important">
        <input id="endereco_shipping_countrycode" type="text" value="{$endereco_shipping_countrycode}">
        {if $endereco_shipping_has_subdivision}
            <input id="endereco_shipping_subdivision_code" type="text" value="{$endereco_shipping_subdivision_code}" data-endereco-subdivision-active="true">
        {/if}
        <input id="endereco_shipping_postal_code" type="text" value="{$endereco_shipping_postal_code}">
        <input id="endereco_shipping_locality" type="text" value="{$endereco_shipping_locality}">
        <input id="endereco_shipping_street_name" type="text" value="{$endereco_shipping_street_name}">
        <input id="endereco_shipping_building_number" type="text" value="{$endereco_shipping_building_number}">
        {if $endereco_shipping_has_addinfo}
            <input id="endereco_shipping_addinfo" type="text" value="{$endereco_shipping_addinfo}">
        {/if}

        <input id="endereco_shipping_ts" type="text" value="{$endereco_shipping_ts}">
        <input id="endereco_shipping_status" type="text" value="{$endereco_shipping_status|escape:'html'}">
        <input id="endereco_shipping_predictions" type="text" value="{$endereco_shipping_predictions|escape:'html'}">

        <input
                id="endereco_shipping_address"
                data-customer-id="{$Kunde->kKunde}"
                data-country-code="{$endereco_shipping_countrycode}"
                data-postal-code="{$endereco_shipping_postal_code}"
                data-locality="{$endereco_shipping_locality}"
                data-street-name="{$endereco_shipping_street_name}"
                data-building-number="{$endereco_shipping_building_number}"
                data-additional-info="{$endereco_shipping_addinfo}"
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
                    // Deliberately reuses the billing edit entry: JTL has no direct
                    // shipping-address edit entry point in the checkout.
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
                const originalAddress = document.querySelector('#endereco_shipping_address');
                if (!originalAddress) {
                    return;
                }
                const coordinator = window.EnderecoIntegrator.jtlReviewCoordinator;
                coordinator.beginUpdate();
                return EAO.util.axios({
                    method: 'post',
                    url: ioUrl,
                    data: {
                        method: 'updateShippingAddress',
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
                    countryCode: '#endereco_shipping_countrycode',
                    subdivisionCode: '#endereco_shipping_subdivision_code',
                    postalCode: '#endereco_shipping_postal_code',
                    locality: '#endereco_shipping_locality',
                    streetName: '#endereco_shipping_street_name',
                    buildingNumber: '#endereco_shipping_building_number',
                    additionalInfo: '#endereco_shipping_addinfo',
                    addressStatus: '#endereco_shipping_status',
                    addressTimestamp: '#endereco_shipping_ts',
                    addressPredictions: '#endereco_shipping_predictions'
                },
                {
                    name: 'shipping_address_ams',
                    addressType: 'shipping_address',
                    intent: 'review',
                    targetSelector: 'body',
                    insertPosition: 'beforeend'
                },
                afterCreateHandler
            ).catch(function(error) {
                console.warn('Endereco shipping review initialization failed:', error);
            });
        })();
    </script>
    {/literal}
</form>
