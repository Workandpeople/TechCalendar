<section id="apres-visite" class="gc-card scroll-mt-24 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="check" />Avis et contrôle après la visite</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">Visite effectuée → contrôle RT → rapport validé. En cas de non-conformité : réponse du client → recontrôle → nouvelle non-conformité ou rapport validé. Chaque prestation suit son propre parcours.</p>
        </div>
        <a href="#documents-dossier" class="gc-btn-soft">Ajouter un rapport PDF</a>
    </div>
    <p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">Seul le résultat validé alimente les statistiques physiques du lot. Les contacts téléphoniques restent indépendants. « Non vérifiable » ne compte ni comme satisfaisant ni comme non satisfaisant. Aucun envoi externe automatique.</p>
    <div class="mt-5 space-y-4">
        @forelse ($dossier->operations as $operation)
            @php
                $controlState = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
                $outcomes = \App\Services\Crm\PostVisitWorkflow::OUTCOMES;
                $candidates = $visitCandidates->get($operation->id, collect());
                $canReview = auth()->user()->can('reviewControl', $dossier);
                $failedOperation = (string) old('_operation') === (string) $operation->id;
                $currentOutcome = $outcomes[$operation->control_outcome] ?? null;
            @endphp
            <article class="rounded-xl border border-slate-200 p-4" aria-label="Contrôle de la fiche {{ $operation->id }}">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold">{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation à rapprocher' }}</h3>
                        <p class="mt-1 text-xs text-slate-500">Fiche #{{ $operation->id }} @if ($operation->inspected_on) · Visite du {{ $operation->inspected_on->format('d/m/Y') }} @endif</p>
                    </div>
                    <x-dossier-status :status="$operation->legacy_status" />
                </header>
                @if ($currentOutcome)
                    <dl class="mt-4 grid gap-3 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">{{ $operation->control_validated_at ? 'Conclusion validée' : 'Conclusion proposée, non validée' }}</dt><dd class="mt-1 font-semibold">{{ $currentOutcome }}</dd></div>
                        <div><dt class="text-slate-500">{{ $operation->control_validated_at ? 'Validé par' : 'Soumis au contrôle RT' }}</dt><dd class="mt-1">{{ $operation->control_validated_at ? ($operation->controlValidator?->full_name ?: 'Responsable') : '' }} {{ ($operation->control_validated_at ?? $operation->control_submitted_at)?->format('d/m/Y H:i') }}</dd></div>
                    </dl>
                    @if ($operation->control_comment)<x-comment-body :body="$operation->control_comment" class="mt-3 text-sm" />@endif
                @elseif (in_array($controlState, ['rt_control', 'report', 'invoiced']))
                    <p class="mt-3 text-sm text-amber-800">État historique importé : aucune conclusion native validée n’est déduite automatiquement des archives Coffrac.</p>
                    @if ($controlState === 'rt_control')<p class="mt-2 text-sm text-slate-600">Utilise « Renseigner / corriger la conclusion » pour reprendre ce contrôle historique, sans demander de correction au client.</p>@endif
                @endif
                @if ($operation->reportDocument)
                    <a class="mt-3 inline-block text-sm underline" href="{{ route('v2.documents.download', $operation->reportDocument) }}">{{ $operation->control_validated_at ? 'Rapport validé' : 'Rapport précédent' }} : {{ $operation->reportDocument->name }}</a>
                @endif

                <a href="#fiche-{{ $operation->id }}" class="gc-btn-primary mt-4"><x-nav-icon name="edit" />Changer l’état depuis la fiche</a>
                @if ($controlState === 'non_compliant')<p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Action attendue du client : transmettre un commentaire ou un justificatif depuis son espace. Le recontrôle démarrera après cet envoi explicite.</p>@endif
                @if ($controlState === 'recontrol')<p class="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">Réponse reçue du client. Consulte les documents et commentaires partagés, puis décide d’une nouvelle correction ou de la validation du rapport.</p>@endif
                @if (in_array($controlState, ['rt_control', 'recontrol'], true) && ! $canReview)<p class="mt-4 text-sm text-slate-500">En attente de décision d’un gérant ou administrateur. Aucun résultat n’alimente encore le lot.</p>@endif
                @if (in_array($controlState, ['pending', 'problem']))<p class="mt-4 text-sm text-slate-500">Planifie ou reprends le rendez-vous depuis <a href="#prestations" class="gc-link underline">Statut et fiches</a> avant de traiter la visite.</p>@endif
            </article>
        @empty
            <p class="text-sm text-slate-500">Aucune prestation à contrôler.</p>
        @endforelse
    </div>
</section>
