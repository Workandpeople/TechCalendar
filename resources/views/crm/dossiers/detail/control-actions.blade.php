@php
    $manualStates = \App\Services\Crm\DossierStatusCatalog::manualStates();
    $failedOperation = old('_form') === 'manual-state' && (string) old('_operation') === (string) $operation->id;
@endphp
<form method="POST" action="{{ route('v2.dossiers.operations.status', [$dossier, $operation]) }}" class="space-y-4">
    @csrf @method('PATCH')
    <input type="hidden" name="version" value="{{ $dossier->version }}">
    <input type="hidden" name="_operation" value="{{ $operation->id }}">
    <input type="hidden" name="_form" value="manual-state">
    <div><label class="gc-label" for="state-{{ $operation->id }}">Nouvel état</label><select class="gc-input" id="state-{{ $operation->id }}" name="status" required>
        @foreach ($manualStates as $key => $status)
            <option value="{{ $key }}" @selected(($failedOperation ? old('status') : $state) === $key)>{{ $status['label'] }}</option>
        @endforeach
    </select></div>
    <p class="text-xs text-slate-500">Choix manuel libre, sans document obligatoire. Les rendez-vous existants, pièces, avis, satisfaction des lots et marquage de facturation sont conservés.</p>
    <p class="text-xs text-slate-500">Le dépôt d’une visite technique ou d’un rapport conserve les changements automatiques prévus. Changer l’état ne crée pas de validation technique ni d’envoi externe.</p>
    <x-content-audience :value="$failedOperation ? old('audience', 'client') : 'client'" />
    <button type="submit" class="gc-btn-success"><x-nav-icon name="check" />Enregistrer l’état</button>
</form>
@if ($operation->control_outcome)
    <p class="mt-3 text-sm">Avis du dernier rapport : <strong>{{ \App\Services\Crm\PostVisitWorkflow::OUTCOMES[$operation->control_outcome] ?? $operation->control_outcome }}</strong></p>
@endif
