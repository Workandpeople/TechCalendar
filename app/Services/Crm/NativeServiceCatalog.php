<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class NativeServiceCatalog
{
    /** Reconcile the collected snapshot, without deleting old references or contacting Cofrac. */
    public function reconcile(): array
    {
        if (! config('crm.v2_enabled')) {
            throw new RuntimeException('Le catalogue natif nécessite la V2.');
        }
        $definitions = DB::table('legacy_records')->where('source_table', 'fiche_type')->orderBy('source_id')->get();
        if ($definitions->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($definitions) {
            $alreadyNative = DB::table('services')->whereNotNull('coffrac_reference')->pluck('id')->all();
            $mapping = [];
            foreach ($definitions as $definition) {
                $row = json_decode($definition->payload, true, 512, JSON_THROW_ON_ERROR);
                $name = trim($row['name']);
                if ($name === '' || mb_strlen($name) > 190) {
                    throw new RuntimeException('Libellé de prestation source invalide : '.$definition->source_id);
                }
                $existing = DB::table('services')->where('coffrac_reference', $definition->source_id)->first()
                    ?? DB::table('services')->where('type', 'COFFRAC')->where('name', $name)->first();
                if ($existing && $existing->coffrac_reference !== null && (int) $existing->coffrac_reference !== (int) $definition->source_id) {
                    throw new RuntimeException('Deux prestations source partagent le même libellé : rapprochement manuel requis.');
                }
                $oldIds = DB::table('dossier_operations')->where('legacy_service_name', $row['name'])->whereNotNull('service_id')->distinct()->pluck('service_id');
                $duration = $oldIds->count() === 1 ? DB::table('services')->where('id', $oldIds->first())->value('average_duration_minutes') : null;
                $id = $existing?->id ?? DB::table('services')->insertGetId(['type' => 'COFFRAC', 'name' => $name,
                    'average_duration_minutes' => $duration ?: 60, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('services')->where('id', $id)->update(['coffrac_reference' => $definition->source_id, 'retired_at' => null]);
                $mapping[$definition->source_id] = $id;
                foreach (DB::table('service_user')->whereIn('service_id', $oldIds)->get() as $skill) {
                    DB::table('service_user')->insertOrIgnore(['service_id' => $id, 'user_id' => $skill->user_id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            // The source operation id is unambiguous, unlike the old many-to-one aliases.
            DB::table('legacy_records')->where('source_table', 'dossier_fiches')->orderBy('id')->chunkById(500, function ($records) use ($mapping, $alreadyNative) {
                $groups = [];
                foreach ($records as $record) {
                    $source = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
                    $serviceId = $mapping[$source['fiche_id']] ?? null;
                    if (! $serviceId) {
                        continue;
                    }
                    $groups[$serviceId][] = (string) $record->source_id;
                }
                foreach ($groups as $serviceId => $references) {
                    $operations = DB::table('dossier_operations')->where('source', 'coffrac')->whereIn('source_reference', $references)
                        ->where(fn ($query) => $query->whereNotIn('service_id', $alreadyNative)->orWhereNull('service_id'))->pluck('id');
                    if ($operations->isEmpty()) {
                        continue;
                    }
                    DB::table('dossier_operations')->whereIn('id', $operations)->update(['service_id' => $serviceId]);
                    DB::table('appointments')->whereIn('dossier_operation_id', $operations)->where('external_source', 'coffrac_archive')->update(['service_id' => $serviceId]);
                }
            });
            // Retain old services for historical foreign keys, but remove them from selection lists.
            if ($alreadyNative === []) {
                DB::table('services')->whereNull('coffrac_reference')->whereNull('retired_at')->update(['retired_at' => now()]);
            }

            return $mapping;
        }, 3);
    }
}
