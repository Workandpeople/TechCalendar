const advancedKeys = ['department', 'section', 'planning', 'billing', 'lot'];
const field = (form, name) => form.elements.namedItem(name);
const value = (form, name) => String(field(form, name)?.value || '').trim();
const selectedLabel = (form, name) => Array.from(field(form, name)?.options || [])
    .find(option => String(option.value) === value(form, name))?.label || value(form, name);
const dateLabel = date => /^\d{4}-\d{2}-\d{2}$/.test(date) ? date.split('-').reverse().join('/') : date;

export function syncDossierPlanningFilter(form, browser) {
    const select = field(form, 'planning_user_ids[]');
    if (!select) return;
    const visited = value(form, 'status') === 'visited';
    const container = form.querySelector('[data-dossier-planning-filter]');
    if (container) container.hidden = !visited;
    select.disabled = !visited;
    if (!visited) for (const option of select.options) option.selected = false;
    select.dispatchEvent(new browser.CustomEvent('techcalendar:selection-sync'));
}

export function dossierFilterItems(form, statusLabels = {}) {
    const items = [];
    const add = (key, label, title = label) => items.push({ key, label, title, advanced: advancedKeys.includes(key) || key === 'dates' });
    if (value(form, 'q')) add('q', `Recherche : ${value(form, 'q')}`);
    if (value(form, 'lot')) add('lot', `Lot : ${value(form, 'lot')}`);
    if (value(form, 'status') && value(form, 'status') !== 'all') {
        add('status', `État : ${statusLabels[value(form, 'status')] || 'Catégorie sélectionnée'}`);
    }
    const companies = Array.from(field(form, 'company_ids[]')?.options || []).filter(option => option.selected);
    if (companies.length) add('companies', companies.length === 1 ? `Société : ${companies[0].label}` : `Sociétés : ${companies.length} sélectionnées`,
        `Sociétés : ${companies.map(option => option.label).join(', ')}`);
    else if (value(form, 'delegataire_id')) add('delegataire_id', `Client : ${selectedLabel(form, 'delegataire_id')}`);
    const services = Array.from(field(form, 'service_ids[]')?.options || []).filter(option => option.selected);
    if (services.length) add('services', services.length === 1 ? `Fiche : ${services[0].label}` : `Fiches : ${services.length} sélectionnées`,
        `Fiches : ${services.map(option => option.label).join(', ')}`);
    const planningUsers = Array.from(field(form, 'planning_user_ids[]')?.options || []).filter(option => option.selected);
    if (value(form, 'status') === 'visited' && planningUsers.length) {
        add('planning_users', planningUsers.length === 1 ? `Suivi planning : ${planningUsers[0].label}` : `Suivi planning : ${planningUsers.length} responsables`,
            `Suivi planning : ${planningUsers.map(option => option.label).join(', ')}`);
    }
    for (const [key, label] of [['department', 'Département'], ['section', 'Secteur'], ['planning', 'RDV'], ['billing', 'Facturation']]) {
        if (value(form, key)) add(key, `${label} : ${selectedLabel(form, key)}`);
    }
    const from = value(form, 'date_from');
    const to = value(form, 'date_to');
    if (from || to) {
        const period = from && to ? `du ${dateLabel(from)} au ${dateLabel(to)}`
            : from ? `à partir du ${dateLabel(from)}` : `jusqu’au ${dateLabel(to)}`;
        add('dates', `Date RDV : ${period}`);
    }
    return items;
}

export function clearDossierFilter(form, key, browser) {
    if (['services', 'planning_users', 'companies'].includes(key)) {
        const select = field(form, { services: 'service_ids[]', planning_users: 'planning_user_ids[]', companies: 'company_ids[]' }[key]);
        for (const option of select?.options || []) option.selected = false;
        if (key === 'services' && field(form, 'service_id')) field(form, 'service_id').value = '';
        if (key === 'companies' && field(form, 'delegataire_id')) field(form, 'delegataire_id').value = '';
        select?.dispatchEvent(new browser.CustomEvent('techcalendar:selection-sync'));
        return true;
    }
    if (key === 'dates') {
        for (const name of ['date_from', 'date_to']) if (field(form, name)) field(form, name).value = '';
        return true;
    }
    if (!['q', 'status', 'delegataire_id', ...advancedKeys].includes(key)) return false;
    if (field(form, key)) field(form, key).value = '';
    if (key === 'status') syncDossierPlanningFilter(form, browser);
    return true;
}

export function initDossierFilterPanel(form, categories, root, browser, onChange) {
    const chips = form.querySelector('[data-dossier-filter-chips]');
    const active = form.querySelector('[data-dossier-active-filters]');
    const details = form.querySelector('[data-dossier-advanced]');
    const counter = form.querySelector('[data-dossier-advanced-count]');
    if (!chips || !active || !details || !counter) return { sync() {} };
    let renderedSignature;
    const sync = ({ revealAdvanced = false } = {}) => {
        syncDossierPlanningFilter(form, browser);
        const statusLabels = { invoiced: 'Dossiers facturés' };
        categories.querySelectorAll('[data-category]').forEach(link => {
            statusLabels[link.dataset.category] = link.querySelector('.gc-dossier-category-label')?.textContent.trim() || '';
        });
        const items = dossierFilterItems(form, statusLabels);
        const advanced = items.filter(item => item.advanced).length;
        counter.textContent = `${advanced} actif${advanced > 1 ? 's' : ''}`;
        counter.hidden = advanced === 0;
        if (revealAdvanced && advanced > 0) details.open = true;
        active.hidden = items.length === 0;
        const signature = JSON.stringify(items);
        // Pending/success notifications must not remove a chip that still holds keyboard focus.
        if (signature === renderedSignature) return;
        renderedSignature = signature;
        chips.replaceChildren();
        for (const item of items) {
            const button = root.createElement('button');
            button.type = 'button';
            button.className = 'gc-dossier-filter-chip';
            button.dataset.dossierFilterRemove = item.key;
            button.title = item.title;
            button.setAttribute('aria-label', `Retirer le filtre ${item.label}`);
            const text = root.createElement('span');
            text.textContent = item.label;
            const close = root.createElement('span');
            close.textContent = '×';
            close.setAttribute('aria-hidden', 'true');
            button.append(text, close);
            chips.append(button);
        }
    };
    chips.addEventListener('click', event => {
        const button = event.target.closest('[data-dossier-filter-remove]');
        if (!button || !chips.contains(button) || !clearDossierFilter(form, button.dataset.dossierFilterRemove, browser)) return;
        sync();
        (chips.querySelector('button') || field(form, 'q'))?.focus();
        onChange();
    });
    sync({ revealAdvanced: true });
    return { sync };
}
