@if ($onSiteReport = $appointment->external_payload['techcalendar_on_site'] ?? null)
    <section class="tech-panel tech-report-confirmed" role="status" data-on-site-confirmed>
        <x-nav-icon name="check" />
        <div><h2>Signalement enregistré</h2><p>{{ \App\Services\Tech\TechnicianOnSiteReports::REASONS[$onSiteReport['reason'] ?? ''] ?? 'Problème sur place' }} · {{ \Carbon\CarbonImmutable::parse($onSiteReport['reported_at'])->format('d/m/Y à H:i') }}</p><p>La photo et le commentaire sont dans le dossier. Le planning peut reprendre contact avec le client.</p></div>
    </section>
@endif
@can('reportOnSiteProblem', $appointment)
    <details id="tech-on-site-report" class="tech-panel tech-on-site-report" @if ($errors->any()) open @endif>
        <summary><x-nav-icon name="alert" /><span>Signaler un problème sur place<small>Client absent, refus du RDV, oubli ou accès impossible</small></span><span aria-hidden="true">+</span></summary>
        <form method="POST" action="{{ route('tech.appointments.on-site-report', $appointment) }}" enctype="multipart/form-data"
              data-on-site-report data-actor="{{ auth()->id() }}" data-appointment="{{ $appointment->id }}">
            @csrf
            <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="snapshot" value="{{ $onSiteSnapshot }}">
            <p class="tech-report-intro">Ajoutez une photo du site et le motif. La validation met le rendez-vous en <strong>problème RDV</strong>, sans supprimer son historique.</p>
            @if ($errors->any())<ul class="tech-report-errors" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            <label for="on-site-reason">Motif <span aria-hidden="true">*</span></label>
            <select id="on-site-reason" name="reason" class="gc-input" required>
                <option value="">Choisir un motif</option>
                @foreach (\App\Services\Tech\TechnicianOnSiteReports::REASONS as $value => $label)<option value="{{ $value }}" @selected(old('reason') === $value)>{{ $label }}</option>@endforeach
            </select>
            <label for="on-site-photo" class="tech-report-photo-label"><x-nav-icon name="document" /><span>Prendre une photo sur place <small>Photo obligatoire · JPEG, PNG ou WebP · 10 Mo maximum</small></span></label>
            <input id="on-site-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required aria-describedby="on-site-photo-help">
            <p id="on-site-photo-help" class="tech-report-help">Évitez les visages et les pièces personnelles. L’heure de réception sera enregistrée ; les métadonnées GPS de la photo ne sont pas conservées.</p>
            <figure data-report-preview hidden><img alt="Photo du signalement à envoyer"><figcaption data-report-photo-name></figcaption></figure>
            <label for="on-site-comment">Commentaire <span class="tech-report-help">(obligatoire pour « Autre »)</span></label>
            <textarea id="on-site-comment" name="comment" rows="3" maxlength="2000" class="gc-input" placeholder="Ex. : portail fermé, appels sans réponse…">{{ old('comment') }}</textarea>
            <label for="on-site-audience">Partager la photo et le commentaire avec</label>
            <select id="on-site-audience" name="audience" class="gc-input" required>
                @foreach (\App\Services\Crm\ContentAudience::LABELS as $value => $label)<option value="{{ $value }}" @selected(old('audience', 'client') === $value)>{{ $label }}</option>@endforeach
            </select>
            <p class="tech-report-help">Les notifications email suivent les droits et les préférences de chaque destinataire. Aucun SMS automatique.</p>
            <p class="tech-report-draft" data-report-draft-status role="status">En cas de coupure réseau, le brouillon reste sur cet appareil pendant 24 h. L’envoi reste manuel.</p>
            <div class="tech-report-feedback" data-report-feedback role="alert" hidden></div>
            <div class="tech-report-actions"><button class="gc-btn-danger" type="submit" data-report-submit>Valider le signalement</button><button class="gc-btn-secondary" type="button" data-report-discard>Effacer le brouillon</button></div>
            <noscript><p>Les brouillons hors connexion nécessitent JavaScript. Reprenez une photo si une validation échoue.</p></noscript>
        </form>
    </details>
@endcan
