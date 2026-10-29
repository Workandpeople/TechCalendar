export function createAddressSearch(browser, { pending, success, failure }) {
    let generation = 0, controller, timer;
    const cancel = () => { generation++; controller?.abort(); browser.clearTimeout(timer); };
    const search = url => {
        cancel();
        const current = generation;
        pending();
        timer = browser.setTimeout(async () => {
            const request = new AbortController();
            controller = request;
            const timeout = browser.setTimeout(() => request.abort(), 12000);
            try {
                const response = await browser.fetch(url.href, { credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json' }, signal: request.signal });
                if ([401, 419].includes(response.status)) throw new Error('Votre session a expiré. Rechargez la fiche.');
                if (response.status === 403) throw new Error('Vous n’avez plus accès à la modification de cette adresse.');
                const data = await response.json();
                if (current !== generation) return;
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || 'Recherche d’adresse ou de commune indisponible. Réessayez dans un instant.');
                if (!Array.isArray(data.suggestions)) throw new Error('Réponse de recherche invalide.');
                success(data.suggestions.filter(item => typeof item.id === 'string' && typeof item.label === 'string'));
            } catch (error) {
                if (current === generation) failure(error.name === 'AbortError' ? 'La recherche a expiré. Réessayez.'
                    : ['SyntaxError', 'TypeError'].includes(error.name) ? 'Recherche d’adresse ou de commune indisponible. Réessayez dans un instant.' : error.message);
            } finally { browser.clearTimeout(timeout); }
        }, 300);
    };
    return { search, cancel };
}

export function initDossierAddress(root = document, browser = window) {
    root.querySelectorAll('[data-dossier-address]').forEach(form => {
        const input = form.elements.address_query;
        const identifier = form.elements.mapbox_id;
        const status = form.querySelector('[data-address-status]');
        const list = form.querySelector('[role="listbox"]');
        const dialog = form.closest('dialog');
        let suggestions = [], highlighted = -1, composing = false;
        let selectedLabel = input.value;
        const hide = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        };
        const highlight = index => {
            highlighted = index;
            Array.from(list.children).forEach((item, i) => {
                item.setAttribute('aria-selected', String(i === index));
                if (i === index) { input.setAttribute('aria-activedescendant', item.id); item.scrollIntoView({ block: 'nearest' }); }
            });
        };
        const choose = index => {
            const item = suggestions[index];
            if (!item) return;
            loader.cancel();
            input.value = selectedLabel = item.label;
            identifier.value = item.id;
            hide();
            status.textContent = ['place', 'postcode', 'locality'].includes(item.feature_type)
                ? 'Commune sélectionnée : position approximative. Cliquez sur Valider.'
                : 'Adresse sélectionnée. Cliquez sur Valider pour recalculer la position.';
            input.focus({ preventScroll: true });
        };
        const loader = createAddressSearch(browser, {
            pending() { hide(); status.textContent = 'Recherche des adresses et communes…'; },
            success(items) {
                suggestions = items;
                list.replaceChildren(...items.map((item, index) => {
                    const option = root.createElement('li');
                    option.id = `${list.id}-${index}`;
                    option.setAttribute('role', 'option');
                    option.textContent = item.label + (['place', 'postcode', 'locality'].includes(item.feature_type) ? ' · Position approximative' : '');
                    option.addEventListener('pointerdown', event => { if (event.pointerType !== 'touch') event.preventDefault(); });
                    option.addEventListener('click', () => choose(index));
                    return option;
                }));
                list.hidden = items.length === 0;
                input.setAttribute('aria-expanded', String(items.length > 0));
                highlighted = -1;
                status.textContent = items.length ? `${items.length} adresse(s) ou commune(s) proposée(s).` : 'Aucun résultat. Précisez l’adresse ou le code postal et le nom de la commune.';
            },
            failure(message) { hide(); status.textContent = message; },
        });
        const changed = () => {
            loader.cancel(); hide();
            identifier.value = '';
            selectedLabel = '';
            const query = input.value.trim();
            if (composing) return;
            if (query.length < 3 || query.length > 255) {
                status.textContent = query.length > 255 ? 'Précisez une adresse plus courte pour rechercher avec Mapbox.' : 'Saisissez au moins 3 caractères pour rechercher une adresse.';
                return;
            }
            const url = new URL(form.dataset.suggestionsUrl, browser.location.href);
            url.searchParams.set('q', query);
            loader.search(url);
        };
        input.addEventListener('input', changed);
        input.addEventListener('change', () => { if (input.value !== selectedLabel) identifier.value = ''; });
        input.addEventListener('compositionstart', () => { composing = true; loader.cancel(); hide(); });
        input.addEventListener('compositionend', () => { composing = false; changed(); });
        input.addEventListener('keydown', event => {
            if (event.isComposing || list.hidden) return;
            if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) event.preventDefault();
            if (event.key === 'ArrowDown') highlight(Math.min(suggestions.length - 1, highlighted + 1));
            if (event.key === 'ArrowUp') highlight(Math.max(0, highlighted - 1));
            if (event.key === 'Enter') choose(highlighted < 0 ? 0 : highlighted);
            if (event.key === 'Escape') { event.stopImmediatePropagation(); loader.cancel(); hide(); }
            if (event.key === 'Tab') { loader.cancel(); hide(); }
        });
        form.addEventListener('focusout', event => { if (!form.contains(event.relatedTarget)) { loader.cancel(); hide(); } });
        form.addEventListener('submit', () => {
            if (input.value !== selectedLabel) identifier.value = '';
            loader.cancel(); hide();
            form.querySelector('[type="submit"]').disabled = true;
            status.textContent = 'Validation et recalcul de la position…';
        });
        dialog?.addEventListener('close', () => { loader.cancel(); hide(); });
        browser.addEventListener('pagehide', loader.cancel);
    });
}

