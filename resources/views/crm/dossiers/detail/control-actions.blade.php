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
    <button type="submit" class="gc-btn-success"><x-nav-icon name="check" />Enregistrer l’état</button>
</form>
