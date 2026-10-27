<details id="booking-replacements" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4" @if (request()->has('replacement_page')) open @endif>
    <summary class="cursor-pointer text-sm font-semibold text-amber-950">{{ $replacementRequests->total() }} prestation(s) en « Prise de RDV » avec un rendez-vous existant</summary>
    <p class="mt-2 text-sm text-amber-900">Ces prestations ne sont pas dans la liste des nouveaux RDV à placer. Utilisez « Replacer le RDV » pour modifier le rendez-vous sans créer de doublon.</p>
    <ul class="mt-3 divide-y divide-amber-200">
        @foreach ($replacementRequests as $operation)
            @php($appointment = $operation->appointments->first())
            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="min-w-0 text-sm">
                    <a class="gc-link font-semibold" href="{{ route('v2.dossiers.show', $operation->dossier) }}">{{ $operation->dossier->beneficiaryLabel() }}</a>
                    <p>{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation non renseignée' }}</p>
                    <p class="text-xs text-amber-900">{{ $operation->dossier->address }} · {{ $operation->dossier->postal_code }} {{ $operation->dossier->city }}</p>
                    <p class="mt-1 text-xs text-amber-900">RDV existant : {{ $appointment->starts_at->format('d/m/Y à H:i') }}{{ $appointment->ends_at->isPast() ? ' · Créneau passé' : '' }}</p>
                </div>
                <a class="gc-btn-secondary whitespace-nowrap" href="{{ route(request()->routeIs('manager.*') ? 'manager.appointments.modify' : 'planner.appointments.modify', ['replace_appointment_id' => $appointment->id]) }}">Replacer le RDV</a>
            </li>
        @endforeach
    </ul>
    {{ $replacementRequests->links() }}
</details>
