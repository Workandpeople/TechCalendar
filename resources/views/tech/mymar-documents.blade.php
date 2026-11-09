<x-layouts.app>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6">
        <header><p class="text-sm font-semibold text-amber-700">Rendez-vous MAR après travaux</p><h1 class="mt-1 text-2xl font-semibold">Documents de travaux</h1><p class="mt-2 text-sm text-slate-600">Factures et devis de l’intervention, du plus récent au plus ancien. Les pièces AMO/MAR et documents sensibles sont exclus.</p></header>
        @if ($unavailable)
            <section class="gc-card p-5" role="status"><h2 class="font-semibold">Documents momentanément indisponibles</h2><p class="mt-2 text-sm">L’accès documentaire MAR doit être configuré ou vérifié. Aucun document n’a été transmis.</p><a class="gc-btn-secondary mt-4" href="{{ request()->fullUrl() }}">Réessayer</a></section>
        @else
            <ul class="space-y-3">
                @forelse ($catalogue['documents'] as $document)
                    <li class="gc-card flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <span class="text-xs font-semibold text-amber-800">{{ $document['kind'] === 'invoice' ? 'Facture de travaux' : 'Devis de travaux' }}</span>
                            <h2 class="break-words font-semibold">{{ $document['name'] }}</h2>
                            <p class="mt-1 text-xs text-slate-500">PDF · {{ number_format($document['bytes'] / 1024, 0, ',', ' ') }} Ko</p>
                            <p class="mt-1 text-sm text-slate-600">
                                @if ($document['inserted_at'] ?? null)
                                    <time datetime="{{ $document['inserted_at'] }}">Ajouté dans MYMAR le {{ \Carbon\CarbonImmutable::parse($document['inserted_at'])->setTimezone(\App\Services\Crm\AppointmentDateTime::TIMEZONE)->format('d/m/Y à H:i') }}</time>
                                @else
                                    Date de dépôt MYMAR non renseignée
                                @endif
                            </p>
                        </div>
                        <a class="gc-btn-primary" href="{{ route('mymar.documents.download', ['rdv' => $rdv, 'document' => $document['id'], ...($technicianId ? ['technician_id' => $technicianId] : [])]) }}">Télécharger</a>
                    </li>
                @empty
                    <li class="gc-card p-5">Aucune facture ou devis de travaux identifiable n’est disponible pour ce rendez-vous.</li>
                @endforelse
            </ul>
        @endif
        <a href="{{ route(auth()->user()->role === 2 ? 'tech.planning' : 'planner.tracking') }}" class="gc-btn-secondary">Retour au planning</a>
    </div>
</x-layouts.app>
