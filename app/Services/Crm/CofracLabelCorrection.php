<?php

namespace App\Services\Crm;

use App\Support\CofracText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CofracLabelCorrection
{
    // Display text only. Source identifiers, references, JSON evidence and file paths are immutable.
    public const FIELDS = [
        'users' => ['first_name', 'last_name'],
        'services' => ['name'],
        'mail_templates' => ['name', 'subject', 'markdown_body'],
        'mail_senders' => ['name', 'mail_from_name'],
        'dossier_documents' => ['name', 'description'],
        'dossier_activities' => ['body'],
        'dossier_operations' => ['legacy_service_name'],
        'dossiers' => ['company_name', 'first_name', 'last_name', 'site_name', 'installer_name', 'legacy_label', 'legacy_lot_name'],
        'external_delegataires' => ['name', 'company_name'],
        'appointments' => ['comment', 'customer_first_name', 'customer_last_name', 'company_name', 'site_name', 'installer_name'],
        'lots' => ['name', 'comment', 'delegataire'],
        'lot_appointments' => ['customer_name', 'customer_first_name', 'customer_last_name', 'company_name', 'site_name', 'installer_name', 'service_name', 'comment', 'contact_comment'],
        'lot_appointment_documents' => ['name', 'original_name'],
    ];

    public function run(?string $backup = null): array
    {
        $this->checkCatalogCollisions();
        $journal = $backup === null ? null : fopen($backup, 'xb');
        if ($backup !== null && (! $journal || ! chmod($backup, 0600))) {
            throw new RuntimeException('Impossible de créer la sauvegarde privée.');
        }
        $counts = [];
        try {
            foreach (self::FIELDS as $table => $candidates) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $columns = array_values(array_intersect($candidates, Schema::getColumnListing($table)));
                if ($columns === []) {
                    continue;
                }
                DB::table($table)->select(['id', ...$columns])->where(function ($query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhereRaw('LOWER('.DB::connection()->getQueryGrammar()->wrap($column).') LIKE ?', ['%coffrac%']);
                    }
                })->chunkById(250, function ($rows) use ($journal, $table, $columns, &$counts): void {
                    DB::transaction(function () use ($rows, $journal, $table, $columns, &$counts): void {
                        $current = $journal ? DB::table($table)->whereIn('id', $rows->pluck('id'))->lockForUpdate()->get(['id', ...$columns]) : $rows;
                        $changes = [];
                        foreach ($current as $row) {
                            $before = $after = [];
                            foreach ($columns as $column) {
                                $value = $row->$column;
                                $normalized = CofracText::normalize($value);
                                if ($normalized !== $value) {
                                    $before[$column] = $value;
                                    $after[$column] = $normalized;
                                }
                            }
                            if ($after !== []) {
                                $changes[] = ['table' => $table, 'id' => $row->id, 'before' => $before, 'after' => $after];
                            }
                        }
                        if ($journal && $changes !== []) {
                            foreach ($changes as $change) {
                                $line = json_encode($change, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                                if (fwrite($journal, $line) !== strlen($line)) {
                                    throw new RuntimeException('Sauvegarde incomplète ; lot annulé.');
                                }
                            }
                            // Persist the preimages before committing any corresponding SQL changes.
                            if (! fflush($journal) || ! fsync($journal)) {
                                throw new RuntimeException('Sauvegarde non synchronisée ; lot annulé.');
                            }
                        }
                        foreach ($changes as $change) {
                            if ($journal) {
                                DB::table($table)->where('id', $change['id'])->update($change['after']);
                            }
                            $counts[$table] = ($counts[$table] ?? 0) + 1;
                        }
                    });
                });
            }
        } finally {
            if ($journal) {
                fclose($journal);
            }
        }

        return $counts;
    }

    private function checkCatalogCollisions(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }
        $seen = [];
        foreach (DB::table('services')->get(['id', 'type', 'name']) as $service) {
            $name = CofracText::normalize($service->name);
            $key = mb_strtolower($service->type."\0".$name);
            if (isset($seen[$key]) || ($name !== $service->name && DB::table('services')->where('type', $service->type)
                ->where('name', $name)->where('id', '!=', $service->id)->exists())) {
                throw new \DomainException('Correction annulée avant écriture : deux prestations auraient le même nom. Aucun identifiant ne sera fusionné automatiquement.');
            }
            $seen[$key] = true;
        }
    }

    public function restore(string $backup): array
    {
        $file = fopen($backup, 'rb');
        if (! $file) {
            throw new RuntimeException('Sauvegarde introuvable.');
        }
        $counts = ['restored' => 0, 'already_restored' => 0, 'conflicts' => 0];
        try {
            while (($line = fgets($file, 8 * 1024 * 1024)) !== false) {
                $change = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $table = $change['table'] ?? '';
                $columns = array_keys($change['before'] ?? []);
                if (! isset(self::FIELDS[$table]) || ! is_int($change['id'] ?? null) || $columns === []
                    || array_diff($columns, self::FIELDS[$table]) !== [] || $columns !== array_keys($change['after'] ?? [])) {
                    throw new RuntimeException('Format de sauvegarde refusé.');
                }
                foreach ($columns as $column) {
                    if (! is_string($change['before'][$column]) || CofracText::normalize($change['before'][$column]) !== $change['after'][$column]) {
                        throw new RuntimeException('Transformation de sauvegarde refusée.');
                    }
                }
                DB::transaction(function () use ($table, $change, $columns, &$counts): void {
                    $row = DB::table($table)->where('id', $change['id'])->lockForUpdate()->first($columns);
                    $values = $row ? (array) $row : null;
                    if ($values === $change['before']) {
                        $counts['already_restored']++;
                    } elseif ($values === $change['after']) {
                        DB::table($table)->where('id', $change['id'])->update($change['before']);
                        $counts['restored']++;
                    } else {
                        // A later edit wins over rollback. Never overwrite a user's work.
                        $counts['conflicts']++;
                    }
                });
            }
        } finally {
            fclose($file);
        }

        return $counts;
    }
}
