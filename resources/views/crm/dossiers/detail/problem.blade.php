<a href="#probleme-{{ $operation->id }}" class="gc-btn-danger"><x-nav-icon name="comment" />Problème RDV</a>
<x-dossier-dialog :id="'probleme-'.$operation->id" title="Signaler un problème de rendez-vous" :reopen="$errors->any() && old('_form') === 'problem-'.$operation->id">
    <form method="POST" action="{{ route('v2.dossiers.operations.problem', [$dossier, $operation]) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="problem-{{ $operation->id }}">
        <input type="hidden" name="version" value="{{ $dossier->version }}">
        @if ($linkedAppointment)<input type="hidden" name="appointment_id" value="{{ $linkedAppointment->id }}"><p>RDV du {{ $linkedAppointment->starts_at?->format('d/m/Y H:i') }}</p>@endif
        @php($failedProblem = old('_form') === 'problem-'.$operation->id)
        <label class="gc-label">Type de problème
            <select class="gc-input" name="problem_type" required><option value="">Choisir</option>
                @foreach (app(\App\Services\CoffracAppointmentService::class)->problemTypes(allowRemote: false) as $type)
                    <option value="{{ $type['value'] }}" @selected($failedProblem && old('problem_type') === $type['value'])>{{ $type['label'] }}</option>
                @endforeach
            </select>
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="gc-label">Date de rappel<input class="gc-input" type="date" name="recall_date" value="{{ $failedProblem ? old('recall_date') : '' }}"></label>
            <label class="gc-label">Heure de rappel<input class="gc-input" type="time" name="recall_time" value="{{ $failedProblem ? old('recall_time') : '' }}"></label>
        </div>
        <p class="text-xs text-slate-500">Rappel facultatif, sauf pour le motif « Demande de rappel » qui exige une date et une heure. Il est visible avec le commentaire selon la portée choisie.</p>
        <label class="gc-label">Commentaire<textarea class="gc-input" name="comment" rows="4" minlength="3" maxlength="5000" required>{{ $failedProblem ? old('comment') : '' }}</textarea></label>
        <x-content-audience :value="$failedProblem ? old('audience', 'client') : 'client'" />
        <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button type="submit" class="gc-btn-danger">Enregistrer le problème</button></footer>
    </form>
</x-dossier-dialog>
