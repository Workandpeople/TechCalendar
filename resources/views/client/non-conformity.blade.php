@foreach ($dossier->operations as $operation)
    @php
        $state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
        $failedResponse = (string) old('_operation') === (string) $operation->id;
        $prefix = 'correction-'.$operation->id;
        $serviceName = $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation';
    @endphp
    @continue (! in_array($state, ['non_compliant', 'recontrol'], true))
    <article class="gc-portal-correction" data-pending="{{ $state === 'non_compliant' ? 'true' : 'false' }}" aria-labelledby="{{ $prefix }}-heading">
        <header><div><span class="gc-portal-icon"><x-nav-icon :name="$state === 'non_compliant' ? 'alert' : 'search'" /></span><h3 id="{{ $prefix }}-heading">{{ $serviceName }}</h3></div><x-dossier-status :status="$operation->legacy_status" /></header>
        @if ($state === 'recontrol')
            <p>En cours de recontrôle par notre équipe. Aucune nouvelle transmission n’est nécessaire pour le moment.</p>
        @else
            <p>Une correction est attendue. Préparez les éléments demandés avant de solliciter une nouvelle vérification.</p>
            <ol class="gc-portal-steps" aria-label="Comment répondre à la non-conformité">
                <li><span>1</span><div><strong>Consultez</strong><p>Relisez les éléments partagés.</p></div></li>
                <li><span>2</span><div><strong>Complétez</strong><p>Préparez vos justificatifs.</p></div></li>
                <li><span>3</span><div><strong>Transmettez</strong><p>Demandez le recontrôle.</p></div></li>
            </ol>
            <div class="gc-portal-correction-actions">
                <div>
                    @if ($canUpload)<a href="#client-document-upload" class="gc-btn-secondary"><x-nav-icon name="upload" />Ajouter un document</a>@endif
                    @if ($canRespond)<a href="#{{ $prefix }}" class="gc-btn-warning"><x-nav-icon name="check" />Demander le recontrôle</a>@endif
                </div>
                @if ($documents !== null)<a href="#client-documents" class="gc-portal-text-link">Voir les documents partagés <span aria-hidden="true">↓</span></a>@endif
            </div>
            @if ($canUpload)<p class="gc-portal-muted">Vous pouvez ajouter plusieurs pièces, une à une. L’ajout seul ne déclenche pas le recontrôle.</p>@endif
            @if (! $canRespond)<p class="gc-portal-muted">Votre compte ne permet pas de transmettre une réponse. Contactez notre équipe pour faire vérifier vos droits.</p>@endif
        @endif
    </article>
    @if ($state === 'non_compliant')
        @if ($canRespond)
            <x-dossier-dialog :id="$prefix" title="Demander le recontrôle" :reopen="$failedResponse">
                <form method="POST" enctype="multipart/form-data" action="{{ route('client.dossiers.recontrol', [$dossier, $operation]) }}" class="gc-portal-form">
                    @csrf
                    <input type="hidden" name="version" value="{{ $dossier->version }}">
                    <input type="hidden" name="_operation" value="{{ $operation->id }}">
                    <div class="gc-portal-form-intro"><strong>{{ $serviceName }}</strong><p>Vos corrections sont prêtes ? Cette demande transmet la prestation à notre équipe pour une nouvelle vérification. Elle ne valide pas le contrôle.</p></div>
                    @if ($canComment)<div><label class="gc-label" for="{{ $prefix }}-comment">Votre réponse (facultatif)</label><textarea class="gc-input" id="{{ $prefix }}-comment" name="comment" rows="4" maxlength="10000">{{ $failedResponse ? old('comment') : '' }}</textarea></div>@endif
                    @if ($canUpload)
                        <div class="gc-portal-file-input"><label class="gc-label" for="{{ $prefix }}-document">Justificatif (10 Mo maximum)</label><input type="file" id="{{ $prefix }}-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"><p>Facultatif si vos pièces ont déjà été ajoutées au dossier.</p></div>
                        <div><label class="gc-label" for="{{ $prefix }}-name">Nom du document (facultatif)</label><input class="gc-input" id="{{ $prefix }}-name" name="document_name" maxlength="190" value="{{ $failedResponse ? old('document_name') : '' }}"></div>
                    @endif
                    <p class="gc-portal-muted">Les éléments transmis sont partagés avec notre équipe et les comptes autorisés de votre organisation.</p>
                    <footer class="gc-dialog-actions"><button class="gc-btn-secondary" type="button" data-dialog-close>Pas encore</button><button class="gc-btn-warning" type="submit"><x-nav-icon name="check" />Transmettre pour recontrôle</button></footer>
                </form>
            </x-dossier-dialog>
        @endif
    @endif
@endforeach
