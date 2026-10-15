<?php

namespace App\Services\Crm;

use App\Models\ExternalDelegataire;
use Illuminate\Support\Facades\DB;

class LegacyClientReconciliation
{
    /** Uses the local archive only. Existing assignments and user accounts are never overwritten. */
    public function run(bool $apply = false): array
    {
        $counts = ['organisations_a_creer' => 0, 'dossiers_a_rattacher' => 0,
            'affectations_existantes_conservees' => 0, 'double_affectation_historique' => 0, 'sans_correspondance' => 0];
        $clients = [];
        $existing = ExternalDelegataire::where('source', 'coffrac')->get()->keyBy('external_id');
        foreach (DB::table('legacy_records')->where('source_table', 'users')->orderBy('id')->lazyById(200) as $record) {
            $row = json_decode($record->payload, true) ?: [];
            if (! in_array((int) ($row['type'] ?? 0), [5, 6, 7], true)) {
                continue;
            }
            $client = $existing->get((string) $record->source_id);
            if (! $client) {
                $counts['organisations_a_creer']++;
                if ($apply) {
                    $client = ExternalDelegataire::firstOrCreate(['source' => 'coffrac', 'external_id' => (string) $record->source_id], [
                        'name' => mb_substr(trim((string) (($row['nom_societe'] ?? '') ?: ($row['name'] ?? ''))) ?: 'Client Coffrac #'.$record->source_id, 0, 190),
                        'company_name' => mb_substr((string) ($row['nom_societe'] ?? ''), 0, 190) ?: null,
                        'email' => filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL) ? mb_substr($row['email'], 0, 190) : null,
                        'phone' => mb_substr((string) ($row['telephone'] ?? ''), 0, 60) ?: null,
                        'kind' => (int) $row['type'] === 7 ? 'delegataire' : 'client',
                        'is_active' => (int) ($row['actif'] ?? 0) === 1,
                    ]);
                }
            }
            $clients[(string) $record->source_id] = $client?->id;
        }
        DB::table('legacy_records')->where('source_table', 'dossiers')->orderBy('id')->chunkById(300,
            function ($records) use ($clients, $apply, &$counts): void {
                $dossiers = DB::table('dossiers')->where('source', 'coffrac')
                    ->whereIn('source_reference', $records->pluck('source_id')->map(fn ($id) => (string) $id))
                    ->get(['id', 'source_reference', 'delegataire_id'])->keyBy('source_reference');
                $assignments = [];
                foreach ($records as $record) {
                    $row = json_decode($record->payload, true) ?: [];
                    $delegataire = (string) ($row['delegataire_id'] ?? '');
                    $client = (string) ($row['client_id'] ?? '');
                    if (array_key_exists($delegataire, $clients) && array_key_exists($client, $clients) && $delegataire !== $client) {
                        $counts['double_affectation_historique']++;
                    }
                    $dossier = $dossiers->get((string) $record->source_id);
                    if (! $dossier) {
                        continue;
                    }
                    if ($dossier->delegataire_id !== null) {
                        $counts['affectations_existantes_conservees']++;

                        continue;
                    }
                    // One owner only: legacy delegataire takes precedence; no owner/parent inheritance.
                    $owner = array_key_exists($delegataire, $clients) ? $delegataire : $client;
                    if (! array_key_exists($owner, $clients)) {
                        $counts['sans_correspondance']++;

                        continue;
                    }
                    $counts['dossiers_a_rattacher']++;
                    if ($apply) {
                        $assignments[$clients[$owner]][] = $dossier->id;
                    }
                }
                foreach ($assignments as $clientId => $ids) {
                    DB::table('dossiers')->whereIn('id', $ids)->whereNull('delegataire_id')
                        ->update(['delegataire_id' => $clientId]);
                }
            });

        return $counts;
    }
}
