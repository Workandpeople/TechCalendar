@foreach ($dossier->operations as $operation)
    @php
        $state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
        $failedResponse = (string) old('_operation') === (string) $operation->id;
        $prefix = 'correction-'.$operation->id;
    @endphp
    @continue (! in_array($state, ['non_compliant', 'recontrol'], true))
    <section class="gc-card space-y-4 p-5" aria-labelledby="{{ $prefix }}-title">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold" id="{{ $prefix }}-title">{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation' }} · Fiche #{{ $operation->id }}</h2>
            <x-dossier-status :status="$operation->legacy_status" />
        </header>
        @if ($state === 'recontrol')
            <p class="text-sm text-slate-600">En cours de recontrôle par notre équipe. Aucune nouvelle transmission n’est nécessaire pour le moment.</p>
        @else
            <p class="text-sm text-slate-600">Une correction est attendue de votre part. Consultez les éléments partagés ci-dessous, puis transmettez votre réponse pour demander le recontrôle de cette prestation.</p>
            <div class="flex flex-wrap gap-3 text-sm">
                @if ($comments !== null)<a href="#client-comments" class="underline">Lire les commentaires partagés</a>@endif
                @if ($documents !== null)<a href="#client-documents" class="underline">Voir les documents partagés</a>@endif
            </div>
            @can('respondToNonConformity', $dossier)
                <form method="POST" enctype="multipart/form-data" action="{{ route('client.dossiers.recontrol', [$dossier, $operation]) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="version" value="{{ $dossier->version }}">
                    <input type="hidden" name="_operation" value="{{ $operation->id }}">
                    <div class="grid items-start gap-4 lg:grid-cols-2">
                        @can('comment', $dossier)
                            <div><label class="gc-label" for="{{ $prefix }}-comment">Votre réponse</label><textarea class="gc-input" id="{{ $prefix }}-comment" name="comment" rows="4" maxlength="10000" @required(! auth()->user()->can('uploadDocument', $dossier))>{{ $failedResponse ? old('comment') : '' }}</textarea></div>
                        @endcan
                        @can('uploadDocument', $dossier)
                            <div class="space-y-3">
                                <div><label class="gc-label" for="{{ $prefix }}-document">Justificatif (10 Mo maximum)</label><input class="block w-full min-w-0 text-sm" type="file" id="{{ $prefix }}-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" @required(! auth()->user()->can('comment', $dossier))></div>
                                <div><label class="gc-label" for="{{ $prefix }}-name">Nom du document (facultatif)</label><input class="gc-input" id="{{ $prefix }}-name" name="document_name" maxlength="190" value="{{ $failedResponse ? old('document_name') : '' }}"></div>
                            </div>
                        @endcan
                    </div>
                    <p class="text-sm text-slate-500">Un commentaire ou un justificatif est obligatoire. Votre réponse est partagée avec notre équipe et les comptes autorisés de votre organisation. La transmission ne valide pas le contrôle.</p>
                    <button class="gc-btn-primary" type="submit">Transmettre pour recontrôle</button>
                </form>
            @else
                <p class="text-sm text-amber-800">Votre compte ne permet pas de transmettre une réponse. Contactez notre équipe pour faire vérifier vos droits.</p>
            @endcan
        @endif
    </section>
@endforeach
