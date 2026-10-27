<section id="email-dossier" class="gc-card gc-dossier-panel">
    <header class="gc-dossier-panel-heading"><h2><x-nav-icon name="mail" />Envoyer un mail</h2><a href="#envoyer-email" data-dialog-target="envoyer-email" class="gc-btn-primary"><x-nav-icon name="mail" />Rédiger</a></header>
    <div class="gc-dossier-panel-body text-sm text-slate-600">Utilisez un modèle ou rédigez un message personnalisé. Les envois sont tracés dans l’historique interne.</div>
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
