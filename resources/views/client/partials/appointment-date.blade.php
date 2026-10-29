@if ($appointment?->starts_at)
    @php
        $past = $appointment->ends_at && $appointment->ends_at->format('Y-m-d H:i:s') < now(\App\Services\Crm\AppointmentDateTime::TIMEZONE)->format('Y-m-d H:i:s');
        $label = $appointment->trashed() ? 'Date historique' : ($past ? 'Dernier RDV enregistré' : 'RDV prévu');
        $unplanned = in_array(\App\Services\Crm\DossierStatusCatalog::describe($appointment->listing_operation_status)['key'], ['pending', 'problem', 'cancelled'], true);
        if (! $appointment->trashed() && ($unplanned || $appointment->status !== \App\Models\Appointment::STATUS_SCHEDULED)) {
            $label = $appointment->status === \App\Models\Appointment::STATUS_CANCELLED
                || \App\Services\Crm\DossierStatusCatalog::describe($appointment->listing_operation_status)['key'] === 'cancelled'
                ? 'RDV annulé' : 'Ancien créneau · à replanifier';
        }
    @endphp
    <div class="space-y-1 text-sm" data-portal-appointment="{{ $appointment->id }}">
        <p class="text-xs text-slate-500">{{ $label }}</p>
        <time class="block whitespace-nowrap font-semibold text-slate-800" datetime="{{ \App\Services\Crm\AppointmentDateTime::formatStored($appointment->starts_at) }}">{{ $appointment->starts_at->format('d/m/Y') }}</time>
        <p class="text-xs font-medium text-slate-600">
            {{ $appointment->starts_at->format('H:i') }}@if ($appointment->ends_at) à @if (! $appointment->starts_at->isSameDay($appointment->ends_at)){{ $appointment->ends_at->format('d/m/Y') }} @endif{{ $appointment->ends_at->format('H:i') }}@endif
        </p>
    </div>
@else
    <span class="text-xs text-slate-500">Aucun RDV confirmé</span>
@endif
