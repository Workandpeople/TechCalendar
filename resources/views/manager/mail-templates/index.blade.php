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
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" class="gc-input" placeholder="Nom ou sujet" />
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
            <div class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between" style="border-color:var(--gc-border);">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Expéditeurs</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Comptes SMTP utilisés par les templates de mails.</p>
                </div>
                <button type="button" class="gc-btn-primary" data-mail-sender-create>Ajouter un expéditeur</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b" style="border-color:var(--gc-border);background:#f8f8f8;">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Expéditeur</th>
                            <th class="px-4 py-3 font-semibold">SMTP</th>
                            <th class="px-4 py-3 font-semibold">Adresse d’envoi</th>
                            <th class="px-4 py-3 font-semibold">Templates</th>
                            <th class="px-4 py-3 font-semibold">Statut</th>
                            <th class="px-4 py-3 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($mailSenders as $sender)
                            @php
                                $senderPayload = [
                                    'id' => $sender->id,
                                    'name' => $sender->name,
                                    'mail_host' => $sender->mail_host,
                                    'mail_port' => $sender->mail_port,
                                    'mail_username' => $sender->mail_username,
                                    'mail_encryption' => $sender->mail_encryption,
                                    'mail_from_address' => $sender->mail_from_address,
                                    'mail_from_name' => $sender->mail_from_name,
                                    'mail_admin_email' => $sender->mail_admin_email,
                                    'logo_url' => $sender->logo_url,
                                    'is_active' => (bool) $sender->is_active,
                                    'templates_count' => $sender->templates_count,
                                    'update_url' => route('manager.mail-templates.senders.update', $sender),
                                    'delete_url' => route('manager.mail-templates.senders.destroy', $sender),
                                ];
                            @endphp
                            <tr class="border-b last:border-b-0" style="border-color:var(--gc-border);">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($sender->logo_url)
                                            <img src="{{ $sender->logo_url }}" alt="" class="h-10 w-10 rounded-xl border object-contain p-1" style="border-color:var(--gc-border);background:#fff;">
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl border text-xs font-semibold" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text-soft);">EX</div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="font-semibold" style="color:var(--gc-text);">{{ $sender->name }}</div>
                                            <div class="mt-0.5 text-xs" style="color:var(--gc-text-soft);">{{ $sender->mail_from_name }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-mono text-xs">{{ $sender->mail_host }}:{{ $sender->mail_port }}</div>
                                    <div class="mt-0.5 text-xs" style="color:var(--gc-text-soft);">{{ $sender->mail_username ?: 'Sans identifiant SMTP' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ $sender->mail_from_address }}</div>
                                    @if ($sender->mail_admin_email)
                                        <div class="mt-0.5 text-xs" style="color:var(--gc-text-soft);">Admin : {{ $sender->mail_admin_email }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $sender->templates_count }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $sender->is_active ? '#dcfce7' : '#ffe4e6' }};color:{{ $sender->is_active ? '#166534' : '#9f1239' }};">
                                        {{ $sender->is_active ? 'Actif' : 'Inactif' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" class="gc-btn-soft" data-mail-sender-edit='@json($senderPayload)'>Modifier</button>
                                        <button type="button" class="gc-btn-danger" data-mail-sender-delete='@json($senderPayload)' @disabled($sender->templates_count > 0)>Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucun expéditeur configuré.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="gc-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b" style="border-color:var(--gc-border);background:#f8f8f8;">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Template</th>
                            <th class="px-4 py-3 font-semibold">Expéditeur</th>
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
                                    'mail_sender_id' => $template->mail_sender_id,
                                    'subject' => $template->subject,
                                    'markdown_body' => $template->markdown_body,
                                    'logo_url' => $template->logo_url,
                                    'sender_name' => $template->sender?->name,
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
                                            <div class="mt-0.5 text-xs" style="color:var(--gc-text-soft);">Template Markdown</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($template->sender)
                                        <div class="font-semibold" style="color:var(--gc-text);">{{ $template->sender->name }}</div>
                                        <div class="mt-0.5 text-xs" style="color:var(--gc-text-soft);">{{ $template->sender->mail_from_address ?? '' }}</div>
                                    @else
                                        <span style="color:var(--gc-text-soft);">Non défini</span>
                                    @endif
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
                                <td colspan="7" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucun template de mail.</td>
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
                <form id="mail-template-editor-form" method="POST" action="{{ route('manager.mail-templates.store') }}" class="space-y-4" data-validate-form>
                    @csrf
                    <input id="mail_template_method" type="hidden" name="_method" value="POST" disabled />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="gc-label" for="mail_template_name">Nom</label>
                            <input id="mail_template_name" name="name" type="text" maxlength="190" class="gc-input" required placeholder="Ex: Confirmation RDV" />
                        </div>
                        <div>
                            <label class="gc-label" for="mail_template_sender_id">Expéditeur</label>
                            <select id="mail_template_sender_id" name="mail_sender_id" class="gc-input" required>
                                @forelse ($activeMailSenders as $sender)
                                    <option value="{{ $sender->id }}">{{ $sender->name }} — {{ $sender->mail_from_address }}</option>
                                @empty
                                    <option value="">Crée d’abord un expéditeur actif</option>
                                @endforelse
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="gc-label" for="mail_template_subject">Sujet du mail</label>
                        <input id="mail_template_subject" name="subject" type="text" maxlength="190" class="gc-input" required placeholder="RDV @{{ service_label }} - @{{ appointment_date }}" />
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

    <div id="mail-sender-editor-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <div class="flex flex-col gap-3 border-b pb-4 md:flex-row md:items-start md:justify-between" style="border-color:var(--gc-border);">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Expéditeur</p>
                    <h2 id="mail-sender-editor-title" class="mt-1 text-xl font-semibold" style="color:var(--gc-text);">Ajouter un expéditeur</h2>
                </div>
                <button type="button" class="gc-link" data-mail-sender-editor-close>Fermer</button>
            </div>

            <form id="mail-sender-editor-form" method="POST" action="{{ route('manager.mail-templates.senders.store') }}" enctype="multipart/form-data" class="mt-5 space-y-4" data-validate-form>
                @csrf
                <input id="mail_sender_method" type="hidden" name="_method" value="POST" disabled />

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label" for="mail_sender_name">Nom interne</label>
                        <input id="mail_sender_name" name="name" type="text" maxlength="190" class="gc-input" required placeholder="Ex: Genius Contrôle" />
                    </div>
                    <div>
                        <label class="gc-label" for="mail_sender_mail_from_name">MAIL_FROM_NAME</label>
                        <input id="mail_sender_mail_from_name" name="mail_from_name" type="text" maxlength="190" class="gc-input" required placeholder="Ex: Genius Contrôle" />
                    </div>
                    <div>
                        <label class="gc-label" for="mail_sender_mail_from_address">MAIL_FROM_ADDRESS</label>
                        <input id="mail_sender_mail_from_address" name="mail_from_address" type="email" maxlength="190" class="gc-input" required placeholder="contact@example.com" />
                    </div>
                    <div>
                        <label class="gc-label" for="mail_sender_mail_admin_email">MAIL_ADMIN_EMAIL</label>
                        <input id="mail_sender_mail_admin_email" name="mail_admin_email" type="email" maxlength="190" class="gc-input" placeholder="admin@example.com" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_120px_140px]">
                    <div>
                        <label class="gc-label" for="mail_sender_mail_host">MAIL_HOST</label>
                        <input id="mail_sender_mail_host" name="mail_host" type="text" maxlength="190" class="gc-input" required placeholder="ssl0.ovh.net" />
                    </div>
                    <div>
                        <label class="gc-label" for="mail_sender_mail_port">MAIL_PORT</label>
                        <input id="mail_sender_mail_port" name="mail_port" type="number" min="1" max="65535" class="gc-input" required value="587" />
                    </div>
                    <div>
                        <label class="gc-label" for="mail_sender_mail_encryption">MAIL_ENCRYPTION</label>
                        <select id="mail_sender_mail_encryption" name="mail_encryption" class="gc-input">
                            <option value="tls">tls</option>
                            <option value="ssl">ssl</option>
                            <option value="">Aucun</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label" for="mail_sender_mail_username">MAIL_USERNAME</label>
                        <input id="mail_sender_mail_username" name="mail_username" type="text" maxlength="190" class="gc-input" autocomplete="off" />
                    </div>
                    <div>
                        <label class="gc-label" for="mail_sender_mail_password">MAIL_PASSWORD</label>
                        <input id="mail_sender_mail_password" name="mail_password" type="password" maxlength="1000" class="gc-input" autocomplete="new-password" required />
                        <p id="mail-sender-password-help" class="mt-1 text-xs" style="color:var(--gc-text-soft);">Obligatoire à la création.</p>
                    </div>
                </div>

                <div class="rounded-xl border p-3" style="border-color:var(--gc-border);">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <label class="gc-label" for="mail_sender_logo">Logo de l’expéditeur</label>
                            <p class="text-xs" style="color:var(--gc-text-soft);">Optionnel. Format JPG ou PNG, 2 Mo maximum.</p>
                        </div>
                        <div id="mail-sender-logo-preview" class="hidden shrink-0">
                            <img id="mail-sender-logo-preview-img" src="" alt="" class="h-14 w-28 rounded-xl border object-contain p-2" style="border-color:var(--gc-border);background:#fff;">
                        </div>
                    </div>
                    <input id="mail_sender_logo" name="logo" type="file" accept="image/png,image/jpeg" class="gc-input mt-3" />
                    <p id="mail-sender-logo-status" class="mt-2 text-xs" style="color:var(--gc-text-soft);">Aucun logo défini.</p>
                    <label id="mail-sender-remove-logo-row" class="mt-3 hidden items-center gap-3 text-sm">
                        <input id="mail_sender_remove_logo" name="remove_logo" type="checkbox" value="1" class="gc-check" />
                        <span style="color:var(--gc-text);">Supprimer le logo actuel</span>
                    </label>
                </div>

                <label class="inline-flex items-center gap-3 rounded-xl border px-3 py-2 text-sm" style="border-color:var(--gc-border);">
                    <input id="mail_sender_is_active" name="is_active" type="checkbox" value="1" class="gc-check" checked />
                    <span style="color:var(--gc-text);">Expéditeur actif</span>
                </label>

                <div class="gc-modal-actions">
                    <button type="button" class="gc-link" data-mail-sender-editor-close>Annuler</button>
                    <button id="mail-sender-submit" type="submit" class="gc-btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-mail-sender-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Supprimer l’expéditeur</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">L’expéditeur <span id="delete-mail-sender-name" class="font-semibold" style="color:var(--gc-text);"></span> sera supprimé.</p>
            <form id="delete-mail-sender-form" method="POST" action="#" class="mt-5 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" class="gc-link" data-delete-mail-sender-close>Annuler</button>
                <button type="submit" class="gc-btn-danger">Supprimer</button>
            </form>
        </div>
    </div>

    <script>
        const mailTemplateStoreUrl = @json(route('manager.mail-templates.store'));
        const mailTemplatePreviewUrl = @json(route('manager.mail-templates.preview'));
        const mailSenderStoreUrl = @json(route('manager.mail-templates.senders.store'));
        const defaultMailTemplateMarkdown = @json($defaultMarkdown);
        const defaultMailSenderId = @json($activeMailSenders->first()?->id);
        const mailTemplateCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const editorModal = document.getElementById('mail-template-editor-modal');
        const editorTitle = document.getElementById('mail-template-editor-title');
        const editorForm = document.getElementById('mail-template-editor-form');
        const methodInput = document.getElementById('mail_template_method');
        const nameInput = document.getElementById('mail_template_name');
        const senderSelect = document.getElementById('mail_template_sender_id');
        const subjectInput = document.getElementById('mail_template_subject');
        const markdownInput = document.getElementById('mail_template_markdown_body');
        const activeInput = document.getElementById('mail_template_is_active');
        const submitButton = document.getElementById('mail-template-submit');
        const previewFrame = document.getElementById('mail-template-preview-frame');
        const previewSubject = document.getElementById('mail-template-preview-subject');
        const previewStatus = document.getElementById('mail-template-preview-status');
        const deleteModal = document.getElementById('delete-mail-template-modal');
        const deleteForm = document.getElementById('delete-mail-template-form');
        const deleteName = document.getElementById('delete-mail-template-name');
        const filtersForm = document.getElementById('mail-template-filters-form');

        const senderEditorModal = document.getElementById('mail-sender-editor-modal');
        const senderEditorTitle = document.getElementById('mail-sender-editor-title');
        const senderForm = document.getElementById('mail-sender-editor-form');
        const senderMethodInput = document.getElementById('mail_sender_method');
        const senderNameInput = document.getElementById('mail_sender_name');
        const senderHostInput = document.getElementById('mail_sender_mail_host');
        const senderPortInput = document.getElementById('mail_sender_mail_port');
        const senderUsernameInput = document.getElementById('mail_sender_mail_username');
        const senderPasswordInput = document.getElementById('mail_sender_mail_password');
        const senderPasswordHelp = document.getElementById('mail-sender-password-help');
        const senderEncryptionInput = document.getElementById('mail_sender_mail_encryption');
        const senderFromAddressInput = document.getElementById('mail_sender_mail_from_address');
        const senderFromNameInput = document.getElementById('mail_sender_mail_from_name');
        const senderAdminEmailInput = document.getElementById('mail_sender_mail_admin_email');
        const senderLogoInput = document.getElementById('mail_sender_logo');
        const senderRemoveLogoInput = document.getElementById('mail_sender_remove_logo');
        const senderRemoveLogoRow = document.getElementById('mail-sender-remove-logo-row');
        const senderLogoPreview = document.getElementById('mail-sender-logo-preview');
        const senderLogoPreviewImg = document.getElementById('mail-sender-logo-preview-img');
        const senderLogoStatus = document.getElementById('mail-sender-logo-status');
        const senderActiveInput = document.getElementById('mail_sender_is_active');
        const senderSubmitButton = document.getElementById('mail-sender-submit');
        const deleteSenderModal = document.getElementById('delete-mail-sender-modal');
        const deleteSenderForm = document.getElementById('delete-mail-sender-form');
        const deleteSenderName = document.getElementById('delete-mail-sender-name');

        let previewTimer = null;
        let previewAbortController = null;
        let editingTemplateId = null;
        let editingSenderLogoUrl = null;
        let selectedSenderLogoObjectUrl = null;

        const openModal = (modal) => modal?.classList.remove('hidden');
        const closeModal = (modal) => modal?.classList.add('hidden');

        const setEditorMode = (template = null) => {
            editingTemplateId = template?.id || null;
            editorTitle.textContent = template ? `Modifier ${template.name}` : 'Ajouter un template';
            editorForm.action = template ? template.update_url : mailTemplateStoreUrl;
            methodInput.disabled = !template;
            methodInput.value = template ? 'PUT' : 'POST';
            submitButton.textContent = template ? 'Enregistrer' : 'Créer';

            nameInput.value = template?.name || '';
            senderSelect.value = String(template?.mail_sender_id || defaultMailSenderId || '');
            subjectInput.value = template?.subject || '';
            markdownInput.value = template?.markdown_body || defaultMailTemplateMarkdown;
            activeInput.checked = template ? Boolean(template.is_active) : true;

            window.TechCalendarForms?.refresh(editorForm);
            openModal(editorModal);
            schedulePreview(0);
        };

        const renderPreviewFallback = (message) => {
            previewFrame.srcdoc = `<!doctype html><html><body style="font-family:sans-serif;color:#31424c;padding:24px;">${message}</body></html>`;
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
                        mail_sender_id: senderSelect.value || null,
                        subject: subjectInput.value,
                        markdown_body: markdownInput.value,
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

        const clearSelectedSenderLogoObjectUrl = () => {
            if (selectedSenderLogoObjectUrl) {
                URL.revokeObjectURL(selectedSenderLogoObjectUrl);
                selectedSenderLogoObjectUrl = null;
            }
        };

        const setSenderLogoPreview = (url, status) => {
            clearSelectedSenderLogoObjectUrl();

            if (url) {
                senderLogoPreviewImg.src = url;
                senderLogoPreview.classList.remove('hidden');
            } else {
                senderLogoPreviewImg.src = '';
                senderLogoPreview.classList.add('hidden');
            }

            senderLogoStatus.textContent = status;
        };

        const resetSenderLogoField = (sender = null) => {
            editingSenderLogoUrl = sender?.logo_url || null;

            if (senderLogoInput) {
                senderLogoInput.value = '';
            }

            if (senderRemoveLogoInput) {
                senderRemoveLogoInput.checked = false;
            }

            if (senderRemoveLogoRow) {
                senderRemoveLogoRow.classList.toggle('hidden', !sender?.logo_url);
                senderRemoveLogoRow.classList.toggle('inline-flex', Boolean(sender?.logo_url));
            }

            setSenderLogoPreview(sender?.logo_url || null, sender?.logo_url ? 'Logo actuel conservé.' : 'Aucun logo défini.');
        };

        const showSelectedSenderLogoFile = () => {
            clearSelectedSenderLogoObjectUrl();

            const file = senderLogoInput?.files?.[0] || null;

            if (!file) {
                setSenderLogoPreview(editingSenderLogoUrl, editingSenderLogoUrl ? 'Logo actuel conservé.' : 'Aucun logo défini.');
                return;
            }

            selectedSenderLogoObjectUrl = URL.createObjectURL(file);
            senderLogoPreviewImg.src = selectedSenderLogoObjectUrl;
            senderLogoPreview.classList.remove('hidden');
            senderLogoStatus.textContent = `${file.name} sélectionné.`;

            if (senderRemoveLogoInput) {
                senderRemoveLogoInput.checked = false;
            }
        };

        const syncSenderRemoveLogoState = () => {
            if (senderRemoveLogoInput?.checked) {
                if (senderLogoInput) {
                    senderLogoInput.value = '';
                }

                setSenderLogoPreview(null, 'Le logo actuel sera supprimé.');
            } else {
                setSenderLogoPreview(editingSenderLogoUrl, editingSenderLogoUrl ? 'Logo actuel conservé.' : 'Aucun logo défini.');
            }
        };

        const setSenderEditorMode = (sender = null) => {
            const isEditing = Boolean(sender);

            senderEditorTitle.textContent = isEditing ? `Modifier ${sender.name}` : 'Ajouter un expéditeur';
            senderForm.action = isEditing ? sender.update_url : mailSenderStoreUrl;
            senderMethodInput.disabled = !isEditing;
            senderMethodInput.value = isEditing ? 'PUT' : 'POST';
            senderSubmitButton.textContent = isEditing ? 'Enregistrer' : 'Créer';

            senderNameInput.value = sender?.name || '';
            senderHostInput.value = sender?.mail_host || 'ssl0.ovh.net';
            senderPortInput.value = sender?.mail_port || 587;
            senderUsernameInput.value = sender?.mail_username || '';
            senderPasswordInput.value = '';
            senderPasswordInput.required = !isEditing;
            senderPasswordInput.placeholder = isEditing ? 'Laisser vide pour conserver le mot de passe actuel' : '';
            senderPasswordHelp.textContent = isEditing ? 'Laisser vide pour conserver le mot de passe actuel.' : 'Obligatoire à la création.';
            senderEncryptionInput.value = sender?.mail_encryption ?? 'tls';
            senderFromAddressInput.value = sender?.mail_from_address || '';
            senderFromNameInput.value = sender?.mail_from_name || '';
            senderAdminEmailInput.value = sender?.mail_admin_email || '';
            senderActiveInput.checked = isEditing ? Boolean(sender.is_active) : true;
            resetSenderLogoField(sender);

            window.TechCalendarForms?.refresh(senderForm);
            openModal(senderEditorModal);
        };

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

        document.querySelector('[data-mail-sender-create]')?.addEventListener('click', () => setSenderEditorMode());

        document.querySelectorAll('[data-mail-sender-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                setSenderEditorMode(JSON.parse(button.dataset.mailSenderEdit || '{}'));
            });
        });

        document.querySelectorAll('[data-mail-sender-delete]').forEach((button) => {
            button.addEventListener('click', () => {
                const sender = JSON.parse(button.dataset.mailSenderDelete || '{}');
                deleteSenderForm.action = sender.delete_url || '#';
                deleteSenderName.textContent = sender.name || '';
                openModal(deleteSenderModal);
            });
        });

        document.querySelectorAll('[data-mail-template-editor-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(editorModal));
        });

        document.querySelectorAll('[data-mail-sender-editor-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(senderEditorModal));
        });

        document.querySelector('[data-delete-mail-template-close]')?.addEventListener('click', () => closeModal(deleteModal));
        document.querySelector('[data-delete-mail-sender-close]')?.addEventListener('click', () => closeModal(deleteSenderModal));

        [editorModal, deleteModal, senderEditorModal, deleteSenderModal].forEach((modal) => {
            modal?.addEventListener('click', (event) => {
                if (event.target === modal) closeModal(modal);
            });
        });

        [subjectInput, markdownInput, senderSelect].forEach((input) => {
            input?.addEventListener('input', () => schedulePreview());
            input?.addEventListener('change', () => schedulePreview(0));
        });

        senderLogoInput?.addEventListener('change', showSelectedSenderLogoFile);
        senderRemoveLogoInput?.addEventListener('change', syncSenderRemoveLogoState);

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
