<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\User;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierBooking
{
    public function pending(User $actor, ?int $limit = null): Collection
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $operations = $this->query()->orderByDesc('id')->when($limit !== null, fn ($query) => $query->limit($limit))->get();
        // Reuse imported GPS in one query, without geocoding the entire backlog.
        $legacy = $operations->isEmpty() ? collect() : DB::table('legacy_records')
            ->where('source_table', 'dossiers')->whereIn('dossier_id', $operations->pluck('dossier_id'))
            ->pluck('payload', 'dossier_id');
        $maps = $operations->isEmpty() ? [] : Cache::many($operations->map(fn ($operation) => $this->mapCacheKey($operation->dossier))->unique()->all());
        $documents = app(CollectedDossierDocuments::class)->forDossiers($actor, $operations->pluck('dossier')->unique('id'));

        return $operations->map(function ($operation) use ($legacy, $maps, $documents): array {
            $source = json_decode($legacy->get($operation->dossier_id, '{}'), true);

            return $this->serialize($operation, is_array($source) ? $source : [], $maps[$this->mapCacheKey($operation->dossier)]['coordinates'] ?? null, $documents[$operation->dossier_id] ?? []);
        });
    }

    public function find(User $actor, string $id, bool $geocode = true): ?array
    {
        $operation = $this->operation($id);
        if (! $operation) {
            return null;
        }
        Gate::forUser($actor)->authorize('view', $operation->dossier);
        $legacy = DB::table('legacy_records')->where('source_table', 'dossiers')->where('dossier_id', $operation->dossier_id)->value('payload');
        $source = json_decode($legacy ?? '{}', true);
        $documents = app(CollectedDossierDocuments::class)->forDossiers($actor, collect([$operation->dossier]));
        $result = $this->serialize($operation, is_array($source) ? $source : [], Cache::get($this->mapCacheKey($operation->dossier))['coordinates'] ?? null, $documents[$operation->dossier_id] ?? []);
        if ($geocode) {
            $point = app(DossierMapPreview::class)->coordinates($actor, $operation->dossier)['coordinates'];
            $result['latitude'] = $point['latitude'] ?? null;
            $result['longitude'] = $point['longitude'] ?? null;
        }

        return $result;
    }

    public function update(User $actor, string $id, array $data): array
    {
        $operation = $this->operation($id);
        abort_unless($operation, 404, 'Ce dossier n’est plus à placer.');
        Gate::forUser($actor)->authorize('update', $operation->dossier);
        $version = $operation->dossier->version;
        $address = array_key_exists('address', $data) ? trim($data['address']) : null;
        $geocoded = $address !== null ? app(MapboxAddressGeocoder::class)->geocode($address) : null;
        if ($geocoded !== null && (! is_numeric($geocoded['latitude'] ?? null) || ! is_numeric($geocoded['longitude'] ?? null))) {
            throw ValidationException::withMessages(['address' => 'Adresse introuvable via Mapbox. Vérifie l’adresse puis réessaie.']);
        }

        $dossier = DB::transaction(function () use ($actor, $operation, $version, $data, $address, $geocoded): Dossier {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($operation->dossier_id);
            $locked = $this->lockPending($operation->id);
            if ($dossier->version !== $version) {
                throw ValidationException::withMessages(['address' => 'Le dossier a changé. Rouvre sa fiche avant de modifier.']);
            }
            if (array_key_exists('service_id', $data)) {
                $locked->update(['service_id' => $data['service_id']]);
            }
            if ($address !== null) {
                $formatted = ($geocoded['formatted_address'] ?? null) ?: $address;
                preg_match('/\b(\d{5})\s+([^,]+)/u', $formatted, $parts);
                $dossier->update([
                    'address' => trim(explode(',', $formatted)[0]),
                    'postal_code' => $parts[1] ?? $dossier->postal_code,
                    'city' => isset($parts[2]) ? trim($parts[2]) : $dossier->city,
                ]);
                $dossier->forceFill(['inspection_geocode' => DossierLocation::metadata($dossier, $geocoded)])->save();
            }
            if (filled($data['comment'] ?? null)) {
                $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => trim($data['comment'])]);
            }
            $dossier->increment('version');

            return $dossier;
        }, 3);

        if ($geocoded !== null) {
            Cache::put($this->mapCacheKey($dossier), ['coordinates' => [
                'latitude' => (float) $geocoded['latitude'], 'longitude' => (float) $geocoded['longitude'],
            ], 'message' => 'Position vérifiée par Mapbox.'], now()->addMinutes(5));
        }

        $result = $this->find($actor, $id, geocode: false);
        abort_unless($result, 404, 'Dossier mis à jour, mais il n’est plus à placer. Actualise la liste.');

        return $result;
    }

    public function markProblem(User $actor, string $id, array $data): array
    {
        $operation = $this->operation($id);
        abort_unless($operation, 404, 'Ce dossier n’est plus à placer.');
        Gate::forUser($actor)->authorize('update', $operation->dossier);
        $result = $this->serialize($operation);
        DB::transaction(function () use ($actor, $operation, $data): void {
            Dossier::query()->lockForUpdate()->findOrFail($operation->dossier_id);
            $locked = $this->lockPending($operation->id);
            app(DossierWorkflow::class)->recordProblem($actor, $locked, $data);
        }, 3);

        return [...$result, 'status' => 'problem', 'remote_status_name' => 'PROBLÈME RENDEZ-VOUS'];
    }

    /** Must be called in the same transaction as the appointment creation. */
    public function markPlaced(User $actor, Appointment $appointment, array $request): void
    {
        $dossier = Dossier::query()->lockForUpdate()->findOrFail($request['dossier_id']);
        Gate::forUser($actor)->authorize('update', $dossier);
        $operation = $this->lockPending($request['dossier_operation_id']);
        if ($operation->dossier_id !== $dossier->id || $dossier->version !== $request['dossier_version']
            || ($operation->service_id !== null && (int) $operation->service_id !== (int) $appointment->service_id)) {
            throw ValidationException::withMessages(['crm_appointment_id' => 'Le dossier a changé. Relance la recherche avant de le placer.']);
        }
        $appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
        $previousStatus = $operation->legacy_status;
        $operation->forceFill(['service_id' => $appointment->service_id, 'legacy_status' => 'RDV ATTENTE VISITE'])->save();
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'appointment_linked',
            'body' => 'RDV #'.$appointment->id.' placé le '.$appointment->starts_at->format('d/m/Y H:i').' : RDV en attente de visite.',
            'changed_fields' => ['operation_id' => $operation->id, 'appointment_id' => $appointment->id,
                'previous_status' => $previousStatus, 'status' => $operation->legacy_status]]);
        if (filled($appointment->comment)) {
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $appointment->comment]);
        }
        $dossier->increment('version');
    }

    private function query(bool $onlyUnscheduled = false): Builder
    {
        return DossierOperation::query()->when($onlyUnscheduled,
            fn ($query) => $query->awaitingBooking(), fn ($query) => $query->pendingStatus())->with([
                'service', 'dossier.client', 'dossier.delegataire', 'dossier.documents',
                'appointments' => fn ($query) => $query->where('status', Appointment::STATUS_SCHEDULED)
                    ->orderByDesc('starts_at')->orderByDesc('id')->limit(1),
                'dossier.comments' => fn ($query) => $query->with('author:id,first_name,last_name')->orderByDesc('created_at')->orderByDesc('id')->limit(10),
            ]);
    }

    private function operation(string $id): ?DossierOperation
    {
        return preg_match('/^dossier-([1-9][0-9]*)$/D', $id, $matches) ? $this->query(onlyUnscheduled: true)->find($matches[1]) : null;
    }

    private function lockPending(int $id): DossierOperation
    {
        $operation = DossierOperation::query()->lockForUpdate()->findOrFail($id);
        if (! in_array($operation->legacy_status, DossierStatusCatalog::STATUSES['pending']['values'], true)
            || $operation->appointments()->where('status', Appointment::STATUS_SCHEDULED)->exists()) {
            throw ValidationException::withMessages(['crm_appointment_id' => 'Ce dossier n’est plus à placer. Actualise la liste.']);
        }

        return $operation;
    }

    private function address(Dossier $dossier): string
    {
        return DossierLocation::address($dossier);
    }

    private function mapCacheKey(Dossier $dossier): string
    {
        return 'dossier-map-preview:'.$dossier->id.':'.hash('sha256', $this->address($dossier));
    }

    public function summary(DossierOperation $operation): array
    {
        $operation->loadMissing(['service', 'dossier.client', 'dossier.delegataire']);
        $dossier = $operation->dossier;
        $display = app(DossierTable::class);
        $companies = $display->companies($dossier);

        return [
            'beneficiary' => $dossier->beneficiaryLabel(), 'email' => $dossier->email,
            'phone_numbers' => $display->phoneNumbers($dossier), 'address' => $display->address($dossier)['full'],
            'service' => $operation->service?->name ?: $operation->legacy_service_name,
            'status' => DossierStatusCatalog::describe($operation->legacy_status)['label'],
            'client' => $companies['client'], 'delegataire' => $companies['delegataire'],
            'site' => $dossier->site_name, 'installer' => $dossier->installer_name, 'lot' => $dossier->legacy_lot_name,
            'dossier_url' => route('v2.dossiers.show', $dossier, absolute: false),
        ];
    }

    private function serialize(DossierOperation $operation, array $legacy = [], ?array $point = null, array $collectedDocuments = []): array
    {
        $dossier = $operation->dossier;
        $point = DossierLocation::coordinates($dossier) ?? $point;
        if ($point === null && trim($legacy['adress'] ?? '') === trim($dossier->address)
            && trim($legacy['ville'] ?? '') === trim($dossier->city) && trim($legacy['zip'] ?? '') === trim($dossier->postal_code ?? '')
            && is_numeric($legacy['latitude'] ?? null) && is_numeric($legacy['longitude'] ?? null)
            && abs((float) $legacy['latitude']) <= 90 && abs((float) $legacy['longitude']) <= 180
            && ((float) $legacy['latitude'] !== 0.0 || (float) $legacy['longitude'] !== 0.0)) {
            $point = ['latitude' => (float) $legacy['latitude'], 'longitude' => (float) $legacy['longitude']];
        }
        $postalCode = (string) $dossier->postal_code;
        $department = str_starts_with($postalCode, '20') ? ((int) $postalCode < 20200 ? '2A' : '2B')
            : substr($postalCode, 0, preg_match('/^9[78]/', $postalCode) ? 3 : 2);
        $service = $operation->service?->only(['id', 'name', 'type', 'average_duration_minutes']);
        $existingAppointment = $operation->appointments->first();
        $companies = app(DossierTable::class)->companies($dossier);

        return [
            'id' => 'dossier-'.$operation->id,
            'dossier_id' => $dossier->id, 'dossier_operation_id' => $operation->id, 'dossier_version' => $dossier->version,
            'replace_appointment_id' => $existingAppointment?->id,
            'existing_appointment_at' => $existingAppointment?->starts_at?->toIso8601String(),
            'dossier_summary' => $this->summary($operation),
            'source' => 'Dossier TechCalendar', 'external_source' => null,
            'external_reference' => $dossier->reference, 'external_payload' => null,
            'status' => 'pending', 'remote_status_name' => $operation->legacy_status,
            'created_at' => $dossier->created_at?->toIso8601String(),
            'first_name' => $dossier->company_name ? '' : ($dossier->first_name ?? ''),
            'last_name' => $dossier->company_name ?: ($dossier->last_name ?: $dossier->beneficiaryLabel()),
            'company_name' => $companies['client'] ?: $companies['delegataire'],
            'client_company_name' => $companies['client'], 'delegataire_company_name' => $companies['delegataire'],
            'beneficiary_name' => $dossier->beneficiaryLabel(), 'site_name' => $dossier->site_name,
            'installer_name' => $dossier->installer_name, 'phone' => $dossier->phone ?? '',
            'address' => $this->address($dossier), 'address_line' => $dossier->address,
            'postal_code' => $dossier->postal_code, 'city' => $dossier->city, 'department_code' => $department,
            'latitude' => $point['latitude'] ?? null, 'longitude' => $point['longitude'] ?? null,
            'is_manual' => false, 'is_lot' => false, 'preferred_starts_at' => null,
            'comment' => '',
            'comments' => $dossier->comments->map(fn ($comment) => [
                'id' => $comment->id, 'body' => $comment->body, 'comment' => $comment->body,
                'author_name' => $comment->author?->full_name ?: 'TechCalendar', 'created_at' => $comment->created_at?->toIso8601String(),
            ])->all(),
            'documents' => [...$dossier->documents->map(fn ($document) => [
                'id' => $document->id, 'name' => $document->name, 'mime_type' => $document->mime_type,
                'url' => route('v2.documents.download', $document), 'size' => $document->size,
            ])->all(), ...$collectedDocuments],
            'service' => $service, 'service_type' => $service['type'] ?? null,
            'service_name' => $service['name'] ?? $operation->legacy_service_name,
            'service_display_name' => $service['name'] ?? $operation->legacy_service_name,
        ];
    }
}
