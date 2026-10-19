<aside class="gc-portal-aside" aria-label="Informations pratiques">
    <section id="client-appointments" class="gc-portal-panel" aria-labelledby="client-appointments-title">
        <header class="gc-portal-panel-heading" data-tone="teal"><div><span class="gc-portal-icon"><x-nav-icon name="planning" /></span><div><h2 id="client-appointments-title">Rendez-vous</h2><p>Les dates de votre dossier.</p></div></div></header>
        <div class="gc-portal-panel-body">
            @if ($nextAppointment)
                <div class="gc-portal-next-appointment">
                    <p class="gc-portal-eyebrow">{{ $nextAppointment->starts_at?->isPast() ? 'Créneau en cours' : 'Prochain rendez-vous' }}</p>
                    <p class="gc-portal-next-date">{{ $nextAppointment->starts_at?->translatedFormat('l j F Y') }}</p>
                    <p class="gc-portal-next-time">{{ $nextAppointment->starts_at?->format('H:i') }}@if ($nextAppointment->ends_at) <span>à</span> {{ $nextAppointment->ends_at->format('H:i') }}@endif</p>
                    <span class="gc-portal-appointment-state"><x-nav-icon name="check" />Planifié</span>
                </div>
            @else
                <p class="gc-portal-muted">Aucun prochain rendez-vous planifié.</p>
            @endif
            @if ($appointments->total())
                <details class="gc-portal-appointment-history" @if (request()->filled('rdv_page')) open @endif>
                    <summary>Tous les rendez-vous <span>{{ $appointments->total() }}</span></summary>
                    <ol>
                        @foreach ($appointments as $appointment)
                            <li><time datetime="{{ $appointment->starts_at?->toIso8601String() }}">{{ $appointment->starts_at?->format('d/m/Y à H:i') ?: 'Date à préciser' }}</time><span>{{ ['scheduled' => 'Planifié', 'problem' => 'Problème RDV'][$appointment->status] ?? 'État à préciser' }}</span></li>
                        @endforeach
                    </ol>
                    {{ $appointments->withQueryString()->links() }}
                    <p class="gc-portal-muted">Un créneau passé ne confirme pas que la visite a été effectuée.</p>
                </details>
            @endif
        </div>
    </section>
    <section class="gc-portal-panel" aria-labelledby="client-site-title">
        <header class="gc-portal-panel-heading" data-tone="slate"><div><span class="gc-portal-icon"><x-nav-icon name="location" /></span><h2 id="client-site-title">Lieu d’inspection</h2></div></header>
        <dl class="gc-portal-panel-body gc-portal-information">
            @if ($dossier->site_name)<div><dt>Site</dt><dd>{{ $dossier->site_name }}</dd></div>@endif
            <div><dt>Adresse</dt><dd>{{ $dossier->address ?: 'Non renseignée' }}<br>{{ $dossier->postal_code }} {{ $dossier->city }}</dd></div>
            @if ($dossier->phone)<div><dt>Téléphone du dossier</dt><dd>{{ $dossier->phone }}</dd></div>@endif
            @if ($dossier->legacy_lot_name)<div><dt>Lot</dt><dd>{{ $dossier->legacy_lot_name }}</dd></div>@endif
        </dl>
    </section>
</aside>
