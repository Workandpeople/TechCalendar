<section id="rendez-vous" class="gc-card gc-dossier-panel" aria-labelledby="dossier-appointments-title">
    <header class="gc-dossier-panel-heading"><h2 id="dossier-appointments-title"><x-nav-icon name="planning" />Rendez-vous et lots <span class="gc-dossier-count">{{ $appointments->total() }}</span></h2></header>
    <div class="gc-dossier-panel-body">
        <p class="text-xs text-slate-500">Un créneau passé ne signifie pas que le contrôle a été effectué.</p>
        <ul class="mt-5 divide-y divide-slate-100">
            @forelse ($appointments as $appointment)
                <li class="space-y-3 py-4">
                    <div>
                        <p class="text-xs text-slate-500">RDV #{{ $appointment->id }} · Prestation #{{ $appointment->dossier_operation_id }}</p>
                        <p class="mt-1 font-semibold">{{ $appointment->starts_at?->format('d/m/Y H:i') }} à {{ $appointment->ends_at?->format('H:i') }}</p>
                        <p class="mt-1 text-sm" data-operation-label="{{ $appointment->dossier_operation_id }}">{{ $appointment->service->name }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $appointment->technician?->full_name ?: 'Technicien indisponible' }}</p>
                        @if ($appointment->trashed())
                            <span class="mt-2 inline-block rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">Retiré du planning</span>
                        @elseif ($appointment->status === \App\Models\Appointment::STATUS_PROBLEM)
                            <span class="mt-2 inline-block rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-800">Problème RDV</span>
                        @else
                            <span class="mt-2 inline-block rounded-full bg-sky-50 px-3 py-1 text-xs text-sky-800">{{ $appointment->ends_at?->isPast() ? 'Créneau passé' : 'Planifié' }}</span>
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
                        @unless ($appointment->trashed())
                            @if (! $dossier->operations->firstWhere('id', $appointment->dossier_operation_id)?->inspected_on)
                                <details class="gc-technician-editor">
                                    <summary class="gc-btn-warning"><x-nav-icon name="users" />Changer le technicien</summary>
                                    <form method="POST" data-appointment-editor="{{ $appointment->id }}" data-operation="{{ $appointment->dossier_operation_id }}" action="{{ route('v2.dossiers.appointments.technician', [$dossier, $appointment->id]) }}" class="mt-3 space-y-3">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="version" value="{{ $dossier->version }}">
                                        <input type="hidden" name="updated_at" value="{{ $appointment->updated_at?->toIso8601String() }}">
                                        <label class="gc-label" for="appointment-tech-{{ $appointment->id }}">Technicien habilité pour cette prestation</label>
                                        <select class="gc-input" id="appointment-tech-{{ $appointment->id }}" name="technician_id" required>
                                            @foreach ($technicians as $technician)
                                                @if ($technician->id === $appointment->technician_id || $technician->services->contains('id', $appointment->service_id))<option value="{{ $technician->id }}" @selected($technician->id === $appointment->technician_id)>{{ $technician->full_name }}</option>@endif
                                            @endforeach
                                        </select>
                                        <p class="text-xs text-slate-500">Le créneau reste identique. Les absences, chevauchements et habilitations sont vérifiés à l’enregistrement.</p>
                                        <button type="submit" class="gc-btn-warning">Enregistrer le technicien</button>
                                    </form>
                                </details>
                            @endif
                            <a class="gc-btn-secondary" href="{{ route($planningRoute, ['appointment_id' => $appointment->id, 'technician_id' => $appointment->technician_id, 'date' => $appointment->starts_at?->toDateString()]) }}">Voir / modifier dans le planning</a>
                        @endunless
                    </div>
                </li>
            @empty
                <li class="py-6 text-sm text-slate-500">Aucun rendez-vous lié. Utilise « Planifier le RDV » sur la prestation à traiter.</li>
            @endforelse
        </ul>
        {{ $appointments->links() }}
        <a href="{{ route($planningRoute) }}" class="gc-btn-secondary mt-3"><x-nav-icon name="planning" />Ouvrir le planning</a>
    </div>
</section>
