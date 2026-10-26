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
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || 'Recherche Mapbox indisponible. Réessayez dans un instant.');
                if (!Array.isArray(data.suggestions)) throw new Error('Réponse Mapbox invalide.');
                success(data.suggestions.filter(item => typeof item.id === 'string' && typeof item.label === 'string'));
            } catch (error) {
                if (current === generation) failure(error.name === 'AbortError' ? 'La recherche a expiré. Réessayez.'
                    : ['SyntaxError', 'TypeError'].includes(error.name) ? 'Recherche Mapbox indisponible. Réessayez dans un instant.' : error.message);
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
            status.textContent = 'Adresse sélectionnée. Cliquez sur Valider pour recalculer la position.';
            input.focus({ preventScroll: true });
        };
        const loader = createAddressSearch(browser, {
            pending() { hide(); status.textContent = 'Recherche des adresses…'; },
            success(items) {
                suggestions = items;
                list.replaceChildren(...items.map((item, index) => {
                    const option = root.createElement('li');
                    option.id = `${list.id}-${index}`;
                    option.setAttribute('role', 'option');
                    option.textContent = item.label;
                    option.addEventListener('pointerdown', event => { if (event.pointerType !== 'touch') event.preventDefault(); });
                    option.addEventListener('click', () => choose(index));
                    return option;
                }));
                list.hidden = items.length === 0;
                input.setAttribute('aria-expanded', String(items.length > 0));
                highlighted = -1;
                status.textContent = items.length ? `${items.length} adresse(s) proposée(s).` : 'Aucune adresse trouvée. Précisez le numéro, la voie et la commune.';
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
            status.textContent = 'Validation et recalcul de la position avec Mapbox…';
        });
        dialog?.addEventListener('close', () => { loader.cancel(); hide(); });
        browser.addEventListener('pagehide', loader.cancel);
    });
}
