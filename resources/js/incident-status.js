export function incidentStatusUrl(value, base) {
    const url = new URL(value, base);
    if (url.origin !== new URL(base).origin || ! /^\/planner\/incidents\/[1-9][0-9]*\/status(?:\/resume)?$/.test(url.pathname)
        || url.search || url.hash || url.username || url.password) throw new Error('Adresse de suivi invalide.');
    return url.href;
}

export function initIncidentStatuses(root = document) {
    root.querySelectorAll('[data-incident-status]').forEach(panel => {
        if (panel.dataset.ready) return;
        panel.dataset.ready = '1';
        const form = panel.querySelector('form');
        const message = panel.querySelector('[data-status-message]');
        const current = panel.querySelector('[data-status-current]');
        const reload = panel.querySelector('[data-status-reload]');
        const resume = panel.querySelector('[data-status-resume]');
        let loading = false;
        let loaded = false;
        let saving = false;
        const request = async (raw, options = {}) => {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            try {
                const response = await fetch(incidentStatusUrl(raw, window.location.href), {
                    credentials: 'same-origin', ...options, signal: controller.signal,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                });
                if ([401, 419].includes(response.status) || response.redirected) throw new Error('Session expirée. Reconnectez-vous puis rechargez le suivi.');
                if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Réponse indisponible. Rechargez le suivi avant de réessayer.');
                const data = await response.json();
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Lecture du statut impossible.');
                return data;
            } finally { clearTimeout(timeout); }
        };
        const load = async () => {
            if (loading || saving) return;
            loading = true;
            loaded = false;
            form.hidden = true;
            resume.hidden = true;
            message.textContent = 'Vérification du statut actuel…';
            reload.disabled = true;
            try {
                const data = await request(panel.dataset.contextUrl);
                current.textContent = data.current_label || '';
                message.textContent = data.message || '';
                const select = form.elements.namedItem('target_status');
                select.replaceChildren(new Option('Choisir explicitement un statut', ''));
                for (const row of data.statuses || []) select.add(new Option(row.label, row.value));
                form.elements.namedItem('context').value = data.context || '';
                form.elements.namedItem('planning_status').value = data.planning_status || 'pending';
                form.elements.namedItem('note').value = data.note || '';
                form.hidden = !data.context;
                resume.hidden = !data.resume_url;
                loaded = true;
            } catch (error) {
                message.textContent = error.name === 'AbortError' ? 'Lecture trop longue. Réessayez sans modifier le dossier.' : error.message;
            } finally { loading = false; reload.disabled = false; }
        };
        panel.addEventListener('toggle', () => { if (panel.open && !loaded) load(); });
        reload.addEventListener('click', load);
        const submit = async (resuming) => {
            if (saving || loading || (!resuming && !form.reportValidity())) return;
            saving = true;
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            resume.disabled = true;
            reload.disabled = true;
            message.textContent = resuming ? 'Vérification de la même demande…' : 'Enregistrement de la décision…';
            try {
                const data = await request(resuming ? panel.dataset.resumeUrl : form.action,
                    { method: 'POST', ...(resuming ? {} : { body: new FormData(form) }) });
                message.textContent = data.message;
                if (data.state === 'confirmed') { window.location.reload(); return; }
                form.hidden = true;
                resume.hidden = data.state === 'rejected';
                loaded = false;
            } catch (error) {
                message.textContent = error.name === 'AbortError'
                    ? 'Réponse interrompue. Rechargez le suivi pour vérifier la confirmation avant de renvoyer une décision.' : error.message;
                loaded = false;
            } finally { saving = false; button.disabled = false; resume.disabled = false; reload.disabled = false; }
        };
        form.addEventListener('submit', event => { event.preventDefault(); submit(false); });
        resume.addEventListener('click', () => submit(true));
    });
}
