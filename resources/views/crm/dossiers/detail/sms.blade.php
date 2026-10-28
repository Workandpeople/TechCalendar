<section id="sms" class="gc-card gc-dossier-panel" aria-labelledby="dossier-sms-title" data-dossier-sms data-status-url="{{ route('v2.dossiers.sms.status', $dossier) }}">
    <header class="gc-dossier-panel-heading">
        <h2 id="dossier-sms-title"><x-nav-icon name="comment" />SMS au bénéficiaire</h2>
        <span @class(['rounded-md px-2 py-1 text-xs font-semibold', 'bg-sky-50 text-sky-800' => $smsMode === 'simulate', 'bg-amber-50 text-amber-800' => $smsMode === 'live'])>{{ $smsMode === 'simulate' ? 'Simulation · aucun envoi réel' : 'Envoi réel · crédits SMSFactor' }}</span>
    </header>
    <div class="gc-dossier-panel-body space-y-4">
        <p class="text-xs text-slate-500">Messages de suivi de dossier uniquement, pas de prospection. Historique interne. Un SMS accepté par le fournisseur n’est pas une confirmation de livraison.</p>
        @if ($smsUnavailable)<p role="status" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $smsUnavailable }}</p>@endif
        <details id="composer-sms" class="gc-dossier-upload">
            <summary><x-nav-icon name="plus" /> Préparer un SMS</summary>
            <form method="POST" action="{{ route('v2.dossiers.sms.store', $dossier) }}" data-sms-form data-preview-url="{{ route('v2.dossiers.sms.preview', $dossier) }}" class="mt-4 space-y-4">
                @csrf
                <fieldset @disabled($smsUnavailable) class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="sms-recipient" class="gc-label">Numéro du bénéficiaire</label><input id="sms-recipient" data-sms-input name="recipient" type="tel" value="{{ $dossier->phone }}" maxlength="50" class="gc-input" required autocomplete="off"><p class="mt-1 text-xs text-slate-500">Un seul mobile, format 06/07… ou +indicatif international.</p></div>
                        <div><label for="sms-appointment" class="gc-label">Rendez-vous concerné</label><select id="sms-appointment" data-sms-input name="appointment_id" class="gc-input"><option value="">Sans rendez-vous (texte libre)</option>@foreach ($smsAppointments as $smsAppointment)<option value="{{ $smsAppointment->id }}">#{{ $smsAppointment->id }} · {{ $smsAppointment->starts_at?->format('d/m/Y H:i') }} · {{ $smsAppointment->service?->name }} · {{ $smsAppointment->technician?->full_name }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500">100 derniers RDV. La date et le technicien viennent uniquement du RDV choisi.</p></div>
                    </div>
                    <div><label for="sms-template" class="gc-label">Modèle Cofrac (facultatif)</label><select id="sms-template" data-sms-template class="gc-input"><option value="">Texte libre</option>@foreach ($smsTemplates as $template)<option value="{{ $template['id'] }}" data-body="{{ $template['body'] }}">{{ $template['name'] }}</option>@endforeach</select></div>
                    <div><label for="sms-body" class="gc-label">Message modifiable pour cet envoi</label><textarea id="sms-body" data-sms-input name="body" rows="4" maxlength="1500" class="gc-input" required></textarea><p class="mt-1 text-xs text-slate-500">La modification ne change pas le modèle d’origine.</p></div>
                    <div class="flex flex-wrap gap-2" aria-label="Variables SMS">@foreach (\App\Services\Sms\DossierSmsService::VARIABLES as $variable => $label)<button type="button" class="gc-btn-soft" data-sms-variable="{{ $variable }}">{{ $label }}</button>@endforeach</div>
                    <button type="button" class="gc-btn-secondary" data-sms-preview>Vérifier et prévisualiser</button>
                    <div data-sms-preview-panel hidden class="rounded-xl border border-sky-200 bg-sky-50 p-4 space-y-3">
                        <p class="font-semibold text-sm" data-sms-preview-heading></p>
                        <p class="text-xs text-slate-600" data-sms-preview-meta></p>
                        <p class="whitespace-pre-wrap break-words rounded-lg bg-white p-3 text-sm" data-sms-preview-body></p>
                        <input type="hidden" name="preview_token" value="">
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required><span>{{ $smsMode === 'simulate' ? 'Je confirme la simulation : le numéro et le texte seront transmis à SMSFactor, sans SMS ni débit.' : 'Je confirme le numéro et le texte : cet envoi réel consommera des crédits SMSFactor.' }}</span></label>
                        <button type="submit" class="gc-btn-primary" data-sms-send disabled>{{ $smsMode === 'simulate' ? 'Lancer la simulation' : 'Confirmer l’envoi du SMS' }}</button>
                    </div>
                </fieldset>
                <p data-sms-feedback role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                <noscript><p>JavaScript est nécessaire pour vérifier le destinataire et confirmer le texte avant l’envoi.</p></noscript>
            </form>
        </details>
        <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">10 dernières demandes</h3><button type="button" class="gc-btn-soft" data-sms-refresh>Actualiser le suivi</button></div>
        <div data-sms-results>@include('crm.dossiers.detail.sms-status', ['messages' => $smsMessages])</div>
        <p data-sms-status-feedback class="text-xs text-slate-500" role="status"></p>
    </div>
</section>
