@php
    $canAssignPlanning = $dossier->operations->contains(fn ($operation) => in_array($operation->legacy_status, \App\Services\Crm\DossierStatusCatalog::STATUSES['visited']['values'], true));
@endphp
@if ($canAssignPlanning)
    <div data-planning-assignment data-assignment-id="{{ $dossier->id }}"
        data-assignment-url="{{ route('v2.dossiers.planning-assignment', $dossier) }}"
        data-version="{{ $dossier->version }}" data-assigned-id="{{ $dossier->planning_user_id }}">
        <select class="gc-input gc-planning-assignment" data-planning-assignee
            aria-label="Attribuer le suivi planning du dossier {{ $dossier->reference }}"
            aria-describedby="assignment-feedback-{{ $dossier->id }}" @disabled($planningUsers->isEmpty() && ! $dossier->planning_user_id)>
            <option value="">Non attribué</option>
            @if ($dossier->planning_user_id && ! $planningUsers->contains('id', $dossier->planning_user_id))
                <option value="{{ $dossier->planning_user_id }}" selected disabled>{{ $dossier->planningUser?->full_name ?: 'Ancien utilisateur' }} (indisponible)</option>
            @endif
            @foreach ($planningUsers as $planningUser)
                <option value="{{ $planningUser->id }}" @selected($dossier->planning_user_id === $planningUser->id)>{{ $planningUser->full_name }}</option>
            @endforeach
        </select>
        <p id="assignment-feedback-{{ $dossier->id }}" data-assignment-feedback class="gc-dossier-cell-muted" role="status" aria-live="polite">{{ $planningUsers->isEmpty() ? 'Aucun utilisateur planning disponible.' : '' }}</p>
        <button type="button" data-assignment-refresh hidden class="mt-1 text-xs underline">Actualiser</button>
    </div>
@else
    <span class="gc-dossier-cell-lines">{{ $dossier->planningUser?->full_name ?: '—' }}</span>
@endif
