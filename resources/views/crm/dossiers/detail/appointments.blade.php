<section id="rendez-vous" class="gc-card gc-dossier-panel" aria-labelledby="dossier-appointments-title">
    <header class="gc-dossier-panel-heading"><h2 id="dossier-appointments-title"><x-nav-icon name="planning" />Rendez-vous et lots <span class="gc-dossier-count" data-dossier-appointments-count>{{ $appointments->total() }}</span></h2></header>
    <div class="gc-dossier-panel-body">
        <p class="text-xs text-slate-500">Un créneau passé ne signifie pas que le contrôle a été effectué.</p>
        <ul class="mt-5 divide-y divide-slate-100" data-dossier-appointments>
            @forelse ($appointments as $appointment)
                <li class="space-y-3 py-4" data-appointment-row="{{ $appointment->id }}">
                    <div>
                        <p class="text-xs text-slate-500">RDV #{{ $appointment->id }} · Prestation #{{ $appointment->dossier_operation_id }}</p>
                        <p class="mt-1 font-semibold" data-appointment-time="{{ $appointment->id }}">{{ $appointment->starts_at?->format('d/m/Y H:i') }} à {{ $appointment->ends_at?->format('H:i') }}</p>
                        <p class="mt-1 text-sm" data-operation-label="{{ $appointment->dossier_operation_id }}">{{ $appointment->service->name }}</p>
                        <p class="mt-1 text-sm text-slate-600" data-appointment-technician="{{ $appointment->id }}">{{ $appointment->technician?->full_name ?: 'Technicien indisponible' }}</p>
                        @if ($appointment->trashed())
                            <span class="mt-2 inline-block rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">Retiré du planning</span>
                        @elseif ($appointment->status === \App\Models\Appointment::STATUS_PROBLEM)
                            <span data-appointment-state="{{ $appointment->id }}" class="mt-2 inline-block rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-800">Problème RDV</span>
                        @else
                            <span data-appointment-state="{{ $appointment->id }}" class="mt-2 inline-block rounded-full bg-sky-50 px-3 py-1 text-xs text-sky-800">{{ $appointment->ends_at?->isPast() ? 'Créneau passé' : 'Planifié' }}</span>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <p class="break-words text-sm">{{ $appointment->address }}</p>
                        @foreach ($appointment->lotAppointments as $row)
                            @if ($row->lot)<a class="mt-2 block text-sm underline" href="{{ route('manager.lots.show', $row->lot) }}">Lot {{ $row->lot->name }} · Ligne {{ $row->row_number ?? $row->id }}</a>@endif
                        @endforeach
                        @if ($appointment->comment)<details class="mt-3 text-sm"><summary class="cursor-pointer text-slate-600">Commentaire du rendez-vous</summary><x-comment-body :body="$appointment->comment" class="mt-2 max-h-48 overflow-y-auto" /></details>@endif
                    </div>
                    <div class="space-y-3">
                        @if ($quickOperations->contains('id', $appointment->dossier_operation_id))
                            <button type="button" class="gc-btn-warning" data-quick-reschedule-url="{{ route('v2.dossiers.appointments.reschedule', [$dossier, $appointment->dossier_operation_id], absolute: false) }}">Replacer rapidement</button>
                        @endif
                        @unless ($appointment->trashed())
                            @if (! $dossier->operations->firstWhere('id', $appointment->dossier_operation_id)?->inspected_on)
                                <details class="gc-technician-editor">
                                    <summary class="gc-btn-warning"><x-nav-icon name="users" />Changer le technicien</summary>
                                    @include('crm.dossiers.technician-assignment', ['auto' => false])
                                    <p class="text-xs text-slate-500">Le créneau reste identique. Les conflits sont expliqués avant toute confirmation de placement forcé.</p>

                                </details>
                            @endif
                            <a class="gc-btn-secondary" data-appointment-planning="{{ $appointment->id }}" href="{{ route($planningRoute, ['appointment_id' => $appointment->id, 'technician_id' => $appointment->technician_id, 'date' => $appointment->starts_at?->toDateString()]) }}">Voir / modifier dans le planning</a>
                        @endunless
                    </div>
                </li>
            @empty
                <li data-no-appointments class="py-6 text-sm text-slate-500">Aucun rendez-vous lié. Utilise « Planifier le RDV » sur la prestation à traiter.</li>
            @endforelse
        </ul>
        {{ $appointments->links() }}
        <a href="{{ route($planningRoute) }}" class="gc-btn-secondary mt-3"><x-nav-icon name="planning" />Ouvrir le planning</a>
    </div>
</section>
