import Promise from 'promise-polyfill';
import merge from 'lodash.merge';
import EnderecoIntegrator from './node_modules/@endereco/js-sdk/modules/integrator';
import css from './endereco.scss';

if ('NodeList' in window && !NodeList.prototype.forEach) {
    NodeList.prototype.forEach = function (callback, thisArg) {
        thisArg = thisArg || window;
        for (var i = 0; i < this.length; i++) {
            callback.call(thisArg, this[i], i, this);
        }
    };
}

if (!window.Promise) {
    window.Promise = Promise;
}

// AMS selectors are supplied explicitly by the initiation templates. Person and
// email services still resolve their fields through these postfix maps.
EnderecoIntegrator.postfix.personServices = {
    salutation: 'anrede',
    firstName: 'vorname'
};
EnderecoIntegrator.postfix.emailServices = {
    email: 'email'
};

EnderecoIntegrator.css = css[0][1];
EnderecoIntegrator.resolvers.countryCodeWrite = function(value) {
    return new Promise(function(resolve, reject) {
        resolve(value.toUpperCase());
    });
}
EnderecoIntegrator.resolvers.countryCodeRead = function(value) {
    return new Promise(function(resolve, reject) {
        resolve(value.toUpperCase());
    });
}
EnderecoIntegrator.resolvers.salutationWrite = function(value) {
    var mapping = {
        'f': 'w',
        'm': 'm'
    } ;
    return new Promise(function(resolve, reject) {
        resolve(mapping[value]);
    });
}
EnderecoIntegrator.resolvers.salutationRead = function(value) {
    var mapping = {
        'w': 'f',
        'm': 'm'
    } ;
    return new Promise(function(resolve, reject) {
        resolve(mapping[value]);
    });
}

// Registered for forward compatibility. SDK 1.14.3 declares this filter list but
// does not consume it yet; the actual reset happens in the kLieferadresse
// listener in config.tpl.
EnderecoIntegrator.amsFilters.isAddressMetaStillRelevant.push((isStillRelevant, EAO) => {
    // The rest of the logic is only valid for shipping addresses.
    if (EAO.addressType !== 'shipping_address') {
        return isStillRelevant;
    }

    const radioButtons = document.querySelectorAll('input[type="radio"][name="kLieferadresse"]');

    const isInvalid = Array.from(radioButtons).some(radio => {
        const value = parseInt(radio.value, 10);
        return value > 0 && radio.checked;
    });

    if (isInvalid) {
        isStillRelevant = false;
    }

    return isStillRelevant;
});

// Replaces the former endereco-blur listener from config.tpl: the SDK calls this
// hook for every DOM element it binds. NOVA validates and floats labels on
// focus/change/blur, which the SDK's programmatic writes do not trigger.
EnderecoIntegrator.prepareDOMElement = function(DOMElement, addressObject) {
    if (!DOMElement || !DOMElement.dataset) {
        return;
    }
    if ('true' === DOMElement.dataset.enderecoNovaAdapter) {
        return;
    }
    DOMElement.dataset.enderecoNovaAdapter = 'true';

    DOMElement.addEventListener('endereco-blur', function(e) {
        const target = e.target;
        const dispatchNovaValidationEvents = function() {
            const previouslyFocused = document.activeElement;
            ['focus', 'change', 'blur'].forEach(function(eventName) {
                target.dispatchEvent(new CustomEvent(eventName, { bubbles: true, cancelable: true }));
            });
            if (
                previouslyFocused &&
                previouslyFocused !== document.activeElement &&
                document.contains(previouslyFocused) &&
                'function' === typeof previouslyFocused.focus
            ) {
                previouslyFocused.focus();
            }
        };

        if (addressObject && 'function' === typeof addressObject.waitForPredictionApplication) {
            addressObject.waitForPredictionApplication()
                .then(dispatchNovaValidationEvents)
                .catch(dispatchNovaValidationEvents);
        } else {
            dispatchNovaValidationEvents();
        }
    });
};