export function initCreationAddress(root = document, browser = window) {
    root.querySelectorAll('[data-creation-address]').forEach(section => {
        const form = section.closest('form');
        const address = form.elements.address, city = form.elements.city, postal = form.elements.postal_code;
        const identifier = form.elements.mapbox_id, precision = form.elements.address_precision;
        const fallback = section.querySelector('[data-address-fallback]');
        const locality = section.querySelector('[data-address-locality]');
        const warning = section.querySelector('[data-address-approximate]');
        const status = section.querySelector('[data-creation-address-status]');
        const addressList = section.querySelector('#creation-address-options'), cityList = section.querySelector('#creation-city-options');
        let cityMode = precision.value === 'city', suggestions = [], activeInput = address, activeList = addressList, index = -1;
        const hide = () => {
            addressList.hidden = cityList.hidden = true;
            [address, city].forEach(input => { input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); });
        };
        const syncMode = () => {
            precision.value = cityMode ? 'city' : 'address';
            warning.hidden = !cityMode;
            locality.hidden = !cityMode && !city.value;
            city.disabled = postal.disabled = locality.hidden;
            postal.required = cityMode;
            address.required = !cityMode;
            fallback.setAttribute('aria-expanded', String(cityMode));
            fallback.textContent = cityMode ? 'Rechercher une adresse précise' : 'Adresse non trouvée';
        };
        const choose = i => {
            const item = suggestions[i];
            if (!item) return;
            loader.cancel(); hide();
            cityMode = item.feature_type !== 'address';
            if (!cityMode) address.value = item.street_address || item.label;
            city.value = item.city || '';
            postal.value = item.postal_code || '';
            identifier.value = item.id;
            address.setCustomValidity(''); city.setCustomValidity('');
            syncMode();
            status.textContent = cityMode ? 'Commune sélectionnée. Position approximative, adresse exacte à confirmer.' : 'Adresse sélectionnée avec Mapbox.';
        };
        const loader = createAddressSearch(browser, {
            pending() { hide(); status.textContent = cityMode ? 'Recherche de la commune…' : 'Recherche de l’adresse…'; },
            failure(message) { hide(); status.textContent = message; },
            success(items) {
                suggestions = items; index = -1;
                activeList.replaceChildren(...items.map((item, i) => {
                    const option = root.createElement('li'); option.id = `${activeList.id}-${i}`;
                    option.setAttribute('role', 'option'); option.textContent = item.label + (item.feature_type === 'address' ? '' : ' · Commune / code postal');
                    option.addEventListener('pointerdown', event => { if (event.pointerType !== 'touch') event.preventDefault(); });
                    option.addEventListener('click', () => choose(i));
                    return option;
                }));
                activeList.hidden = items.length === 0;
                activeInput.setAttribute('aria-expanded', String(items.length > 0));
                status.textContent = items.length ? 'Choisissez une suggestion.' : 'Aucun résultat. Utilisez « Adresse non trouvée », puis précisez le code postal et la ville.';
            },
        });
        const search = mode => {
            loader.cancel(); hide(); identifier.value = '';
            activeInput = mode === 'city' ? city : address;
            activeList = mode === 'city' ? cityList : addressList;
            const query = mode === 'city' ? `${postal.value.trim()} ${city.value.trim()}`.trim() : address.value.trim();
            if (query.length < 3 || query.length > 255) return;
            const url = new URL(section.dataset.suggestionsUrl, browser.location.href);
            url.searchParams.set('q', query); url.searchParams.set('mode', mode);
            loader.search(url);
        };
        address.addEventListener('input', () => { address.setCustomValidity(''); search('address'); });
        city.addEventListener('input', () => { city.setCustomValidity(''); search('city'); });
        postal.addEventListener('input', () => search('city'));
        fallback.addEventListener('click', () => {
            loader.cancel(); hide(); identifier.value = ''; cityMode = !cityMode; syncMode();
            address.setCustomValidity(''); city.setCustomValidity('');
            status.textContent = cityMode ? 'Renseignez le code postal et la ville pour localiser la commune.' : 'Recherchez une adresse précise avec Mapbox.';
            (cityMode ? postal : address).focus();
        });
        [address, city].forEach(input => input.addEventListener('keydown', event => {
            if (event.isComposing || activeList.hidden) return;
            if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) event.preventDefault();
            if (event.key === 'Escape' || event.key === 'Tab') { loader.cancel(); hide(); return; }
            if (event.key === 'ArrowDown') index = Math.min(suggestions.length - 1, index + 1);
            if (event.key === 'ArrowUp') index = Math.max(0, index - 1);
            if (event.key === 'Enter') { choose(index < 0 ? 0 : index); return; }
            Array.from(activeList.children).forEach((option, i) => {
                option.setAttribute('aria-selected', String(i === index));
                if (i === index) { input.setAttribute('aria-activedescendant', option.id); option.scrollIntoView({ block: 'nearest' }); }
            });
        }));
        form.addEventListener('submit', event => {
            if (!cityMode && city.disabled) {
                event.preventDefault(); address.setCustomValidity('Sélectionnez une adresse ou utilisez « Adresse non trouvée ».'); address.reportValidity();
            }
            loader.cancel(); hide();
        });
        section.addEventListener('focusout', event => { if (!section.contains(event.relatedTarget)) { loader.cancel(); hide(); } });
        browser.addEventListener('pagehide', loader.cancel);
        syncMode();
    });
}
