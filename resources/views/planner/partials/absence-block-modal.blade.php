@can('create', \App\Models\TechnicianAbsence::class)
@once
    <div data-planning-absence-root
         data-technicians-url="{{ route('planner.absences.technicians') }}"
         data-store-url="{{ route('planner.absences.store') }}">
        <button type="button" class="gc-btn-soft" data-absence-open>Bloquer une journée / demi-journée</button>
        <p class="mt-2 text-sm" data-absence-status role="status" aria-live="polite" hidden></p>

        <dialog id="planning-absence-modal" class="gc-modal-panel" aria-labelledby="planning-absence-title"
                aria-describedby="planning-absence-description" style="margin:auto;width:min(42rem,calc(100vw - 2rem));max-height:calc(100dvh - 2rem);">
            <h2 id="planning-absence-title" class="text-xl font-semibold">Bloquer des techniciens</h2>
            <p id="planning-absence-description" class="mt-2 text-sm text-slate-600">
                Une même date pour plusieurs techniciens actifs. Tout le lot est refusé en cas de conflit avec une absence ou un RDV actif.
                Aucun remplacement, aucune suppression de RDV, aucun email.
            </p>
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" data-absence-errors role="alert" tabindex="-1" hidden></div>

            <form class="mt-4 space-y-4" data-absence-form method="post" action="{{ route('planner.absences.store') }}">
                @csrf
                <fieldset class="space-y-4" data-absence-fields>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="gc-label" for="planning-absence-date">Date</label>
                            <input id="planning-absence-date" name="date" type="date" class="gc-input" value="{{ $absenceBlockDate ?? now(\App\Services\Crm\AppointmentDateTime::TIMEZONE)->toDateString() }}" required>
                        </div>
                        <div>
                            <label class="gc-label" for="planning-absence-period">Période</label>
                            <select id="planning-absence-period" name="period" class="gc-input" required>
                                <option value="day">Journée entière</option>
                                <option value="morning">Matin (avant 12 h)</option>
                                <option value="afternoon">Après-midi (à partir de 12 h)</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Horaires locaux : {{ \App\Services\Crm\AppointmentDateTime::TIMEZONE }}. Les demi-journées sont séparées à 12 h, indépendamment des horaires de travail individuels.</p>
                    <div>
                        <label class="gc-label" for="planning-absence-reason">Motif (facultatif)</label>
                        <input id="planning-absence-reason" name="reason" class="gc-input" maxlength="255" placeholder="Formation, indisponibilité…">
                    </div>
                    <div>
                        <label class="gc-label" for="planning-absence-search">Techniciens actifs</label>
                        <input id="planning-absence-search" type="search" class="gc-input" placeholder="Rechercher un technicien" data-absence-search>
                        <div class="my-2 flex flex-wrap items-center gap-3 text-sm">
                            <button type="button" class="gc-link" data-absence-select-visible>Cocher les visibles</button>
                            <button type="button" class="gc-link" data-absence-clear>Tout décocher</button>
                            <span data-absence-count aria-live="polite">0 sélectionné(s)</span>
                        </div>
                        <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2" data-absence-technicians aria-live="polite"></div>
                    </div>
                </fieldset>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
                    <button type="button" class="gc-btn-soft" data-absence-close>Fermer</button>
                    <button type="button" class="gc-btn-soft" data-absence-reload hidden>Actualiser le planning</button>
                    <button type="submit" class="gc-btn-primary" data-absence-submit disabled>Créer les blocages</button>
                </div>
            </form>
        </dialog>

        <script>
            (() => {
                const root = document.currentScript.closest('[data-planning-absence-root]');
                const find = (name) => root.querySelector(`[data-absence-${name}]`);
                const dialog = root.querySelector('dialog');
                const form = find('form');
                const submit = find('submit');
                const errors = find('errors');
                const fields = find('fields');
                const technicians = find('technicians');
                let rows = [];
                let ready = false;
                let saving = false;
                let completed = false;
                let loadRequest = null;
                let maxTechnicians = 200;
                const selected = () => rows.filter(({ checkbox }) => checkbox.checked);
                const updateSelection = () => {
                    const count = selected().length;
                    find('count').textContent = `${count} sélectionné(s) / ${maxTechnicians} maximum`;
                    submit.disabled = saving || completed || !ready || count === 0 || count > maxTechnicians;
                };
                const showErrors = (messages) => {
                    errors.replaceChildren();
                    const list = document.createElement('ul');
                    list.className = 'list-disc space-y-1 pl-4';
                    messages.forEach((message) => {
                        const item = document.createElement('li');
                        item.textContent = String(message);
                        list.append(item);
                    });
                    errors.append(list);
                    errors.hidden = false;
                    errors.focus();
                };
                const filterRows = () => {
                    const query = find('search').value.trim().toLocaleLowerCase('fr');
                    rows.forEach(({ label, element }) => { element.hidden = !label.toLocaleLowerCase('fr').includes(query); });
                };
                const loadTechnicians = async () => {
                    loadRequest?.abort();
                    const controller = new AbortController();
                    loadRequest = controller;
                    ready = false;
                    rows = [];
                    technicians.textContent = 'Chargement des techniciens actifs…';
                    updateSelection();
                    try {
                        const response = await fetch(root.dataset.techniciansUrl, {
                            headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                        });
                        if (!response.ok) throw new Error('Impossible de charger les techniciens. Fermez puis rouvrez cette fenêtre, ou reconnectez-vous.');
                        const data = await response.json();
                        if (controller.signal.aborted) return;
                        if (!Array.isArray(data.technicians)) throw new Error('Liste indisponible. Vérifiez votre session puis rouvrez cette fenêtre.');
                        maxTechnicians = data.max_technicians;
                        technicians.replaceChildren();
                        rows = data.technicians.map((technician) => {
                            const element = document.createElement('label');
                            element.className = 'flex cursor-pointer items-center gap-2 rounded-lg p-2 hover:bg-slate-50';
                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.name = 'technician_ids[]';
                            checkbox.value = technician.id;
                            checkbox.addEventListener('change', updateSelection);
                            const label = document.createElement('span');
                            label.textContent = technician.label;
                            element.append(checkbox, label);
                            technicians.append(element);
                            return { element, checkbox, label: technician.label };
                        });
                        if (!rows.length) technicians.textContent = 'Aucun technicien actif disponible.';
                        ready = true;
                        filterRows();
                    } catch (error) {
                        if (controller.signal.aborted) return;
                        technicians.textContent = 'Liste indisponible.';
                        showErrors([error.message]);
                    } finally {
                        if (!controller.signal.aborted) updateSelection();
                    }
                };
                find('open').addEventListener('click', () => {
                    if (dialog.open || saving) return;
                    errors.hidden = true;
                    completed = false;
                    fields.disabled = false;
                    submit.hidden = false;
                    find('reload').hidden = true;
                    dialog.showModal();
                    loadTechnicians();
                });
                find('close').addEventListener('click', () => { if (!saving) dialog.close(); });
                dialog.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });
                dialog.addEventListener('close', () => { loadRequest?.abort(); find('open').focus(); });
                find('reload').addEventListener('click', () => window.location.reload());
                find('search').addEventListener('input', filterRows);
                find('select-visible').addEventListener('click', () => {
                    rows.forEach(({ element, checkbox }) => { if (!element.hidden) checkbox.checked = true; });
                    updateSelection();
                });
                find('clear').addEventListener('click', () => {
                    rows.forEach(({ checkbox }) => { checkbox.checked = false; });
                    updateSelection();
                });
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (saving || completed || !ready || submit.disabled || !form.reportValidity()) return;
                    const data = new FormData(form);
                    const payload = {
                        technician_ids: selected().map(({ checkbox }) => Number(checkbox.value)),
                        date: data.get('date'), period: data.get('period'), reason: data.get('reason'),
                    };
                    saving = true;
                    fields.disabled = true;
                    find('close').disabled = true;
                    errors.hidden = true;
                    submit.textContent = 'Création en cours…';
                    updateSelection();
                    try {
                        const response = await fetch(root.dataset.storeUrl, {
                            method: 'POST', credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data.get('_token') },
                            body: JSON.stringify(payload),
                        });
                        const result = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            const messages = Object.values(result.errors || {}).flat();
                            showErrors([result.message || 'Le blocage a échoué. Vérifiez votre session puis réessayez.', ...messages]);
                            return;
                        }
                        if (response.status !== 201 || !Array.isArray(result.absences) || !result.count) throw new Error('Unconfirmed result');
                        completed = true;
                        find('status').textContent = result.message;
                        find('status').hidden = false;
                        // Show confirmation inside the modal as well, without discarding an in-progress booking.
                        technicians.textContent = result.message;
                        submit.hidden = true;
                        find('reload').hidden = false;
                        window.dispatchEvent(new CustomEvent('planning:absences-created', { detail: result }));
                    } catch {
                        showErrors(['Résultat non confirmé : vérifiez le planning avant de réessayer. Aucun RDV ni absence existante ne sera remplacé.']);
                    } finally {
                        saving = false;
                        fields.disabled = completed;
                        find('close').disabled = false;
                        submit.textContent = 'Créer les blocages';
                        updateSelection();
                    }
                });
            })();
        </script>
    </div>
@endonce
@endcan