// The SDK's default check expects a populated subdivisionMappingReverse, which the
// JTL integration does not maintain: JTL state selects already carry ISO-3166-2
// codes. A select therefore counts as active when it offers such codes for the
// current country; NOVA's free-text fallback (country without configured states)
// counts as inactive so subdivisionCode is omitted from requests.
const sdkHasActiveSubscriber = EnderecoIntegrator.hasActiveSubscriber;
const ISO_3166_2_PATTERN = /^[A-Z]{2}-[A-Z0-9]{1,3}$/i;
EnderecoIntegrator.hasActiveSubscriber = function(fieldName, DOMElement, dataObject) {
    if ('subdivisionCode' !== fieldName) {
        return sdkHasActiveSubscriber(fieldName, DOMElement, dataObject);
    }

    if (DOMElement && DOMElement.dataset && 'true' === DOMElement.dataset.enderecoSubdivisionActive) {
        return true;
    }

    if (DOMElement && 'SELECT' === DOMElement.tagName) {
        const countryCode = (dataObject && dataObject.countryCode)
            ? String(dataObject.countryCode).toUpperCase()
            : '';
        return Array.from(DOMElement.options).some(function(option) {
            if (!option.value || option.disabled || !ISO_3166_2_PATTERN.test(option.value)) {
                return false;
            }
            return !countryCode || option.value.toUpperCase().indexOf(countryCode + '-') === 0;
        });
    }

    return false;
};

// NOVA's regionsToState() replaces the state element on country changes, which
// disconnects the SDK subscriber. This observer rebinds a single subscriber to
// the replacement element and keeps the address object in sync.
EnderecoIntegrator.watchSubdivisionField = function(EAO, subdivisionSelector) {
    if (!EAO || !subdivisionSelector) {
        return;
    }

    let knownElement = document.querySelector(subdivisionSelector);
    const container = knownElement ? (knownElement.closest('form') || knownElement.parentNode) : null;
    if (!container) {
        return;
    }

    const isBound = function(element) {
        return (EAO._subscribers.subdivisionCode || []).some(function(subscriber) {
            return subscriber.object === element;
        });
    };

    const rebind = function(element) {
        // SDK 1.14.3's removeSubscriber is a no-op, so stale subscribers have to
        // be cleaned up and filtered out manually.
        (EAO._subscribers.subdivisionCode || []).forEach(function(subscriber) {
            if (subscriber.object !== element && 'function' === typeof subscriber.cleanupResources) {
                subscriber.cleanupResources();
            }
        });
        EAO._subscribers.subdivisionCode = (EAO._subscribers.subdivisionCode || []).filter(function(subscriber) {
            return subscriber.object === element;
        });

        if (EAO._subscribers.subdivisionCode.length === 0) {
            const subscriber = new EnderecoIntegrator.constructors.EnderecoSubscriber(
                'subdivisionCode',
                element,
                {}
            );
            EAO.addSubscriber(subscriber);
        }

        EnderecoIntegrator.prepareDOMElement(element, EAO);

        const replacementValue = element.value || '';
        if (replacementValue !== EAO.subdivisionCode) {
            EAO._allowToNotifySubdivisionCodeSubscribers = false;
            Promise.resolve(EAO.setSubdivisionCode(replacementValue)).then(function() {
                EAO._allowToNotifySubdivisionCodeSubscribers = true;
                if (EAO.active) {
                    EAO.util.invalidateAddressMeta();
                }
            }).catch(function() {
                EAO._allowToNotifySubdivisionCodeSubscribers = true;
            });
        }
    };

    const reconcile = function() {
        const candidate = document.querySelector(subdivisionSelector);
        if (!candidate) {
            return;
        }
        if (candidate === knownElement && isBound(candidate)) {
            return;
        }
        knownElement = candidate;
        rebind(candidate);
    };

    // Covers a replacement that happened between field binding and observer start
    // (initial asynchronous country refresh).
    reconcile();

    const observer = new MutationObserver(reconcile);
    observer.observe(container, {
        childList: true,
        subtree: true
    });
};

