<form method="POST" action="{{ route('v2.dossiers.appointments.technician', [$dossier, $appointment->id]) }}"
      data-appointment-editor="{{ $appointment->id }}" data-operation="{{ $appointment->dossier_operation_id }}"
      data-dossier-id="{{ $dossier->id }}" data-technician-id="{{ $appointment->technician_id }}"
      @if($auto ?? false) data-technician-auto @endif class="gc-technician-assignment space-y-2">
    @csrf @method('PATCH')
    <input type="hidden" name="version" value="{{ $dossier->version }}">
    <input type="hidden" name="updated_at" value="{{ $appointment->updated_at?->toIso8601String() }}">
    <label class="sr-only" for="appointment-tech-{{ $appointment->id }}">Technicien du rendez-vous #{{ $appointment->id }}</label>
    <select class="gc-input" id="appointment-tech-{{ $appointment->id }}" name="technician_id" required>
        @if (! $technicians->contains('id', $appointment->technician_id))<option value="{{ $appointment->technician_id }}" selected disabled>Technicien actuel indisponible</option>@endif
        @foreach ($technicians as $technician)
            <option value="{{ $technician->id }}" @selected($technician->id === $appointment->technician_id)>{{ $technician->full_name }}{{ $technician->services->contains('id', $appointment->service_id) ? '' : ' · Non habilité' }}</option>
        @endforeach
    </select>
    @unless($auto ?? false)<button type="submit" class="gc-btn-warning">Enregistrer le technicien</button>@endunless
    <p role="status" class="text-xs text-slate-600" data-technician-status></p>
</form>
