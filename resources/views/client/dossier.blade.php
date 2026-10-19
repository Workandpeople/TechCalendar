<x-layouts.app>
    <div class="space-y-6">
        <a class="gc-link" href="{{ route('client.dossiers') }}">← Mes dossiers</a>
        <x-workspace-heading :title="$dossier->beneficiaryLabel()" eyebrow="Votre dossier" :description="$dossier->legacy_lot_name ? 'Lot : '.$dossier->legacy_lot_name : 'Suivi de votre inspection et échanges avec notre équipe.'" />
        @include('client.notice')
        @include('client.non-conformity')
        <div class="grid items-start gap-6 lg:grid-cols-2">
            <section class="gc-card space-y-4 p-5"><h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="location" />Intervention</h2><div><p class="text-sm text-slate-500">Lieu d’inspection</p><p>{{ $dossier->site_name }}</p><p>{{ $dossier->address }}</p><p>{{ $dossier->postal_code }} {{ $dossier->city }}</p></div><p>{{ $dossier->phone }}</p>
                <ul class="space-y-3">@foreach ($dossier->operations as $operation)<li class="rounded-lg bg-slate-50 p-3"><p class="font-semibold">{{ $operation->service?->name ?: $operation->legacy_service_name }}</p><p class="mt-1 text-sm">{{ \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['label'] }}</p></li>@endforeach</ul>
                <h3 class="font-semibold">Rendez-vous</h3>@forelse ($appointments as $appointment)<p class="text-sm">{{ $appointment->starts_at?->format('d/m/Y à H:i') }} · {{ ['scheduled' => 'Planifié', 'problem' => 'Problème RDV'][$appointment->status] ?? $appointment->status }}</p>@empty<p class="text-sm text-slate-500">Aucun rendez-vous enregistré.</p>@endforelse
                {{ $appointments->withQueryString()->links() }}
            </section>
            @if ($documents !== null)
                <section id="client-documents" class="gc-card scroll-mt-24 space-y-4 p-5"><h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="document" />Documents partagés</h2><p class="text-sm text-slate-500">Seuls les documents autorisés pour votre organisation apparaissent ici.</p>
                    <ul class="divide-y divide-slate-100">@forelse ($documents as $document)<li class="flex items-center justify-between gap-4 py-3"><span class="break-words">{{ $document->name }}</span><a class="shrink-0 text-sm underline" href="{{ route('client.documents.download', $document) }}">Télécharger</a></li>@empty<li class="text-sm text-slate-500">Aucun document local partagé.</li>@endforelse</ul>
                    {{ $documents->withQueryString()->links() }}
                    @if ($lotDocuments?->total())
                        <h3 class="font-semibold">Documents du lot</h3>
                        <ul class="divide-y divide-slate-100">
                            @foreach ($lotDocuments as $document)
                                <li class="flex items-center justify-between gap-4 py-3"><span class="break-words">{{ $document->name }}</span><a class="shrink-0 text-sm underline" href="{{ route('client.dossiers.lot-documents.download', [$dossier, $document->id]) }}">Télécharger</a></li>
                            @endforeach
                        </ul>
                        {{ $lotDocuments->links() }}
                    @endif
                    @if ($collected?->total())<h3 class="font-semibold">Documents historiques</h3><ul class="divide-y divide-slate-100">@foreach ($collected as $document)<li class="flex items-center justify-between gap-4 py-3"><span class="break-words">{{ $document['name'] }}</span>@if ($document['url'])<a class="shrink-0 text-sm underline" href="{{ $document['url'] }}">Télécharger</a>@else<span class="text-xs text-slate-500">Non disponible dans cette collecte</span>@endif</li>@endforeach</ul>{{ $collected->links() }}@endif
                    @can('uploadDocument', $dossier)
                        <details id="client-document-upload" class="scroll-mt-24 rounded-lg border border-slate-200 p-4" @if ($dossier->operations->contains(fn ($operation) => \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'non_compliant') || old('_form') === 'client-document') open @endif>
                            <summary class="cursor-pointer font-semibold">Ajouter un document</summary>
                            <form class="mt-4 space-y-3" method="POST" enctype="multipart/form-data" action="{{ route('client.dossiers.upload', $dossier) }}">
                                @csrf
                                <input type="hidden" name="_form" value="client-document">
                                <p class="text-sm text-slate-500">Le dépôt transmet votre document à notre équipe sans demander automatiquement le recontrôle.</p>
                                <label class="gc-label" for="document-name">Nom du document</label><input id="document-name" name="name" maxlength="190" required class="gc-input" value="{{ old('_form') === 'client-document' ? old('name') : '' }}">
                                <label class="gc-label" for="document-file">Fichier, 10 Mo maximum</label><input id="document-file" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" required class="block w-full min-w-0 text-sm">
                                <button class="gc-btn-primary">Transmettre le document</button>
                            </form>
                        </details>
                    @endcan
                </section>
            @endif
        </div>
        @if ($comments !== null)
            <section id="client-comments" class="gc-card scroll-mt-24 space-y-4 p-5"><h2 class="text-lg font-semibold">Échanges avec notre équipe</h2>
                <ol class="space-y-4">@forelse ($comments as $comment)<li class="rounded-xl border border-slate-200 p-4"><p class="text-xs text-slate-500">{{ $comment->created_at?->format('d/m/Y H:i') }}</p><x-comment-body :body="$comment->body" class="mt-2 text-sm" /></li>@empty<li class="text-sm text-slate-500">Aucun commentaire partagé.</li>@endforelse</ol>{{ $comments->withQueryString()->links() }}
                @can('comment', $dossier)<form method="POST" class="space-y-3" action="{{ route('client.dossiers.comments', $dossier) }}">@csrf<label for="body" class="gc-label">Votre commentaire</label><textarea id="body" name="body" rows="3" maxlength="10000" class="gc-input" required>{{ old('body') }}</textarea><button class="gc-btn-primary">Envoyer le commentaire</button></form>@endcan
            </section>
        @endif
    </div>
</x-layouts.app>
