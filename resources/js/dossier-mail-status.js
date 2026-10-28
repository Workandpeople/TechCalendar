export function mailStatusTone(status) {
    if (status === 'sent') return 'bg-emerald-100 text-emerald-900';
    if (status === 'queued' || status === 'sending') return 'bg-amber-100 text-amber-900';
    if (status === 'failed' || status === 'blocked') return 'bg-rose-100 text-rose-900';
    return 'bg-slate-100 text-slate-700';
}

export function initDossierMailStatus(root = document, browser = window) {
    root.querySelectorAll('[data-mail-delivery-status]').forEach(panel => {
        if (panel.dataset.mailStatusReady) return;
        panel.dataset.mailStatusReady = 'true';
        const list = panel.querySelector('[data-mail-status-list]');
        const refresh = panel.querySelector('[data-mail-status-refresh]');
        const feedback = panel.querySelector('[data-mail-status-feedback]');
        let pending = panel.dataset.pending === 'true', visible = !browser.IntersectionObserver;
        let timer, controller, polls = 0, paused = false;
        const node = (parent, tag, className, value) => {
            const element = root.createElement(tag);
            element.className = className; element.textContent = value;
            parent.append(element); return element;
        };
        const render = messages => {
            const rows = messages.map(message => {
                const row = root.createElement('li'); row.className = 'py-3 first:pt-0 last:pb-0';
                const heading = root.createElement('div'); heading.className = 'flex flex-wrap items-center justify-between gap-2'; row.append(heading);
                node(heading, 'span', `rounded-md px-2 py-1 text-xs font-semibold ${mailStatusTone(message.status)}`, message.label);
                node(heading, 'time', 'text-xs text-slate-500', message.at || '');
                node(row, 'p', 'mt-1 break-words text-sm font-medium text-slate-800', message.subject || '');
                node(row, 'p', 'break-words text-xs text-slate-500', `À : ${message.recipient || ''}`);
                node(row, 'p', 'mt-1 text-xs text-slate-600', message.detail || '');
                return row;
            });
            if (!rows.length) {
                const empty = root.createElement('li'); empty.className = 'text-xs text-slate-500';
                empty.textContent = panel.dataset.emptyLabel || 'Aucune demande d’envoi pour ce dossier.'; rows.push(empty);
            }
            list.replaceChildren(...rows);
        };
        const canPoll = () => !!panel.dataset.statusUrl && pending && visible && !root.hidden && !paused && polls < 60;
        const schedule = (delay = 8000) => {
            browser.clearTimeout(timer);
            if (canPoll() && !controller) timer = browser.setTimeout(() => update(), delay);
        };
        const pause = () => {
            browser.clearTimeout(timer);
            controller?.abort();
        };
        const update = async (manual = false) => {
            if (!panel.dataset.statusUrl || controller || root.hidden || paused || (!manual && !canPoll())) return;
            browser.clearTimeout(timer);
            if (manual) polls = 0;
            polls++;
            const current = new AbortController(); controller = current;
            const timeout = browser.setTimeout(() => current.abort(), 10000);
            refresh.disabled = true;
            let delay = 8000;
            try {
                const response = await browser.fetch(panel.dataset.statusUrl, { credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json' }, signal: current.signal });
                if (controller !== current) return;
                if ([401, 403, 404].includes(response.status)) pending = false;
                if (response.status === 429) delay = 30000;
                if (!response.ok) throw new Error('Le suivi est temporairement indisponible. Aucun nouvel email n’a été envoyé.');
                const data = await response.json();
                if (controller !== current) return;
                if (!Array.isArray(data.messages)) throw new Error('Réponse du suivi invalide.');
                render(data.messages);
                pending = data.messages.some(message => message.pending === true);
                panel.dataset.pending = String(pending);
                feedback.textContent = pending ? 'Actualisation automatique pendant le traitement.' : 'Suivi à jour.';
            } catch (error) {
                if (controller === current && error.name !== 'AbortError') feedback.textContent = error.message;
            } finally {
                browser.clearTimeout(timeout);
                if (controller !== current) return;
                controller = null; refresh.disabled = false;
                if (pending && polls >= 60) feedback.textContent = 'Le traitement se poursuit. Utilisez « Actualiser le suivi » pour vérifier le résultat.';
                schedule(delay);
            }
        };
        panel.addEventListener('cofrac:mail-status', event => {
            pause(); controller = null; polls = 0;
            panel.dataset.statusUrl = event.detail?.url || '';
            const messages = event.detail?.messages;
            pending = !!panel.dataset.statusUrl && (!Array.isArray(messages) || messages.some(message => message.pending === true));
            panel.dataset.pending = String(pending);
            render(Array.isArray(messages) ? messages : []);
            feedback.textContent = pending ? 'Vérification du suivi…' : '';
            refresh.disabled = !panel.dataset.statusUrl;
            schedule(0);
        });
        refresh.disabled = !panel.dataset.statusUrl;
        refresh.addEventListener('click', () => update(true));
        root.addEventListener('visibilitychange', () => root.hidden ? pause() : schedule(0));
        browser.addEventListener('pagehide', () => { paused = true; pause(); });
        browser.addEventListener('pageshow', () => { paused = false; schedule(0); });
        if (browser.IntersectionObserver) {
            const observer = new browser.IntersectionObserver(entries => {
                visible = entries.some(entry => entry.isIntersecting);
                if (visible) schedule(0); else pause();
            }, { rootMargin: '100px' });
            observer.observe(panel);
        }
        schedule();
    });
}
