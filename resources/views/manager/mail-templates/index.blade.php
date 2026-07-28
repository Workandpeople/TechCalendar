<x-layouts.app>
    @php
        $defaultMarkdown = <<<'MD'
# Bonjour {{ client_name }},

Votre rendez-vous pour **{{ service_label }}** est prévu le **{{ appointment_date }} à {{ appointment_time }}**.

**Adresse :** {{ address }}

{{ comment }}

Merci,
{{ app_name }}
MD;

        $variableLabels = [
            'app_name' => 'Nom de l’application',
            'appointment_id' => 'Référence du RDV',
            'client_name' => 'Nom du client',
            'customer_name' => 'Nom du client',
            'customer_first_name' => 'Prénom du client',
            'customer_last_name' => 'Nom de famille du client',
            'customer_phone' => 'Téléphone du client',
            'customer_email' => 'Email du client',
            'company_name' => 'Raison sociale',
            'site_name' => 'Nom du site',
            'technician_name' => 'Nom du technicien',
            'technician_email' => 'Email du technicien',
            'service_label' => 'Prestation',
            'service_type' => 'Type de prestation',
            'service_name' => 'Nom de la prestation',
            'appointment_date' => 'Date du RDV',
            'appointment_time' => 'Heure du RDV',
            'appointment_end_time' => 'Heure de fin',
            'appointment_duration' => 'Durée du RDV',
            'address' => 'Adresse du RDV',
            'comment' => 'Commentaire',
            'manager_name' => 'Créé par',
        ];
    @endphp

    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Gérant</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Templates de mails</h1>
            <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Templates Markdown modifiables, prêts à être utilisés par des Mailables.</p>
        </div>

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#f5c2c7;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        <section class="gc-card p-4">
            <form id="mail-template-filters-form" method="GET" action="{{ route('manager.mail-templates') }}" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_220px]">
                <div>
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" class="gc-input" placeholder="Nom, slug ou sujet" />
                </div>

                <div>
                    <label class="gc-label" for="status">Statut</label>
                    <select id="status" name="status" class="gc-input">
                        <option value="">Tous</option>
                        <option value="active" @selected($filters['status'] === 'active')>Actifs</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Inactifs</option>
                    </select>
                </div>

                <div class="md:col-span-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" class="gc-btn-primary" data-template-create>Ajouter un template</button>
                    <a href="{{ route('manager.mail-templates') }}" class="gc-link">Réinitialiser les filtres</a>
                </div>
            </form>
        </section>

        <section class="gc-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b" style="border-color:var(--gc-border);background:#f8f8f8;">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Template</th>
                            <th class="px-4 py-3 font-semibold">Sujet</th>
                            <th class="px-4 py-3 font-semibold">Variables</th>
                            <th class="px-4 py-3 font-semibold">Statut</th>
                            <th class="px-4 py-3 font-semibold">Dernière modification</th>
                            <th class="px-4 py-3 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($templates as $template)
                            @php
                                $templatePayload = [
                                    'id' => $template->id,
                                    'name' => $template->name,
                                    'slug' => $template->slug,
                                    'subject' => $template->subject,
                                    'markdown_body' => $template->markdown_body,
                                    'logo_url' => $template->logo_url,
                                    'is_active' => (bool) $template->is_active,
                                    'update_url' => route('manager.mail-templates.update', $template),
                                    'delete_url' => route('manager.mail-templates.destroy', $template),
                                ];
                                $variables = $template->used_variables;
                            @endphp
                            <tr class="border-b last:border-b-0" style="border-color:var(--gc-border);">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($template->logo_url)
                                            <img src="{{ $template->logo_url }}" alt="" class="h-10 w-10 rounded-xl border object-contain p-1" style="border-color:var(--gc-border);background:#fff;">
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl border text-xs font-semibold" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text-soft);">ML</div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="font-semibold" style="color:var(--gc-text);">{{ $template->name }}</div>
                                            <div class="mt-0.5 font-mono text-xs" style="color:var(--gc-text-soft);">{{ $template->slug }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-md px-4 py-3">
                                    <span class="line-clamp-2">{{ $template->subject }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($variables->isNotEmpty())
                                        <div class="flex max-w-sm flex-wrap gap-1">
                                            @foreach ($variables->take(4) as $variable)
                                                <span class="rounded-lg px-2 py-1 font-mono text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">{{ $variable }}</span>
                                            @endforeach
                                            @if ($variables->count() > 4)
                                                <span class="rounded-lg px-2 py-1 text-xs" style="background:#e0f2fe;color:#0369a1;">+{{ $variables->count() - 4 }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <span style="color:var(--gc-text-soft);">Aucune</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $template->is_active ? '#dcfce7' : '#ffe4e6' }};color:{{ $template->is_active ? '#166534' : '#9f1239' }};">
                                        {{ $template->is_active ? 'Actif' : 'Inactif' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3" style="color:var(--gc-text-soft);">
                                    <div>{{ $template->updated_at?->format('d/m/Y H:i') }}</div>
                                    @if ($template->updatedBy)
                                        <div class="text-xs">par {{ $template->updatedBy->full_name }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="gc-btn-soft"
                                            aria-label="Voir le template {{ $template->name }}"
                                            data-template-edit='@json($templatePayload)'
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="gc-btn-danger"
                                            data-template-delete='@json($templatePayload)'
                                        >Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucun template de mail.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t px-4 py-3" style="border-color:var(--gc-border);">
                {{ $templates->links() }}
            </div>
        </section>
    </div>

    <div id="mail-template-editor-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <div class="flex flex-col gap-3 border-b pb-4 md:flex-row md:items-start md:justify-between" style="border-color:var(--gc-border);">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Template de mail</p>
                    <h2 id="mail-template-editor-title" class="mt-1 text-xl font-semibold" style="color:var(--gc-text);">Ajouter un template</h2>
                </div>
                <button type="button" class="gc-link" data-mail-template-editor-close>Fermer</button>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(360px,0.9fr)_minmax(0,1.1fr)]">
                <form id="mail-template-editor-form" method="POST" action="{{ route('manager.mail-templates.store') }}" enctype="multipart/form-data" class="space-y-4" data-validate-form>
                    @csrf
                    <input id="mail_template_method" type="hidden" name="_method" value="POST" disabled />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="gc-label" for="mail_template_name">Nom</label>
                            <input id="mail_template_name" name="name" type="text" maxlength="190" class="gc-input" required placeholder="Ex: Confirmation RDV" />
                        </div>
                        <div>
                            <label class="gc-label" for="mail_template_slug">Slug</label>
                            <input id="mail_template_slug" name="slug" type="text" maxlength="190" class="gc-input" placeholder="Généré depuis le nom si vide" />
                        </div>
                    </div>

                    <div>
                        <label class="gc-label" for="mail_template_subject">Sujet du mail</label>
                        <input id="mail_template_subject" name="subject" type="text" maxlength="190" class="gc-input" required placeholder="RDV @{{ service_label }} - @{{ appointment_date }}" />
                    </div>

                    <div class="rounded-xl border p-3" style="border-color:var(--gc-border);">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <label class="gc-label" for="mail_template_logo">Logo du template</label>
                                <p class="text-xs" style="color:var(--gc-text-soft);">Optionnel. Format JPG ou PNG, 2 Mo maximum.</p>
                            </div>
                            <div id="mail-template-logo-preview" class="hidden shrink-0">
                                <img id="mail-template-logo-preview-img" src="" alt="" class="h-14 w-28 rounded-xl border object-contain p-2" style="border-color:var(--gc-border);background:#fff;">
                            </div>
                        </div>
                        <input id="mail_template_logo" name="logo" type="file" accept="image/png,image/jpeg" class="gc-input mt-3" />
                        <p id="mail-template-logo-status" class="mt-2 text-xs" style="color:var(--gc-text-soft);">Aucun logo défini.</p>
                        <label id="mail-template-remove-logo-row" class="mt-3 hidden items-center gap-3 text-sm">
                            <input id="mail_template_remove_logo" name="remove_logo" type="checkbox" value="1" class="gc-check" />
                            <span style="color:var(--gc-text);">Supprimer le logo actuel</span>
                        </label>
                    </div>

                    <div>
                        <label class="gc-label" for="mail_template_markdown_body">Vue Markdown</label>
                        <textarea id="mail_template_markdown_body" name="markdown_body" rows="18" maxlength="60000" class="gc-input font-mono text-xs leading-relaxed" required></textarea>
                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">Markdown uniquement. Les variables s’écrivent sous la forme <span class="font-mono">@{{ client_name }}</span>. Le Blade stocké en BDD n’est volontairement pas exécuté.</p>
                    </div>

                    <label class="inline-flex items-center gap-3 rounded-xl border px-3 py-2 text-sm" style="border-color:var(--gc-border);">
                        <input id="mail_template_is_active" name="is_active" type="checkbox" value="1" class="gc-check" checked />
                        <span style="color:var(--gc-text);">Template actif</span>
                    </label>

                    <div class="rounded-xl border p-3" style="border-color:var(--gc-border);background:var(--gc-accent-soft);">
                        <p class="text-sm font-semibold" style="color:var(--gc-text);">Variables disponibles en preview</p>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($sampleVariables as $variable => $sampleValue)
                                <button
                                    type="button"
                                    class="rounded-lg border bg-white px-2 py-1 text-xs font-semibold transition hover:-translate-y-0.5"
                                    style="border-color:var(--gc-border);color:var(--gc-text);"
                                    data-template-variable="{{ $variable }}"
                                    title="Insérer @{{ $variable }}"
                                >
                                    {{ $variableLabels[$variable] ?? ucwords(str_replace('_', ' ', $variable)) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="gc-modal-actions">
                        <button type="button" class="gc-link" data-mail-template-editor-close>Annuler</button>
                        <button id="mail-template-submit" type="submit" class="gc-btn-primary">Créer</button>
                    </div>
                </form>

                <aside class="min-h-0 rounded-2xl border bg-white p-4" style="border-color:var(--gc-border);">
                    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-[0.12em]" style="color:var(--gc-text-soft);">Preview réelle</p>
                            <h3 id="mail-template-preview-subject" class="mt-1 text-base font-semibold" style="color:var(--gc-text);">Sujet du mail</h3>
                        </div>
                        <span id="mail-template-preview-status" class="text-xs" style="color:var(--gc-text-soft);">En attente</span>
                    </div>
                    <iframe id="mail-template-preview-frame" title="Preview du mail" class="h-[680px] w-full rounded-xl border bg-white" style="border-color:var(--gc-border);"></iframe>
                </aside>
            </div>
        </div>
    </div>

    <div id="delete-mail-template-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Supprimer le template</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Le template <span id="delete-mail-template-name" class="font-semibold" style="color:var(--gc-text);"></span> sera supprimé.</p>
            <form id="delete-mail-template-form" method="POST" action="#" class="mt-5 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" class="gc-link" data-delete-mail-template-close>Annuler</button>
                <button type="submit" class="gc-btn-danger">Supprimer</button>
            </form>
        </div>
    </div>

    <script>
        const mailTemplateStoreUrl = @json(route('manager.mail-templates.store'));
        const mailTemplatePreviewUrl = @json(route('manager.mail-templates.preview'));
        const defaultMailTemplateMarkdown = @json($defaultMarkdown);
        const mailTemplateCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const editorModal = document.getElementById('mail-template-editor-modal');
        const editorTitle = document.getElementById('mail-template-editor-title');
        const editorForm = document.getElementById('mail-template-editor-form');
        const methodInput = document.getElementById('mail_template_method');
        const nameInput = document.getElementById('mail_template_name');
        const slugInput = document.getElementById('mail_template_slug');
        const subjectInput = document.getElementById('mail_template_subject');
        const markdownInput = document.getElementById('mail_template_markdown_body');
        const logoInput = document.getElementById('mail_template_logo');
        const removeLogoInput = document.getElementById('mail_template_remove_logo');
        const removeLogoRow = document.getElementById('mail-template-remove-logo-row');
        const logoPreview = document.getElementById('mail-template-logo-preview');
        const logoPreviewImg = document.getElementById('mail-template-logo-preview-img');
        const logoStatus = document.getElementById('mail-template-logo-status');
        const activeInput = document.getElementById('mail_template_is_active');
        const submitButton = document.getElementById('mail-template-submit');
        const previewFrame = document.getElementById('mail-template-preview-frame');
        const previewSubject = document.getElementById('mail-template-preview-subject');
        const previewStatus = document.getElementById('mail-template-preview-status');
        const deleteModal = document.getElementById('delete-mail-template-modal');
        const deleteForm = document.getElementById('delete-mail-template-form');
        const deleteName = document.getElementById('delete-mail-template-name');
        const filtersForm = document.getElementById('mail-template-filters-form');
        let previewTimer = null;
        let previewAbortController = null;
        let editingExistingTemplate = false;
        let editingTemplateId = null;
        let editingLogoUrl = null;
        let selectedLogoObjectUrl = null;

        const openModal = (modal) => modal?.classList.remove('hidden');
        const closeModal = (modal) => modal?.classList.add('hidden');

        const slugify = (value) => String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 190);

        const setEditorMode = (template = null) => {
            editingExistingTemplate = Boolean(template);
            editingTemplateId = template?.id || null;
            editorTitle.textContent = template ? `Modifier ${template.name}` : 'Ajouter un template';
            editorForm.action = template ? template.update_url : mailTemplateStoreUrl;
            methodInput.disabled = !template;
            methodInput.value = template ? 'PUT' : 'POST';
            submitButton.textContent = template ? 'Enregistrer' : 'Créer';

            nameInput.value = template?.name || '';
            slugInput.value = template?.slug || '';
            subjectInput.value = template?.subject || '';
            markdownInput.value = template?.markdown_body || defaultMailTemplateMarkdown;
            activeInput.checked = template ? Boolean(template.is_active) : true;
            resetLogoField(template);

            window.TechCalendarForms?.refresh(editorForm);
            openModal(editorModal);
            schedulePreview(0);
        };

        const renderPreviewFallback = (message) => {
            previewFrame.srcdoc = `<!doctype html><html><body style="font-family:sans-serif;color:#31424c;padding:24px;">${message}</body></html>`;
        };

        const clearSelectedLogoObjectUrl = () => {
            if (selectedLogoObjectUrl) {
                URL.revokeObjectURL(selectedLogoObjectUrl);
                selectedLogoObjectUrl = null;
            }
        };

        const setLogoPreview = (url, status) => {
            clearSelectedLogoObjectUrl();

            if (url) {
                logoPreviewImg.src = url;
                logoPreview.classList.remove('hidden');
            } else {
                logoPreviewImg.src = '';
                logoPreview.classList.add('hidden');
            }

            logoStatus.textContent = status;
        };

        const resetLogoField = (template = null) => {
            editingLogoUrl = template?.logo_url || null;

            if (logoInput) {
                logoInput.value = '';
            }

            if (removeLogoInput) {
                removeLogoInput.checked = false;
            }

            if (removeLogoRow) {
                removeLogoRow.classList.toggle('hidden', !template?.logo_url);
                removeLogoRow.classList.toggle('inline-flex', Boolean(template?.logo_url));
            }

            setLogoPreview(template?.logo_url || null, template?.logo_url ? 'Logo actuel conservé.' : 'Aucun logo défini.');
        };

        const showSelectedLogoFile = () => {
            clearSelectedLogoObjectUrl();

            const file = logoInput?.files?.[0] || null;

            if (!file) {
                setLogoPreview(editingLogoUrl, editingLogoUrl ? 'Logo actuel conservé.' : 'Aucun logo défini.');
                schedulePreview();
                return;
            }

            selectedLogoObjectUrl = URL.createObjectURL(file);
            logoPreviewImg.src = selectedLogoObjectUrl;
            logoPreview.classList.remove('hidden');
            logoStatus.textContent = `${file.name} sélectionné. Le logo sera visible dans la preview réelle après enregistrement.`;

            if (removeLogoInput) {
                removeLogoInput.checked = false;
            }

            schedulePreview();
        };

        const syncRemoveLogoState = () => {
            if (removeLogoInput?.checked) {
                if (logoInput) {
                    logoInput.value = '';
                }

                setLogoPreview(null, 'Le logo actuel sera supprimé.');
            } else {
                setLogoPreview(editingLogoUrl, editingLogoUrl ? 'Logo actuel conservé.' : 'Aucun logo défini.');
            }

            schedulePreview(0);
        };

        const updatePreview = async () => {
            previewAbortController?.abort();
            previewAbortController = new AbortController();
            previewStatus.textContent = 'Génération...';

            try {
                const response = await fetch(mailTemplatePreviewUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': mailTemplateCsrf,
                    },
                    body: JSON.stringify({
                        mail_template_id: editingTemplateId,
                        subject: subjectInput.value,
                        markdown_body: markdownInput.value,
                        remove_logo: removeLogoInput?.checked || false,
                    }),
                    signal: previewAbortController.signal,
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : payload?.message;
                    throw new Error(firstError || 'Preview impossible.');
                }

                previewSubject.textContent = payload.subject || 'Sujet du mail';
                previewFrame.srcdoc = payload.html || '';
                previewStatus.textContent = 'À jour';
            } catch (error) {
                if (error.name === 'AbortError') return;

                previewStatus.textContent = 'Erreur preview';
                renderPreviewFallback(error.message || 'Preview impossible.');
            }
        };

        function schedulePreview(delay = 300) {
            window.clearTimeout(previewTimer);
            previewTimer = window.setTimeout(updatePreview, delay);
        }

        document.querySelector('[data-template-create]')?.addEventListener('click', () => setEditorMode());

        document.querySelectorAll('[data-template-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                setEditorMode(JSON.parse(button.dataset.templateEdit || '{}'));
            });
        });

        document.querySelectorAll('[data-template-delete]').forEach((button) => {
            button.addEventListener('click', () => {
                const template = JSON.parse(button.dataset.templateDelete || '{}');
                deleteForm.action = template.delete_url || '#';
                deleteName.textContent = template.name || '';
                openModal(deleteModal);
            });
        });

        document.querySelectorAll('[data-mail-template-editor-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(editorModal));
        });

        document.querySelector('[data-delete-mail-template-close]')?.addEventListener('click', () => closeModal(deleteModal));

        [editorModal, deleteModal].forEach((modal) => {
            modal?.addEventListener('click', (event) => {
                if (event.target === modal) closeModal(modal);
            });
        });

        nameInput?.addEventListener('input', () => {
            if (!editingExistingTemplate && slugInput.value.trim() === '') {
                slugInput.value = slugify(nameInput.value);
                slugInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        [subjectInput, markdownInput].forEach((input) => {
            input?.addEventListener('input', () => schedulePreview());
        });

        logoInput?.addEventListener('change', showSelectedLogoFile);
        removeLogoInput?.addEventListener('change', syncRemoveLogoState);

        document.querySelectorAll('[data-template-variable]').forEach((button) => {
            button.addEventListener('click', () => {
                const token = `{` + `{ ${button.dataset.templateVariable} }` + `}`;
                const start = markdownInput.selectionStart || markdownInput.value.length;
                const end = markdownInput.selectionEnd || markdownInput.value.length;
                markdownInput.value = `${markdownInput.value.slice(0, start)}${token}${markdownInput.value.slice(end)}`;
                markdownInput.focus();
                markdownInput.selectionStart = markdownInput.selectionEnd = start + token.length;
                markdownInput.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });

        if (filtersForm) {
            const searchInput = document.getElementById('q');
            const statusInput = document.getElementById('status');
            let filterTimer = null;

            searchInput?.addEventListener('input', () => {
                window.clearTimeout(filterTimer);
                filterTimer = window.setTimeout(() => filtersForm.submit(), 450);
            });

            statusInput?.addEventListener('change', () => filtersForm.submit());
        }
    </script>
</x-layouts.app>
