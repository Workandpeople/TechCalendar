<x-layouts.app title="RDV modifiés · À reporter sur Global+">
    <section class="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
        <header><p class="text-sm font-semibold text-teal-700">SUIVI PLANNING</p><h1 class="text-2xl font-bold">RDV modifiés</h1><p class="mt-2 text-sm text-slate-600">Les changements de date, durée ou technicien sont regroupés ici. Reportez-les sur Global+, puis validez la version affichée. Aucun mail ni transfert API automatique.</p></header>
        @if (session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>@endif
        @if ($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-4 text-rose-900">{{ $errors->first() }}</div>@endif
        <form method="GET" class="grid gap-3 rounded-2xl border bg-white p-4 sm:grid-cols-[1fr_220px_auto]">
            <label class="gc-label">Dossier / lot<input class="gc-input mt-1" type="search" name="q" maxlength="120" value="{{ $filters['q'] ?? '' }}" placeholder="Nom, référence, numéro de lot"></label>
            <label class="gc-label">Suivi<select class="gc-input mt-1" name="status">@foreach (['pending' => 'À reporter', 'done' => 'Reportés', 'all' => 'Tous'] as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? 'pending') === $key)>{{ $label }}</option>@endforeach</select></label>
            <button class="gc-btn-primary self-end" type="submit">Filtrer</button>
        </form>
        <p class="text-sm text-slate-600" role="status">{{ $appointments->total() }} RDV dans cette vue. File commune à l’équipe ; une consultation ne marque rien comme reporté.</p>
        @forelse ($appointments as $appointment)
            @php($review = $appointment->global_plus_review)
            @php($dossier = $appointment->dossierOperation->dossier)
            <article id="rdv-{{ $appointment->id }}" class="space-y-4 rounded-2xl border bg-white p-4 sm:p-5">
                <header class="flex flex-wrap items-start justify-between gap-3"><div><a class="gc-link font-semibold" href="{{ route('v2.dossiers.show', $dossier) }}">{{ $dossier->beneficiaryLabel() }}</a><p class="text-sm text-slate-600">{{ $dossier->reference }} · {{ $appointment->service?->name }} · {{ $dossier->legacy_lot_name }}</p><p class="mt-1 break-words text-sm">{{ $appointment->address }}</p></div><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $appointment->global_plus_review_pending ? 'bg-amber-100 text-amber-950' : 'bg-emerald-50 text-emerald-900' }}">{{ $appointment->global_plus_review_pending ? 'À reporter sur Global+' : 'Reporté' }} · V{{ $appointment->global_plus_review_version }}</span></header>
                <div class="grid gap-3 sm:grid-cols-2">@foreach (['before' => 'Avant les changements à reporter', 'after' => 'Dernière version à reporter'] as $side => $label)
                    <div class="rounded-xl p-4 {{ $side === 'after' ? 'bg-teal-50' : 'bg-slate-50' }}"><h2 class="text-sm font-semibold">{{ $label }}</h2><p class="mt-2 font-semibold">{{ \Carbon\Carbon::parse($review[$side]['starts_at'])->tz('Europe/Paris')->format('d/m/Y à H:i') }} · {{ $review[$side]['duration_minutes'] }} min</p><p class="text-sm">{{ $review[$side]['technician'] }}</p></div>
                @endforeach</div>
                <p class="text-xs text-slate-600">Modifié par {{ $review['changed_by_name'] }} le {{ \Carbon\Carbon::parse($review['changed_at'])->tz('Europe/Paris')->format('d/m/Y à H:i') }}. @if ($appointment->trashed() || $appointment->status !== 'scheduled') Attention : le RDV a depuis été retiré du planning ou signalé ; vérifiez son dossier avant le report. @endif</p>
                @if ($appointment->global_plus_review_pending)
                    <form method="POST" action="{{ route('planner.appointment-changes.update', $appointment->id) }}" class="flex flex-wrap items-center justify-between gap-3 border-t pt-4">@csrf @method('PATCH')<input type="hidden" name="version" value="{{ $appointment->global_plus_review_version }}"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required>J’ai reporté cette version manuellement sur Global+</label><button type="submit" class="gc-btn-primary">Marquer comme reporté</button></form>
                @else
                    <p class="text-sm text-emerald-900">Report confirmé par {{ $review['acknowledged_by_name'] ?? 'l’équipe' }} le {{ \Carbon\Carbon::parse($review['acknowledged_at'])->tz('Europe/Paris')->format('d/m/Y à H:i') }}.</p>
                @endif
            </article>
        @empty
            <p class="rounded-2xl border bg-white p-6 text-slate-600">Aucun changement dans cette vue. Les nouveaux changements apparaîtront ici ; les anciens RDV ne sont pas repris automatiquement.</p>
        @endforelse
        {{ $appointments->links() }}
    </section>
</x-layouts.app>
