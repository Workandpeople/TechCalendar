import { filterSelectItems, selectMenuItems, selectMenuPosition } from './select-menu.js';

export function multiSelectSummary(select) {
    const selected = Array.from(select.options).filter(option => option.selected);
    return { count: selected.length, title: selected.map(option => option.label).join(', '),
        label: selected.length === 0 ? (select.dataset.emptyLabel || 'Tous') : selected.length === 1 ? selected[0].label : `${selected.length} ${select.dataset.selectedLabel || 'fiches sélectionnées'}` };
}

export function initMultiSelects(root = document, browser = window) {
    root.querySelectorAll('select[multiple][data-multi-select]').forEach(select => {
        if (select.dataset.multiSelectReady) return;
        select.dataset.multiSelectReady = 'true';
        const wrapper = root.createElement('div');
        wrapper.className = 'gc-multi-select';
        const button = root.createElement('button');
        button.type = 'button';
        button.className = 'gc-input gc-multi-select-trigger';
        button.id = `${select.id}-trigger`;
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-expanded', 'false');
        const required = select.required;
        if (required) button.setAttribute('aria-required', 'true');
        const describedBy = select.getAttribute('aria-describedby');
        if (describedBy) button.setAttribute('aria-describedby', describedBy);
        const labels = Array.from(select.labels || []);
        const label = labels.map(label => label.textContent.trim()).join(' ') || 'Fiches techniques';
        select.before(wrapper);
        wrapper.append(button, select);
        let error;
        const clearError = () => {
            button.removeAttribute('aria-invalid');
            if (error) error.hidden = true;
        };
        for (const label of labels) label.htmlFor = button.id;
        let menu, search, options, count, clear;
        const close = (restore = false) => {
            menu?.remove(); menu = null;
            button.setAttribute('aria-expanded', 'false');
            button.removeAttribute('aria-controls');
            if (restore) button.focus({ preventScroll: true });
        };
        const sync = () => {
            const summary = multiSelectSummary(select);
            if (summary.count > 0 || select.disabled) clearError();
            button.textContent = summary.label;
            button.title = summary.title || summary.label;
            button.setAttribute('aria-label', `${label} : ${summary.label}`);
            button.disabled = select.disabled;
            if (select.disabled) close();
            if (menu) {
                count.textContent = summary.count ? `${summary.count} ${select.dataset.selectedLabel || 'fiche(s) sélectionnée(s)'}` : (select.dataset.emptyLabel || 'Toutes les fiches');
                clear.disabled = summary.count === 0;
                options.querySelectorAll('input').forEach(input => { input.checked = select.options[Number(input.dataset.index)].selected; });
            }
        };
        const notify = () => {
            sync();
            select.dispatchEvent(new browser.Event('input', { bubbles: true }));
            select.dispatchEvent(new browser.Event('change', { bubbles: true }));
        };
        const place = () => {
            const anchor = button.getBoundingClientRect();
            if (!select.isConnected || select.disabled || !anchor.width || !anchor.height) return close();
            const position = selectMenuPosition(anchor, { width: root.documentElement.clientWidth, height: browser.innerHeight });
            if (position.maxHeight < 100) return close();
            Object.assign(menu.style, { width: `${position.width}px`, left: `${position.left}px`, maxHeight: `${position.maxHeight}px`,
                top: position.top === null ? 'auto' : `${position.top}px`, bottom: position.bottom === null ? 'auto' : `${position.bottom}px` });
        };
        const render = () => {
            options.replaceChildren();
            const items = filterSelectItems(selectMenuItems(select), search.value);
            for (const item of items) {
                const row = root.createElement('label');
                row.className = 'gc-multi-select-option';
                const input = root.createElement('input');
                input.type = 'checkbox';
                input.dataset.index = String(item.index);
                input.checked = select.options[item.index].selected;
                input.disabled = item.disabled;
                input.addEventListener('change', () => {
                    if (select.disabled || select.options[item.index]?.disabled) return sync();
                    select.options[item.index].selected = input.checked; notify();
                });
                const text = root.createElement('span');
                text.textContent = item.group ? `${item.group} · ${item.label}` : item.label;
                row.append(input, text);
                options.append(row);
            }
            if (!items.length) {
                const empty = root.createElement('p');
                empty.className = 'gc-select-menu-empty';
                empty.setAttribute('role', 'status');
                empty.textContent = select.dataset.noResultsLabel || 'Aucune fiche ne correspond à la recherche.';
                options.append(empty);
            }
            sync(); place();
        };
        const open = () => {
            if (select.disabled) return;
            menu = root.createElement('div');
            menu.className = 'gc-select-menu gc-multi-select-popup';
            menu.id = `${select.id}-choices`;
            menu.setAttribute('role', 'dialog');
            menu.setAttribute('aria-label', label);
            search = root.createElement('input');
            search.type = 'search'; search.className = 'gc-input gc-select-search';
            search.placeholder = select.dataset.searchLabel || 'Rechercher une fiche…'; search.autocomplete = 'off';
            search.setAttribute('aria-label', select.dataset.searchLabel || 'Rechercher une fiche technique');
            search.addEventListener('input', render);
            options = root.createElement('div');
            options.className = 'gc-multi-select-options';
            options.setAttribute('role', 'group'); options.setAttribute('aria-label', select.dataset.optionsLabel || 'Fiches disponibles');
            const footer = root.createElement('div');
            footer.className = 'gc-multi-select-footer';
            count = root.createElement('span'); count.setAttribute('role', 'status'); count.setAttribute('aria-live', 'polite');
            clear = root.createElement('button'); clear.type = 'button'; clear.textContent = 'Tout effacer';
            clear.addEventListener('click', () => { for (const option of select.options) option.selected = false; notify(); search.focus(); });
            const done = root.createElement('button'); done.type = 'button'; done.textContent = 'Fermer';
            done.addEventListener('click', () => close(true));
            footer.append(count, clear, done);
            menu.append(search, options, footer);
            menu.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
                if (event.target === search && event.key === 'ArrowDown') {
                    event.preventDefault(); options.querySelector('input:not(:disabled)')?.focus();
                }
            });
            (select.closest('dialog[open]') || root.body).append(menu);
            button.setAttribute('aria-expanded', 'true'); button.setAttribute('aria-controls', menu.id);
            render();
            if (menu) search.focus({ preventScroll: true });
        };
        button.addEventListener('click', () => menu ? close() : open());
        button.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); if (!menu) open(); }
        });
        select.addEventListener('change', sync);
        select.addEventListener('techcalendar:selection-sync', sync);
        select.form?.addEventListener('reset', () => { close(); clearError(); browser.setTimeout(sync, 0); });
        if (required) {
            // The visible trigger owns validation once the native select is hidden.
            select.required = false;
            select.form?.addEventListener('submit', event => {
                if (select.disabled || multiSelectSummary(select).count > 0) return;
                event.preventDefault();
                if (!error) {
                    error = root.createElement('p');
                    error.id = `${select.id}-error`;
                    error.className = 'mt-2 text-sm text-rose-800';
                    error.setAttribute('role', 'alert');
                    wrapper.append(error);
                    button.setAttribute('aria-describedby', [describedBy, error.id].filter(Boolean).join(' '));
                }
                error.textContent = select.dataset.requiredMessage || 'Sélectionnez au moins une option.';
                error.hidden = false;
                button.setAttribute('aria-invalid', 'true');
                button.focus({ preventScroll: true });
                if (!menu) open();
            });
        }
        root.addEventListener('pointerdown', event => { if (menu && event.target !== button && !menu.contains(event.target)) close(); }, true);
        root.addEventListener('focusin', event => { if (menu && event.target !== button && !menu.contains(event.target)) close(); });
        root.addEventListener('scroll', event => { if (menu && !menu.contains(event.target)) close(); }, true);
        root.addEventListener('close', () => close(), true);
        browser.addEventListener('resize', () => close());
        browser.addEventListener('pagehide', () => close());
        browser.visualViewport?.addEventListener('resize', () => close());
        select.hidden = true;
        sync();
    });
}
