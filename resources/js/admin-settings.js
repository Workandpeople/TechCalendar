const normalize = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

export function settingMatches(row, { search = '', source = '', group = '' }) {
    const text = normalize(row.search);
    return (!group || group === row.group)
        && (!source || (source === 'missing' ? row.missing === 'true'
            : row.source === source && (source !== 'fallback' || row.missing !== 'true')))
        && normalize(search).split(/\s+/).every(term => text.includes(term));
}

export function changedSettings(inputs) {
    return inputs.filter(input => String(input.value) !== String(input.dataset.savedValue ?? '')
        && (input.type !== 'password' || input.value !== ''));
}

export function initAdminSettings(root = document, browser = window) {
    const workspace = root.querySelector('[data-settings-workspace]');
    if (!workspace || workspace.dataset.ready) return;
    workspace.dataset.ready = 'true';
    const form = workspace.querySelector('[data-settings-form]');
    const search = workspace.querySelector('[data-settings-search]');
    const group = workspace.querySelector('[data-settings-group]');
    const source = workspace.querySelector('[data-settings-source]');
    const sections = [...workspace.querySelectorAll('[data-setting-section]')];
    const inputs = [...form.querySelectorAll('[data-setting-input]')];
    const count = workspace.querySelector('[data-settings-count]');
    const dirty = workspace.querySelector('[data-settings-dirty]');
    const diagnostics = workspace.querySelector('[data-diagnostics]');
    const cards = [...diagnostics.querySelectorAll('[data-diagnostic]')];
    const allButton = diagnostics.querySelector('[data-diagnostics-all]');
    const buttons = [allButton, ...cards.map(card => card.querySelector('[data-diagnostic-run]'))];
    let busy = false;

    const filter = () => {
        let visible = 0;
        for (const section of sections) {
            let groupVisible = 0;
            for (const row of section.querySelectorAll('[data-setting-row]')) {
                row.hidden = !settingMatches({ ...row.dataset, group: section.dataset.group }, {
                    search: search.value, group: group.value, source: source.value,
                });
                if (!row.hidden) groupVisible++;
            }
            section.hidden = groupVisible === 0;
            const sectionCount = section.querySelector?.('[data-setting-section-count]');
            if (sectionCount) sectionCount.textContent = `${groupVisible} / ${section.querySelectorAll('[data-setting-row]').length}`;
            visible += groupVisible;
        }
        count.textContent = `${visible} variable${visible === 1 ? '' : 's'} affichée${visible === 1 ? '' : 's'}`;
        workspace.querySelector('[data-settings-empty]').hidden = visible !== 0;
    };
    const resetFilters = () => { search.value = ''; group.value = ''; source.value = ''; filter(); };
    search.addEventListener('input', filter);
    group.addEventListener('change', filter);
    source.addEventListener('change', filter);
    workspace.querySelector('[data-settings-reset]').addEventListener('click', resetFilters);
    form.addEventListener('invalid', resetFilters, true);
    const updateDirty = () => {
        const amount = changedSettings(inputs).length;
        dirty.hidden = amount === 0;
        dirty.textContent = `${amount} modification${amount > 1 ? 's' : ''} non enregistrée${amount > 1 ? 's' : ''}`;
        buttons.forEach(button => {
            button.disabled = busy || amount > 0;
            button.title = amount ? 'Enregistrez les changements avant de tester.' : '';
        });
    };
    inputs.forEach(input => {
        input.addEventListener('input', updateDirty);
        input.addEventListener('change', updateDirty);
    });
    form.addEventListener('submit', event => {
        if (!changedSettings(inputs).length) {
            event.preventDefault();
            dirty.hidden = false;
            dirty.textContent = 'Aucune modification à enregistrer.';
        }
    });
    // Only changed fields become overrides; filtering never removes unsaved fields from the payload.
    form.addEventListener('formdata', event => {
        const changed = new Set(changedSettings(inputs));
        inputs.forEach(input => { if (!changed.has(input)) event.formData.delete(input.name); });
    });

    const run = async card => {
        const result = card.querySelector('[data-diagnostic-result]');
        result.dataset.state = 'running';
        result.textContent = 'Test en cours…';
        const controller = new AbortController();
        const timer = browser.setTimeout(() => controller.abort(), 30000);
        const payload = { check: card.dataset.diagnostic };
        const sender = card.querySelector('[data-diagnostic-mail-sender]');
        if (sender?.value) payload.mail_sender_id = Number(sender.value);
        try {
            const response = await browser.fetch(diagnostics.dataset.url, {
                method: 'POST', credentials: 'same-origin', redirect: 'error', signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': root.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify(payload),
            });
            if (!response.ok) {
                result.dataset.state = 'error';
                result.textContent = response.status === 429 ? 'Trop de tests rapprochés. Réessayez dans une minute.'
                    : [401, 403, 419].includes(response.status) ? 'Session expirée ou accès refusé. Rechargez la page.'
                        : 'Le diagnostic n’a pas pu être exécuté. Réessayez.';
                return ![401, 403, 419, 429].includes(response.status);
            }
            const data = await response.json();
            if (!['ok', 'warning', 'error'].includes(data.status) || typeof data.message !== 'string'
                || data.key !== card.dataset.diagnostic) throw new Error('Invalid result');
            result.dataset.state = data.status;
            const duration = Number.isFinite(data.duration_ms) ? ` · ${data.duration_ms} ms` : '';
            result.textContent = `${data.message}${duration} · ${new Date().toLocaleTimeString('fr-FR')}`;
            return true;
        } catch {
            result.dataset.state = 'error';
            result.textContent = 'Connexion interrompue ou délai dépassé. Aucun envoi demandé.';
            return false;
        } finally {
            browser.clearTimeout(timer);
        }
    };
    const runCards = async selected => {
        if (busy || changedSettings(inputs).length) return;
        busy = true; updateDirty();
        diagnostics.querySelectorAll('select').forEach(select => { select.disabled = true; });
        try {
            for (const card of selected) { if (!await run(card)) break; }
        } finally {
            busy = false; updateDirty();
            diagnostics.querySelectorAll('select').forEach(select => { select.disabled = false; });
        }
    };
    buttons.forEach(button => { button.hidden = false; });
    cards.forEach(card => {
        card.querySelector('[data-diagnostic-run]').addEventListener('click', () => runCards([card]));
        card.querySelector('[data-diagnostic-mail-sender]')?.addEventListener('change', () => {
            const result = card.querySelector('[data-diagnostic-result]');
            result.dataset.state = 'idle'; result.textContent = 'Non testé pour cet expéditeur';
        });
    });
    allButton.addEventListener('click', () => runCards(cards));
    updateDirty(); filter();
}
