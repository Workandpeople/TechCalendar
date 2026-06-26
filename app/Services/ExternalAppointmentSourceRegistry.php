<?php

namespace App\Services;

use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use Illuminate\Support\Facades\DB;

class ExternalAppointmentSourceRegistry
{
    /**
     * @return array<int, array{key:string,label:string,refresh_label:string,reset_label:string,enabled:bool,description:string}>
     */
    public function all(): array
    {
        return [
            [
                'key' => CoffracAppointmentService::SOURCE,
                'label' => 'Coffrac',
                'refresh_label' => 'Actualiser Coffrac',
                'reset_label' => 'Réinitialiser Coffrac',
                'enabled' => true,
                'description' => 'Vide le cache local des RDV récupérés depuis Coffrac. Les RDV déjà créés dans TechCalendar ne sont pas supprimés.',
            ],
            [
                'key' => 'external_app_2',
                'label' => 'Connecteur 2',
                'refresh_label' => 'Actualiser connecteur 2',
                'reset_label' => 'Réinitialiser connecteur 2',
                'enabled' => false,
                'description' => 'Emplacement préparé pour une future application externe.',
            ],
            [
                'key' => 'external_app_3',
                'label' => 'Connecteur 3',
                'refresh_label' => 'Actualiser connecteur 3',
                'reset_label' => 'Réinitialiser connecteur 3',
                'enabled' => false,
                'description' => 'Emplacement préparé pour une future application externe.',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resetRows(): array
    {
        return collect($this->all())
            ->map(function (array $source): array {
                $source['appointments_count'] = ExternalAppointmentRequest::query()
                    ->where('source', $source['key'])
                    ->count();
                $source['has_sync_state'] = ExternalApiSync::query()
                    ->where('source', $source['key'])
                    ->exists();

                return $source;
            })
            ->values()
            ->all();
    }

    /**
     * @return array{deleted_appointments:int, deleted_sync_states:int}
     */
    public function resetLocalCache(string $source): array
    {
        return DB::transaction(function () use ($source): array {
            $deletedAppointments = ExternalAppointmentRequest::query()
                ->where('source', $source)
                ->delete();
            $deletedSyncStates = ExternalApiSync::query()
                ->where('source', $source)
                ->delete();

            return [
                'deleted_appointments' => $deletedAppointments,
                'deleted_sync_states' => $deletedSyncStates,
            ];
        });
    }

    public function label(string $source): string
    {
        $sourceDefinition = collect($this->all())->firstWhere('key', $source);

        return is_array($sourceDefinition) ? $sourceDefinition['label'] : $source;
    }
}
