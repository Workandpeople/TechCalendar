@php
    $recovery = $recovery ?? false;
    $evidenceRequired = $recovery || ! auth()->user()->can('reportOnSiteProblem', $appointment);
    $prefix = $recovery ? 'draft-on-site' : 'on-site';
@endphp
<form method="POST" action="{{ $recovery ? '' : route('tech.appointments.on-site-report', $appointment) }}" enctype="multipart/form-data"
      data-on-site-report data-actor="{{ auth()->id() }}" data-appointment="{{ $recovery ? '' : $appointment->id }}"
      @if ($evidenceRequired) data-evidence-required="true" @endif @if ($recovery) data-recovery="true" @endif>
    @csrf
    <input type="hidden" name="request_id" value="{{ $recovery ? '' : old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
    <input type="hidden" name="snapshot" value="{{ $recovery ? '' : $onSiteSnapshot }}">
    <input type="hidden" name="report_context" value="{{ $recovery ? '' : old('report_context', $onSiteContext['report_context']) }}">
    <input type="hidden" name="context_signature" value="{{ $recovery ? '' : old('context_signature', $onSiteContext['context_signature']) }}">
    <label class="tech-report-mode" for="{{ $prefix }}-evidence"><input id="{{ $prefix }}-evidence" type="checkbox" name="evidence_only" value="1" @checked($evidenceRequired || old('evidence_only')) @disabled($evidenceRequired)><span>Preuve d’un ancien passage<small>Photo et commentaire uniquement : ne change ni le statut ni la date du RDV actuel.</small></span></label>
    <p class="tech-report-intro" data-report-current-intro @if ($evidenceRequired || old('evidence_only')) hidden @endif>La validation conserve la photo et le commentaire et alerte le planning. <strong>Le statut du dossier reste inchangé</strong> jusqu’à la décision administrative.</p>
    <p class="tech-report-draft" data-report-visit-label hidden></p>
    <fieldset data-report-visit-fields hidden disabled class="tech-report-visit-fields">
        <legend>Date de l’ancien passage à confirmer</legend>
        <p class="tech-report-help">Cet ancien brouillon ne contient pas le créneau d’origine. Renseignez votre passage réel, pas la date du nouveau RDV.</p>
        <label for="{{ $prefix }}-date">Date du passage<input id="{{ $prefix }}-date" type="date" name="visit_date" class="gc-input" max="{{ now('Europe/Paris')->toDateString() }}"></label>
        <label for="{{ $prefix }}-time">Heure du passage<input id="{{ $prefix }}-time" type="time" name="visit_time" class="gc-input"></label>
    </fieldset>
    @if (! $recovery && $errors->any())<ul class="tech-report-errors" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <label for="{{ $prefix }}-reason">Motif <span aria-hidden="true">*</span></label>
    <select id="{{ $prefix }}-reason" name="reason" class="gc-input" required>
        <option value="">Choisir un motif</option>
        @foreach (\App\Services\Tech\TechnicianOnSiteReports::REASONS as $value => $label)<option value="{{ $value }}" @selected(! $recovery && old('reason') === $value)>{{ $label }}</option>@endforeach
    </select>
    <label for="{{ $prefix }}-photo" class="tech-report-photo-label"><x-nav-icon name="document" /><span>Prendre une photo sur place <small>Photo obligatoire · JPEG, PNG ou WebP · 10 Mo maximum</small></span></label>
    <input id="{{ $prefix }}-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required aria-describedby="{{ $prefix }}-photo-help">
    <p id="{{ $prefix }}-photo-help" class="tech-report-help">Évitez les visages et les pièces personnelles. L’heure de réception sera enregistrée ; les métadonnées GPS de la photo ne sont pas conservées.</p>
    <figure data-report-preview hidden><img alt="Photo du signalement à envoyer"><figcaption data-report-photo-name></figcaption></figure>
    <label for="{{ $prefix }}-comment">Commentaire <span class="tech-report-help">(obligatoire pour « Autre »)</span></label>
    <textarea id="{{ $prefix }}-comment" name="comment" rows="3" maxlength="2000" class="gc-input">{{ $recovery ? '' : old('comment') }}</textarea>
    <p class="tech-report-help">Photo et commentaire : <strong>Client uniquement</strong>. L’équipe interne conserve l’accès, pas les délégataires.</p>
    <p class="tech-report-help">Les notifications email suivent les droits et les préférences de chaque destinataire. Aucun SMS automatique.</p>
    <p class="tech-report-draft" data-report-draft-status role="status">Le brouillon reste sur cet appareil pendant 24 h. Rien n’est envoyé avant validation.</p>
    <button class="gc-btn-secondary" type="button" data-report-recover hidden>Conserver comme preuve de l’ancien passage</button>
    <div class="tech-report-feedback" data-report-feedback role="alert" hidden></div>
    <div class="tech-report-actions"><button class="gc-btn-danger" type="submit" data-report-submit>Valider le signalement</button><button class="gc-btn-secondary" type="button" data-report-discard>Effacer le brouillon</button></div>
    <noscript><p>Les brouillons et la reprise d’un ancien passage nécessitent JavaScript. Pour un signalement courant, reprenez une photo si une validation échoue.</p></noscript>
</form>
@if ($recovery)<a class="tech-today-link" href="{{ route('tech.planning') }}">Revenir à mes brouillons</a>@endif
