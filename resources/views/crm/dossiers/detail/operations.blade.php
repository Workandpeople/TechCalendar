<section id="prestations" class="gc-card gc-dossier-panel" aria-labelledby="dossier-status-title">
    <header class="gc-dossier-panel-heading"><h2 id="dossier-status-title"><x-nav-icon name="services" />Statut et fiches <span class="gc-dossier-count">{{ $dossier->operations->count() }}</span></h2></header>
    <div class="gc-dossier-panel-body">
        <p class="text-xs text-slate-500">Une fiche par prestation. Les actions proposées suivent l’état du dossier.</p>
        <ul class="mt-3 divide-y divide-slate-100">
            @foreach ($dossier->operations as $operation)
                @php
                    $state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
                    $linkedAppointment = $operation->appointments->first();
                @endphp
                <li id="fiche-{{ $operation->id }}" class="gc-dossier-operation space-y-3 py-4">
                    <div><p class="font-medium">{{ ($operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation à rapprocher') }}</p><p class="text-xs text-slate-500">{{ ($operation->service?->type ?: 'Coffrac') }} · Fiche {{ $operation->id }}</p></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-dossier-status :status="$operation->legacy_status" />
                        @if ($operation->billing)<a href="#facturation-{{ $operation->id }}"><x-billing-badge /></a>@endif
                        <span class="text-sm text-slate-500">{{ $operation->appointments_count }} RDV lié(s)</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($linkedAppointment)
                            <a href="{{ route($planningRoute, ['appointment_id' => $linkedAppointment->id, 'technician_id' => $linkedAppointment->technician_id, 'date' => $linkedAppointment->starts_at?->toDateString()]) }}" class="gc-btn-secondary">Voir le RDV</a>
                            @if (in_array($state, ['pending', 'problem', 'awaiting_visit']))
                                <a href="{{ route('planner.book', ['replace_appointment_id' => $linkedAppointment->id]) }}" class="gc-btn-primary">Replacer le RDV</a>
                            @endif
                        @elseif ($state === 'pending')
                            <a href="{{ route('planner.book', ['crm_appointment_id' => 'dossier-'.$operation->id]) }}" class="gc-btn-primary">Planifier le RDV</a>
                        @elseif ($state === 'problem')
                            <details class="max-w-sm rounded-lg border border-slate-200 p-3">
                                <summary class="cursor-pointer text-sm font-semibold">Reprendre la prise de RDV</summary>
                                <form method="POST" action="{{ route('v2.dossiers.operations.reopen', [$dossier, $operation]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    <input type="hidden" name="version" value="{{ $dossier->version }}">
                                    <label for="reopen-{{ $operation->id }}" class="gc-label">Motif de la reprise</label>
                                    <textarea id="reopen-{{ $operation->id }}" name="comment" class="gc-input" rows="2" maxlength="2000" required></textarea>
                                    <button type="submit" class="gc-btn-primary">Remettre en prise de RDV</button>
                                </form>
                            </details>
                        @endif
                        @if (! in_array($state, ['pending', 'problem']))
                            <a href="#apres-visite" class="gc-btn-soft"><x-nav-icon name="check" />Avis et contrôle après visite</a>
                        @endif
                        @unless ($operation->inspected_on)
                        <details class="text-sm">
                            <summary class="cursor-pointer text-slate-500">Liaison existante</summary>
                            <a href="{{ route('v2.dossiers.appointments.index', [$dossier, $operation]) }}" class="mt-2 inline-block underline">Rattacher un RDV déjà créé</a>
                        </details>
                        @endunless
                    </div>
                    <details class="gc-operation-workflow" @if (old('_operation') == $operation->id) open @endif>
                        <summary class="gc-btn-success"><x-nav-icon name="edit" />Changer l’état / traiter la fiche</summary>
                        <div class="mt-3">@include('crm.dossiers.detail.control-actions')</div>
                        @if (in_array($state, ['pending', 'problem']))<p class="mt-3 text-sm">Le changement d’état suit la planification du rendez-vous ci-dessus.</p>@endif
                        @if ($state === 'non_compliant')<p class="mt-3 text-sm">Réponse attendue du client pour passer en recontrôle.</p>@endif
                    </details>
                </li>
            @endforeach
        </ul>
    </div>
</section>
