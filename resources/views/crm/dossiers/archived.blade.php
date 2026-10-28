<x-layouts.app :crm-navigation="true">
    <div class="space-y-5">
        <a href="{{ route('v2.dossiers.index', ['status' => 'unknown']) }}" class="gc-link">Retour aux dossiers supprimés</a>
        <x-workspace-heading :title="$dossier->beneficiaryLabel()" eyebrow="Dossier supprimé" description="Consultation interne uniquement. Les pièces, rendez-vous, facturation et historiques sont conservés." />
        @include('crm.dossiers.notice')
        <section class="gc-card space-y-4">
            <h2 class="font-semibold">{{ $dossier->deletion_source === 'legacy_status' ? 'Ancien dossier supprimé dans Cofrac' : 'Dossier supprimé' }}</h2>
            <p class="text-sm">Ce dossier est retiré des listes actives et des accès externes. Il ne peut plus être modifié ni facturé tant qu’il n’est pas restauré.</p>
            @php($summary = \App\Services\Crm\DossierBillingService::summary($dossier))
            <x-billing-badge :label="$summary['label']" :tone="$summary['tone']" />
            @can('restore', $dossier)
                <form method="POST" action="{{ route('v2.dossiers.restore', $dossier) }}" class="space-y-3">
                    @csrf<input type="hidden" name="version" value="{{ $dossier->version }}">
                    @if ($dossier->operations->isEmpty())
                        <label class="gc-label">Prestation à restaurer
                            <select name="service_id" class="gc-input" required><option value="">Choisir une prestation</option>
                                @foreach ($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    <p class="text-xs text-slate-500">Les fiches sans état connu reprendront en « Prise de RDV ». Les autres états et le marquage de facturation restent inchangés. Aucun envoi externe.</p>
                    <button type="submit" class="gc-btn-success"><x-nav-icon name="check" />Restaurer le dossier</button>
                </form>
            @endcan
        </section>
        <section class="gc-card space-y-3">
            <h2 class="font-semibold">Documents conservés</h2>
            <ul class="divide-y divide-slate-100">@forelse ($documents as $document)<li class="flex items-center justify-between gap-3 py-2"><span>{{ $document->name }}</span><a href="{{ route('v2.documents.download', $document) }}" class="gc-link">Télécharger</a></li>@empty<li>Aucun document natif.</li>@endforelse</ul>
            {{ $documents->links() }}
            <ul class="divide-y divide-slate-100">@foreach ($legacyDocuments as $document)<li class="flex items-center justify-between gap-3 py-2"><span>{{ $document['name'] }} <small class="text-slate-500">Archive Cofrac</small></span>@if ($document['url'])<a href="{{ $document['url'] }}" class="gc-link">Télécharger</a>@else<span class="text-xs text-slate-500">Fichier indisponible</span>@endif</li>@endforeach</ul>
            {{ $legacyDocuments->links() }}
        </section>
        <section class="gc-card space-y-3">
            <h2 class="font-semibold">Historique conservé</h2>
            <ol class="divide-y divide-slate-100">@foreach ($activities as $activity)<li class="py-2"><p class="text-xs text-slate-500">{{ $activity->created_at?->format('d/m/Y H:i') }} · {{ $activity->author?->full_name ?: 'Historique Cofrac' }}</p><x-comment-body :body="$activity->body" /></li>@endforeach</ol>
            {{ $activities->links() }}
        </section>
    </div>
</x-layouts.app>
