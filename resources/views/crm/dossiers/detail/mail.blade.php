@can('update', $dossier)
<section id="email-dossier" class="gc-card gc-dossier-panel" data-mail-delivery-status
    data-status-url="{{ route('v2.dossiers.mail.status', $dossier) }}"
    data-pending="{{ collect($mailDeliveries ?? [])->contains('pending', true) ? 'true' : 'false' }}">
    <header class="gc-dossier-panel-heading"><h2><x-nav-icon name="mail" />Envoyer un mail</h2><a href="#envoyer-email" data-dialog-target="envoyer-email" class="gc-btn-primary"><x-nav-icon name="mail" />Rédiger</a></header>
    <div class="gc-dossier-panel-body text-sm text-slate-600">
        <div class="flex flex-wrap items-center justify-between gap-2"><p>Suivi des 10 dernières demandes d’envoi.</p><button type="button" class="gc-btn-soft" data-mail-status-refresh>Actualiser le suivi</button></div>
        <ul class="mt-3 max-h-80 overflow-y-auto divide-y divide-slate-100" data-mail-status-list aria-label="Résultats des envois d’emails">
            @forelse ($mailDeliveries ?? [] as $message)
                <li class="py-3 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center justify-between gap-2"><span @class(['rounded-md px-2 py-1 text-xs font-semibold',
                        'bg-emerald-100 text-emerald-900' => $message['status'] === 'sent',
                        'bg-amber-100 text-amber-900' => $message['pending'],
                        'bg-rose-100 text-rose-900' => in_array($message['status'], ['failed', 'blocked']),
                        'bg-slate-100 text-slate-700' => in_array($message['status'], ['unknown', 'legacy', 'simulated']),
                    ])>{{ $message['label'] }}</span><time class="text-xs text-slate-500">{{ $message['at'] }}</time></div>
                    <p class="mt-1 break-words text-sm font-medium text-slate-800">{{ $message['subject'] }}</p>
                    <p class="break-words text-xs text-slate-500">À : {{ $message['recipient'] }}</p>
                    <p class="mt-1 text-xs text-slate-600">{{ $message['detail'] }}</p>
                </li>
            @empty
                <li class="text-xs text-slate-500">Aucune demande d’envoi pour ce dossier.</li>
            @endforelse
        </ul>
        <p class="mt-2 text-xs text-slate-500" role="status" aria-live="polite" data-mail-status-feedback></p>
    </div>
</section>
<x-dossier-dialog id="envoyer-email" title="Envoyer un mail" :reopen="$errors->any() && old('_form') === 'mail'">
    <form method="POST" action="{{ route('v2.dossiers.mail.store', $dossier) }}" class="mt-4 space-y-4" data-dossier-mail data-preview-url="{{ route('v2.dossiers.mail.preview', $dossier) }}" data-mail-templates="{{ json_encode($mailTemplates->map->only(['id', 'subject', 'markdown_body'])->values()) }}">
        @csrf <input type="hidden" name="_form" value="mail">
        <p class="text-xs text-slate-500">Expéditeur : {{ config('mail.from.address') }}
            @if (!config('mail.delivery_enabled') || config('simulation.enabled') || in_array(config('mail.default'), ['log', 'array'], true))
                · Simulation sans envoi réel
            @endif
        </p>
        <label class="gc-label">Destinataire<input type="email" name="recipient_email" maxlength="190" required class="gc-input" value="{{ old('recipient_email', $dossier->email) }}"></label>
        <label class="gc-label">Modèle<select name="mail_template_id" class="gc-input" data-searchable><option value="">Message personnalisé</option>@foreach ($mailTemplates as $template)<option value="{{ $template->id }}" @selected(old('mail_template_id') == $template->id)>{{ $template->name }}</option>@endforeach</select></label>
        <label class="gc-label">Objet<input name="subject" required maxlength="190" class="gc-input" value="{{ old('subject') }}"></label>
        <label class="gc-label">Message<textarea name="markdown_body" rows="8" required maxlength="60000" class="gc-input">{{ old('markdown_body') }}</textarea></label>
        <p class="text-xs text-slate-500">Les retours à la ligne sont conservés. Les variables du modèle seront remplacées par les données du dossier.</p>
        <p role="status" data-mail-feedback class="text-sm"></p>
        <iframe title="Aperçu du mail" data-mail-preview sandbox="" referrerpolicy="no-referrer" class="h-80 w-full rounded border border-slate-200" hidden></iframe>
        <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button type="button" class="gc-btn-secondary" data-mail-preview-button>Aperçu</button><button type="submit" class="gc-btn-primary"><x-nav-icon name="mail" />Envoyer</button></footer>
    </form>
</x-dossier-dialog>
@endcan
