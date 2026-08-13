<input type="hidden" name="enderecodeliveryamsts" value="{$endereco_delivery_amsts}" >
<input type="hidden" name="enderecodeliveryamsstatus" value="{$endereco_delivery_amsstatus|escape:'html'}" >
<input type="hidden" name="enderecodeliveryamspredictions" value="{$endereco_delivery_amspredictions|escape:'html'}">

<script>
    {literal}
    enderecoInitAMS(
        {
            countryCode: '[name="register[shipping_address][land]"]',
            subdivisionCode: '[name="register[shipping_address][bundesland]"]',
            postalCode: '[name="register[shipping_address][plz]"]',
            locality: '[name="register[shipping_address][ort]"]',
            streetFull: '',
            streetName: '[name="register[shipping_address][strasse]"]',
            buildingNumber: '[name="register[shipping_address][hausnummer]"]',
            addressStatus: '[name="enderecodeliveryamsstatus"]',
            addressTimestamp: '[name="enderecodeliveryamsts"]',
            addressPredictions: '[name="enderecodeliveryamspredictions"]',
            additionalInfo: '[name="register[shipping_address][adresszusatz]"]',
        },
        {
            name: 'shipping_address_ams',
            addressType: 'shipping_address',
            intent: 'edit',
            targetSelector: 'body',
            insertPosition: 'beforeend'
        },
        function(EAO) {
            if (!EAO) {
                return;
            }
            // Compatibility issue with DHL Wunschpaket.
            if (document.querySelector('select#kLieferadresse')) {
                document.querySelector('select#kLieferadresse').addEventListener('change', function() {
                    var $attr = document.querySelector('select#kLieferadresse').selectedOptions[0].getAttribute('data-jtlpack')
                    var addressType = 'shipping_address';
                    if ('-2' === $attr) {
                        addressType = 'packstation';
                    } else if ('-3' === $attr) {
                        addressType = 'postoffice';
                    }
                    EAO.setAddressType(addressType).catch(function(error) {
                        console.warn('Endereco could not switch the address type:', error);
                    });
                })
            }
        }
    ).then(function(EAO) {
        if (!EAO) {
            return;
        }
        window.EnderecoIntegrator.watchSubdivisionField(EAO, '[name="register[shipping_address][bundesland]"]');
    }).catch(function(error) {
        console.warn('Endereco shipping AMS initialization failed:', error);
    });

    enderecoInitPS(
        {
            salutation: 'register[shipping_address][anrede]',
            firstName: 'register[shipping_address][vorname]',
            lastName: 'register[shipping_address][nachname]',
            title: 'register[shipping_address][titel]'
        },
        {
            name: 'shipping_person'
        }
    )

    enderecoInitES(
        {
            email: '[name="register[shipping_address][email]"]'
        },
        {
            name: 'shipping_email',
            errorContainer: '#container-shipping-email-error-messages',
            errorInsertMode: 'afterbegin'
        }
    )
    {/literal}
</script>
