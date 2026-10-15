<x-layouts.app :crm-navigation="true">
    <div class="space-y-6">
        <header>
            <a href="{{ route('v2.dossiers.show', $dossier) }}#rendez-vous" class="text-sm underline">Retour au dossier</a>
            <p class="mt-4 text-xs font-semibold uppercase tracking-widest text-slate-500">{{ $dossier->reference }}</p>
            <h1 class="mt-2 text-3xl font-semibold">Rattacher un rendez-vous existant</h1>
            <p class="mt-2 text-sm text-slate-500">{{ $dossier->beneficiaryLabel() }} · {{ ($operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation à rapprocher') }}</p>
        </header>
        @include('crm.dossiers.notice')
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
            <p class="font-semibold">On relie les données, on ne crée pas de nouveau rendez-vous.</p>
            <p class="mt-2 text-slate-600">Vérifie le bénéficiaire, l’adresse et le créneau avant de confirmer. Seuls les RDV de cette prestation, sans autre rattachement, sont proposés. Aucun mail ni envoi CRM ne sera déclenché.</p>
        </div>
        <form method="GET" class="grid gap-4 rounded-2xl border border-slate-200 p-5 md:grid-cols-4 md:items-end">
            <div class="md:col-span-2">
                <label for="appointment_q" class="gc-label">Bénéficiaire, adresse, numéro de RDV ou email du technicien</label>
                <input id="appointment_q" name="q" type="search" maxlength="120" class="gc-input" value="{{ $filters['q'] ?? '' }}" placeholder="Rechercher dans les rendez-vous existants">
            </div>
            <div><label for="appointment_date" class="gc-label">Date du rendez-vous</label><input id="appointment_date" name="date" type="date" class="gc-input" value="{{ $filters['date'] ?? '' }}"></div>
            <button class="gc-btn-primary" type="submit">Rechercher</button>
        </form>
        <section aria-label="Rendez-vous disponibles" class="space-y-3">
            <p class="text-sm text-slate-500">{{ $appointments->total() }} résultat(s). Les rendez-vous déjà rattachés à un autre dossier sont exclus.</p>
            @forelse ($appointments as $appointment)
                <article class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 lg:grid-cols-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-500">RDV #{{ $appointment->id }} @if ($appointment->external_reference) · {{ $appointment->external_source }} {{ $appointment->external_reference }} @endif</p>
                        <h2 class="mt-2 break-words font-semibold">{{ $appointment->customer_first_name }} {{ $appointment->customer_last_name }}</h2>
                        <p class="mt-1 break-words text-sm text-slate-600">{{ $appointment->address }}</p>
                        @foreach ($appointment->lotAppointments as $row)
                            <p class="mt-2 text-xs text-slate-500">Lot : {{ $row->lot?->name }} · Ligne {{ $row->row_number ?? $row->id }}</p>
                        @endforeach
                    </div>
                    <div>
                        <p class="font-semibold">{{ $appointment->starts_at?->format('d/m/Y H:i') }} à {{ $appointment->ends_at?->format('H:i') }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $appointment->technician?->full_name ?: 'Technicien indisponible' }}</p>
                        @if ($appointment->status === \App\Models\Appointment::STATUS_PROBLEM)<p class="mt-2 text-sm font-semibold text-rose-700">Problème RDV</p>@endif
                    </div>
                    <div>
                        @if ($appointment->dossier_operation_id === $operation->id)
                            <p class="text-sm font-semibold text-emerald-700">Déjà rattaché à cette prestation</p>
                        @else
                            <form method="POST" action="{{ route('v2.dossiers.appointments.store', [$dossier, $operation]) }}" class="space-y-3">
                                @csrf
                                <input type="hidden" name="appointment_id" value="{{ $appointment->id }}">
                                <input type="hidden" name="version" value="{{ $dossier->version }}">
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" required class="mt-1"><span>J’ai vérifié qu’il s’agit du même dossier.</span></label>
                                <button class="gc-btn-primary" type="submit">Rattacher au dossier</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <p class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ filled($filters['q'] ?? null) || filled($filters['date'] ?? null) ? 'Aucun rendez-vous compatible dans cette sélection.' : 'Renseigne un critère de recherche ou une date pour retrouver un rendez-vous.' }}</p>
            @endforelse
        </section>
        {{ $appointments->links() }}
    </div>
</x-layouts.app>
