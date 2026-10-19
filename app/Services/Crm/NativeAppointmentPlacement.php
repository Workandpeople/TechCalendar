<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NativeAppointmentPlacement
{
    /** Runs inside the booking transaction: no HTTP calls or document copies. */
    public function place(User $actor, Appointment $appointment, array $request): void
    {
        Gate::forUser($actor)->authorize('create', Dossier::class);
        if (isset($request['dossier_operation_id'])) {
            app(DossierBooking::class)->markPlaced($actor, $appointment, $request);

            return;
        }

        $row = null;
        if (! empty($request['lot_appointment_id'])) {
            $row = LotAppointment::query()->with('lot')->lockForUpdate()->findOrFail($request['lot_appointment_id']);
            $row->setRelation('lot', Lot::query()->lockForUpdate()->findOrFail($row->lot_id));
            if ($row->appointment_id || $row->excluded_from_lot_stats
                || ! in_array($row->status, [LotAppointment::STATUS_PENDING, LotAppointment::STATUS_NEEDS_REVIEW], true)
                || ! $row->lot?->supportsPhysicalProcessing() || Lot::isArchivedStatus($row->lot->status)) {
                throw ValidationException::withMessages(['lot_appointment_id' => 'Ce dossier du lot ne peut plus être placé. Actualise la liste.']);
            }
        }

        $operation = $appointment->dossier_operation_id
            ? DossierOperation::query()->lockForUpdate()->findOrFail($appointment->dossier_operation_id)
            : null;
        if (! $operation && ! $row && in_array($appointment->external_source, ['coffrac', 'coffrac_archive'], true)) {
            throw ValidationException::withMessages(['replace_appointment_id' => 'Rattache ce rendez-vous historique à sa fiche dossier avant de le replacer. Aucun doublon créé.']);
        }
        if (! $operation) {
            $source = $row ? 'lot' : 'appointment';
            $sourceId = (string) ($row?->id ?? $appointment->id);
            $dossier = Dossier::query()->where('source', $source)->where('source_reference', $sourceId)->lockForUpdate()->first();
            if (! $dossier) {
                $dossier = $this->createDossier($actor, $appointment, $row, $source, $sourceId);
            }
            $operation = $dossier->operations()->where('source', $source)->where('source_reference', $sourceId)->first();
            $operation ??= $dossier->operations()->create([
                'service_id' => $appointment->service_id, 'source' => $source, 'source_reference' => $sourceId,
            ]);
        }
        $dossier = Dossier::query()->lockForUpdate()->findOrFail($operation->dossier_id);
        Gate::forUser($actor)->authorize('update', $dossier);
        if ($operation->legacy_status && ! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], ['pending', 'problem', 'awaiting_visit'], true)) {
            throw ValidationException::withMessages(['replace_appointment_id' => 'Cette prestation est déjà dans le circuit de contrôle ou de clôture. Son état ne peut pas être écrasé par une replanification.']);
        }
        if ($operation->appointments()->whereKeyNot($appointment->id)->where('status', Appointment::STATUS_SCHEDULED)->exists()) {
            throw ValidationException::withMessages(['lot_appointment_id' => 'Cette prestation possède déjà un rendez-vous planifié. Replace le rendez-vous existant.']);
        }
        $appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
        $previousStatus = $operation->legacy_status;
        $operation->forceFill(['service_id' => $appointment->service_id, 'legacy_status' => 'RDV ATTENTE VISITE'])->save();
        if ($row) {
            $row->update(['appointment_id' => $appointment->id, 'service_id' => $appointment->service_id,
                'status' => LotAppointment::STATUS_PLACED, 'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL]);
        }
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'appointment_linked',
            'body' => 'RDV #'.$appointment->id.' '.(! empty($request['is_replacement']) ? 'replacé' : 'placé').' le '.$appointment->starts_at->format('d/m/Y H:i').' : RDV en attente de visite.',
            'changed_fields' => ['operation_id' => $operation->id, 'appointment_id' => $appointment->id,
                'previous_status' => $previousStatus, 'status' => $operation->legacy_status]]);
        if (filled($appointment->comment)) {
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $appointment->comment]);
        }
        $dossier->increment('version');
    }

    private function createDossier(User $actor, Appointment $appointment, ?LotAppointment $row, string $source, string $sourceId): Dossier
    {
        preg_match('/\b(\d{5})\s+([^,]+)/u', $appointment->address, $location, PREG_OFFSET_CAPTURE);
        $street = isset($location[0]) ? rtrim(substr($appointment->address, 0, $location[0][1]), " ,\t\n\r") : $appointment->address;
        // Historical lots store a name, not an ID. Ambiguous names must not be guessed.
        $delegataires = $row && ! $row->lot->delegataire_id && filled($row->lot->delegataire)
            ? ExternalDelegataire::query()->where('name', $row->lot->delegataire)->get(['id', 'name'])
                ->filter(fn ($client) => $client->name === $row->lot->delegataire)->pluck('id') : collect();
        $ownerId = $row?->lot?->delegataire_id ?? ($delegataires->count() === 1 ? $delegataires->first() : null);
        $owner = $ownerId ? ExternalDelegataire::find($ownerId) : null;
        $dossier = Dossier::create([
            'reference' => 'DOS-'.strtoupper($source).'-'.$sourceId, 'source' => $source, 'source_reference' => $sourceId,
            'created_by' => $actor->id, 'delegataire_id' => $owner?->kind === 'delegataire' ? $ownerId : null,
            'client_id' => $owner?->kind === 'client' ? $ownerId : null,
            'company_name' => $row?->company_name, 'first_name' => $row ? $row->customer_first_name : $appointment->customer_first_name,
            'last_name' => $row ? ($row->customer_last_name ?: ($row->company_name ? null : $row->customer_name)) : $appointment->customer_last_name,
            'phone' => $appointment->customer_phone, 'email' => $row?->customer_email, 'site_name' => $row?->site_name,
            'address' => $row?->address ?? ($street ?: $appointment->address),
            'postal_code' => $row?->postal_code ?? ($location[1][0] ?? null), 'city' => $row?->city ?? trim($location[2][0] ?? ''),
            'beneficiary_address' => $row?->beneficiary_address, 'beneficiary_postal_code' => $row?->beneficiary_postal_code,
            'beneficiary_city' => $row?->beneficiary_city, 'installer_name' => $row?->installer_name, 'installer_siren' => $row?->installer_siren,
        ]);
        if ($row) {
            $dossier->forceFill(['legacy_lot_name' => $row->lot->name])->save();
        }
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'created',
            'body' => $row ? 'Dossier créé depuis le lot '.$row->lot->name.'. Référence interne : '.($row->internalReference() ?: 'non renseignée').'.' : 'Dossier créé lors de la prise de rendez-vous manuelle.']);

        return $dossier;
    }
}
