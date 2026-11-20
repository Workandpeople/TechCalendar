@if ($onSiteReport = $appointment->external_payload['techcalendar_on_site'] ?? null)
    <section class="tech-panel tech-report-confirmed" role="status" data-on-site-confirmed>
        <x-nav-icon name="check" />
        <div><h2>Signalement enregistré</h2><p>{{ \App\Services\Tech\TechnicianOnSiteReports::REASONS[$onSiteReport['reason'] ?? ''] ?? 'Problème sur place' }} · {{ \Carbon\CarbonImmutable::parse($onSiteReport['reported_at'])->format('d/m/Y à H:i') }}</p><p>La photo et le commentaire sont dans le dossier. @if ($onSiteReport['status_decision_pending'] ?? false) Le statut du dossier est conservé ; l’équipe administrative décidera de la suite depuis les signalements. @elseif ($onSiteReport['returned_to_booking'] ?? false) La fiche avait été remise en prise de RDV. @else Le planning peut reprendre contact avec le client. @endif</p></div>
    </section>
@endif
@if (auth()->user()->can("reportOnSiteProblem", $appointment) || auth()->user()->can("reportOnSiteEvidence", $appointment))
    <details id="tech-on-site-report" class="tech-panel tech-on-site-report" @if ($errors->any()) open @endif>
        <summary><x-nav-icon name="alert" /><span>Signaler un problème sur place<small>Client absent, refus du RDV, oubli ou accès impossible</small></span><span aria-hidden="true">+</span></summary>
        @include("tech.partials.on-site-report-form")
    </details>
@endif