// Coordinates the page reload after the confirmation-page review forms persisted
// their results: reload once, only after every pending update and SDK process
// settled, and only if at least one update succeeded (fail-open otherwise).
EnderecoIntegrator.jtlReviewCoordinator = {
    pendingUpdates: 0,
    anySuccess: false,
    reloadTriggered: false,
    watcherId: null,
    beginUpdate: function() {
        this.pendingUpdates++;
    },
    finishUpdate: function(wasSuccessful) {
        this.pendingUpdates--;
        if (wasSuccessful) {
            this.anySuccess = true;
        }
        this.watchForReload();
    },
    watchForReload: function() {
        const $self = this;
        if ($self.reloadTriggered || $self.watcherId) {
            return;
        }
        $self.watcherId = setInterval(function() {
            if ($self.reloadTriggered) {
                clearInterval($self.watcherId);
                $self.watcherId = null;
                return;
            }
            const queueBusy = window.EnderecoIntegrator.processQueue &&
                window.EnderecoIntegrator.processQueue.size > 0;
            const popupsOpen = window.EnderecoIntegrator.popupQueue > 0 ||
                !!document.querySelector('[endereco-popup]');
            if ($self.pendingUpdates > 0 || queueBusy || popupsOpen) {
                return;
            }
            clearInterval($self.watcherId);
            $self.watcherId = null;
            if ($self.anySuccess) {
                $self.reloadTriggered = true;
                if (window.EnderecoIntegrator.globalSpace && window.EnderecoIntegrator.globalSpace.reloadPage) {
                    window.EnderecoIntegrator.globalSpace.reloadPage();
                } else {
                    window.location.reload();
                }
            }
        }, 250);
    }
};

if (window.EnderecoIntegrator) {
    window.EnderecoIntegrator = merge(EnderecoIntegrator, window.EnderecoIntegrator);
} else {
    window.EnderecoIntegrator = EnderecoIntegrator;
}

window.EnderecoIntegrator.TypeaheadManager = class TypeaheadManager {
    static SELECTORS = {
        ZIP_INPUT: 'input[name="plz"]',
        CITY_INPUT: 'input[name="ort"]',
        SHIPPING_ZIP: '[name="register[shipping_address][plz]"]',
        SHIPPING_CITY: '[name="register[shipping_address][ort]"]'
    };

    constructor() {
        Object.values(TypeaheadManager.SELECTORS).forEach(selector => {
            const element = document.querySelector(selector);

            if (element) {
                this.removeFromElement(element);
            }
        });
    }

    removeFromElement(element) {
        if (this.isInitialized(element)) {
            this.destroy(element);
        } else {
            this.observeChanges(element);
        }
    }

    destroy(element) {

        // check for jQuery and the typeahead plugin
        if (!$?.fn?.typeahead) {
            return;
        }

        $(element).typeahead('destroy');

        // remove remains from JTL's typeahead implementation
        element.classList.remove('bg-typeahead-fix');
        element.classList.remove('typeahead');

        const container = element.closest('.typeahead-required');

        if (container) {
            container.classList.remove('typeahead-required');
        }
    }

    observeChanges(element) {
        const observer = new MutationObserver(() => {
            if (this.isInitialized(element)) {
                observer.disconnect();
                this.destroy(element);
            }
        });

        observer.observe(document.body, {
            childList: true,
            attributes: true,
            subtree: true
        });
    }

    isInitialized(element) {
        return element.classList.contains('tt-input');
    }
}

window.EnderecoIntegrator.waitUntilReady().then( function() {
    new window.EnderecoIntegrator.TypeaheadManager();
});

// config.tpl is prepended to <head> while this bundle loads async/defer at the
// end of <body>, so the config loader practically always exists already. The
// guarded scanner only covers exotic template setups.
if ('function' === typeof enderecoLoadAMSConfig) {
    enderecoLoadAMSConfig();
} else {
    let $waitForConfigTries = 0;
    const $waitForConfig = setInterval(function() {
        $waitForConfigTries++;
        if ('function' === typeof enderecoLoadAMSConfig) {
            enderecoLoadAMSConfig();
            clearInterval($waitForConfig);
        } else if ($waitForConfigTries >= 200) {
            clearInterval($waitForConfig);
        }
    }, 50);
}

EnderecoIntegrator.afterAMSActivation.push( function(EAO) {
    if (!!EAO.onSubmitUnblock) {
        EAO.onSubmitUnblock.push(function(AddressObject) {
            AddressObject.forms.forEach( function(form) {
                if (form.querySelector('[type="submit"][disabled]')) {
                    form.querySelector('[type="submit"][disabled]').removeAttribute('disabled');
                }
            });
        });
    }
});
