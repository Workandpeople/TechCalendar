<?php

namespace App\Services\Crm;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyBillingReconciliation
{
    private const CHUNK_SIZE = 200;

    /** Counts are distinct operations, distinct review dossiers, and invalid source rows (which may overlap). */
    public function run(bool $apply = false): array
    {
        $counts = ['created' => 0, 'existing' => 0, 'review' => 0, 'invalid' => 0];
        $dossiers = DB::table('dossiers')->where('source', 'coffrac')->whereNotNull('source_reference')
            ->where('source_reference', '!=', '')->pluck('id', 'source_reference')->all();
        $localDossiers = DB::table('dossiers')->pluck('id')->flip()->all();
        $operations = [];
        foreach (DB::table('dossier_operations')->where('source', 'coffrac')->select(['id', 'dossier_id', 'source_reference'])->lazyById(self::CHUNK_SIZE) as $operation) {
            $operations[$operation->source_reference] = [(int) $operation->id, (int) $operation->dossier_id];
        }
        // Only compact ownership maps are retained, never the full source archive.
        $parents = [];
        foreach ($this->records('dossier_fiches') as $record) {
            $row = $this->payload($record->payload);
            $parents[(string) $record->source_id] = $this->sourceId($row['dossier_id'] ?? null);
        }

        $candidates = $reviews = [];
        foreach (['dossier_historique', 'dossier_fiches', 'dossier_fiche_facturation'] as $table) {
            foreach ($this->records($table) as $record) {
                $row = $this->payload($record->payload);
                if ($row === null) {
                    $counts['invalid']++;
                    if (isset($localDossiers[$record->dossier_id])) {
                        $this->review($reviews, [(int) $record->dossier_id], [
                            'source_table' => $table, 'source_id' => (string) $record->source_id,
                        ], 'malformed_payload');
                    }

                    continue;
                }
                $flag = $table === 'dossier_fiche_facturation' ? 'passer_facture' : 'status_id';
                if ($this->sourceId($row[$flag] ?? null) !== ($flag === 'passer_facture' ? '1' : '11')) {
                    continue;
                }

                [$evidence, $markedAt, $invoice, $invalid] = $this->evidence($table, (string) $record->source_id, $row);
                $reference = $table === 'dossier_fiches' ? (string) $record->source_id : $this->sourceId($row['dossier_fiche_id'] ?? null);
                $declaredParent = $this->sourceId($row['dossier_id'] ?? null);
                $sourceParent = $parents[$reference ?? ''] ?? null;
                $parentReference = $declaredParent ?? $sourceParent;
                $parentId = $dossiers[$parentReference ?? ''] ?? null;
                $operation = $operations[$reference ?? ''] ?? null;
                $reason = null;
                if (! $reference || ! $operation) {
                    $reason = 'operation_unmapped';
                } elseif (! $parentId) {
                    $reason = 'parent_unmapped';
                } elseif ((isset($row['dossier_id']) && ! $declaredParent)
                    || (array_key_exists($reference, $parents) && ! $sourceParent)) {
                    $reason = 'invalid_source_parent';
                } elseif (($declaredParent && $sourceParent && $declaredParent !== $sourceParent)
                    || $operation[1] !== (int) $parentId) {
                    $reason = 'parent_mismatch';
                }

                if ($reason !== null) {
                    // An identified operation's local parent is at risk too, even when source ownership is unprovable.
                    $knownParents = array_filter([$parentId, $dossiers[$sourceParent ?? ''] ?? null, $operation[1] ?? null]);
                    if ($knownParents === [] && isset($localDossiers[$record->dossier_id])) {
                        // This local archive pointer may block a dossier, but can never attribute a mark.
                        $knownParents[] = (int) $record->dossier_id;
                    }
                    $this->review($reviews, $knownParents, $evidence, $reason);
                    $counts['invalid']++;

                    continue;
                }

                $conflict = $this->candidate($candidates, $operation[0], (int) $parentId, $evidence, $markedAt, $invoice, $reviews);
                $counts['invalid'] += (int) ($invalid || $conflict);
            }
        }

        // A native status can predate the ledger; it is evidence of a mark, not of a payment or invoice date.
        foreach (DB::table('dossier_operations')->where('legacy_status', 'DOSSIERS FACTURER')
            ->select(['id', 'dossier_id', 'source', 'source_reference'])->lazyById(self::CHUNK_SIZE) as $operation) {
            if (isset($candidates[$operation->id])) {
                continue;
            }
            $evidence = ['source_table' => 'dossier_operations', 'source_id' => (string) $operation->id,
                'legacy_status' => 'DOSSIERS FACTURER', 'source' => $operation->source, 'source_reference' => $operation->source_reference];
            if ($operation->source !== 'native'
                && ($operation->source !== 'coffrac'
                    || ($dossiers[$parents[$operation->source_reference ?? ''] ?? ''] ?? null) !== (int) $operation->dossier_id)) {
                $this->review($reviews, [(int) $operation->dossier_id], $evidence, 'status_parent_unverified');
                $counts['invalid']++;

                continue;
            }
            $this->candidate($candidates, (int) $operation->id, (int) $operation->dossier_id, $evidence, null, null, $reviews);
        }

        $counts['review'] = count($reviews);
        if ($apply) {
            $this->persistReviews($reviews);
        }
        foreach (array_chunk($candidates, self::CHUNK_SIZE, true) as $batch) {
            $rows = [];
            foreach ($batch as $id => $candidate) {
                $rows[$id] = [...$candidate, 'evidence' => $this->json($candidate['evidence']), 'created_at' => now(), 'updated_at' => now()];
            }
            $created = $apply ? $this->persistMarks($rows) : count($rows) - DB::table('dossier_billings')->whereIn('dossier_operation_id', array_keys($rows))->count();
            $counts['created'] += $created;
            $counts['existing'] += count($rows) - $created;
        }

        return $counts;
    }

    private function records(string $table): iterable
    {
        return DB::table('legacy_records')->where('source_table', $table)
            ->select(['id', 'source_id', 'dossier_id', 'payload'])->lazyById(self::CHUNK_SIZE);
    }

    private function payload(string $payload): ?array
    {
        $row = json_decode($payload, true);

        return is_array($row) && ! array_is_list($row) ? $row : null;
    }

    private function sourceId(mixed $value): ?string
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) ? (string) $value : null;
    }

    private function evidence(string $table, string $id, array $row): array
    {
        $evidence = ['source_table' => $table, 'source_id' => $id, ...array_intersect_key($row, array_flip([
            'dossier_id', 'dossier_fiche_id', 'status_id', 'passer_facture', 'author_id',
            'created_at', 'updated_at', 'date_status_change', 'date_facture', 'num_facture',
        ]))];
        $dateField = match ($table) {
            'dossier_historique' => 'created_at',
            'dossier_fiches' => 'date_status_change',
            default => null,
        };
        $markedAt = $dateField ? $this->timestamp($row[$dateField] ?? null) : null;
        if ($dateField && ($row[$dateField] ?? null) !== null && ($row[$dateField] ?? '') !== '' && $markedAt === null) {
            $evidence['issues'][] = 'invalid_'.$dateField;
        }
        if (isset($row['author_id']) && ! in_array($row['author_id'], [0, '0', ''], true) && $this->sourceId($row['author_id']) === null) {
            $evidence['issues'][] = 'invalid_author_id';
        }
        // date_facture is an invoice date, not the date passer_facture changed. Keep it as evidence only.
        $invoice = null;
        if ($table === 'dossier_fiche_facturation' && ($row['date_facture'] ?? '') !== '' && ($row['date_facture'] ?? null) !== null) {
            $date = $row['date_facture'];
            $timestamp = is_string($date) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) ? $date.' 00:00:00' : $date;
            if ($this->timestamp($timestamp) === null) {
                $evidence['issues'][] = 'invalid_date_facture';
            }
        }
        if ($table === 'dossier_fiche_facturation' && ($row['num_facture'] ?? null) !== null) {
            $value = $row['num_facture'];
            if ((is_string($value) || is_int($value)) && mb_strlen(trim((string) $value)) <= 190) {
                $invoice = trim((string) $value) === '' ? null : trim((string) $value);
            } else {
                $evidence['issues'][] = 'invalid_num_facture';
            }
        }

        return [$evidence, $markedAt, $invoice, isset($evidence['issues'])];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{3}-/', $value)) {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s.u', 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d\\TH:i:s\\Z', 'Y-m-d\\TH:i:s.u\\Z'] as $format) {
            try {
                $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
                $errors = DateTimeImmutable::getLastErrors();
                if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $local = $date->setTimezone(new DateTimeZone(config('app.timezone', 'UTC')));

                    return (int) $local->format('Y') >= 1000 && (int) $local->format('Y') <= 9999 ? $local->format('Y-m-d H:i:s') : null;
                }
            } catch (\ValueError) {
                return null;
            }
        }

        return null;
    }

    private function candidate(array &$candidates, int $operationId, int $dossierId, array $evidence, ?string $markedAt, ?string $invoice, array &$reviews): bool
    {
        $conflict = false;
        $table = $evidence['source_table'];
        $reference = $table.':'.$evidence['source_id'];
        if (! isset($candidates[$operationId])) {
            $candidates[$operationId] = ['dossier_id' => $dossierId, 'dossier_operation_id' => $operationId,
                'marked_at' => $markedAt, 'marked_by' => null, 'invoice_reference' => $invoice,
                'source' => $table === 'dossier_operations' ? 'legacy_status' : 'coffrac', 'source_reference' => $reference,
                'evidence' => ['records' => [], 'source_record_count' => 0]];
        }
        $candidate = &$candidates[$operationId];
        $candidate['evidence']['source_record_count']++;
        // Retain one representative per source type. Every other original remains in legacy_records.
        if (! isset($candidate['evidence']['records'][$table])
            || ($markedAt !== null && ($candidate['marked_at'] === null || $markedAt < $candidate['marked_at']))) {
            $candidate['evidence']['records'][$table] = $evidence;
        }
        if ($markedAt !== null && ($candidate['marked_at'] === null || $markedAt < $candidate['marked_at'])) {
            $candidate['marked_at'] = $markedAt;
            $candidate['source_reference'] = $reference;
        }
        if ($invoice !== null && ! isset($candidate['evidence']['invoice_reference_conflict'])) {
            if ($candidate['invoice_reference'] !== null && $candidate['invoice_reference'] !== $invoice) {
                $candidate['invoice_reference'] = null;
                $candidate['evidence']['invoice_reference_conflict'] = true;
                $this->review($reviews, [$dossierId], $evidence, 'invoice_reference_conflict');
                $conflict = true;
            } else {
                $candidate['invoice_reference'] = $invoice;
            }
        }

        return $conflict;
    }

    private function review(array &$reviews, array $dossierIds, array $evidence, string $reason): void
    {
        foreach (array_unique($dossierIds) as $id) {
            $reviews[$id][$evidence['source_table'].':'.$evidence['source_id']] = [...$evidence, 'reason' => $reason];
        }
    }

    private function persistReviews(array $reviews): void
    {
        ksort($reviews);
        foreach (array_chunk($reviews, self::CHUNK_SIZE, true) as $batch) {
            DB::transaction(function () use ($batch): void {
                $dossiers = DB::table('dossiers')->whereIn('id', array_keys($batch))->orderBy('id')->lockForUpdate()
                    ->get(['id', 'billing_review_required', 'billing_review_evidence']);
                foreach ($dossiers as $dossier) {
                    $evidence = json_decode($dossier->billing_review_evidence ?? '{}', true) ?: [];
                    $merged = $evidence;
                    $merged['legacy_billing'] = array_replace($merged['legacy_billing'] ?? [], $batch[$dossier->id]);
                    // MySQL canonicalises JSON object key order; compare evidence semantically.
                    if (! $dossier->billing_review_required || $merged != $evidence) {
                        DB::table('dossiers')->where('id', $dossier->id)->update(['billing_review_required' => true,
                            'billing_review_evidence' => $this->json($merged), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                    }
                }
            }, 3);
        }
    }

    private function persistMarks(array $rows): int
    {
        return DB::transaction(function () use ($rows): int {
            $parentIds = array_unique(array_column($rows, 'dossier_id'));
            sort($parentIds);
            DB::table('dossiers')->whereIn('id', $parentIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $operations = DB::table('dossier_operations')->whereIn('id', array_keys($rows))->orderBy('id')->lockForUpdate()->pluck('dossier_id', 'id');
            foreach ($rows as $id => $row) {
                if ((int) $operations->get($id) !== $row['dossier_id']) {
                    throw new RuntimeException('Rattachement modifie pendant la reconciliation. Relancer le controle.');
                }
            }
            while ($rows !== []) {
                $existing = DB::table('dossier_billings')->whereIn('dossier_operation_id', array_keys($rows))->lockForUpdate()->pluck('dossier_operation_id')->all();
                $rows = array_diff_key($rows, array_flip($existing));
                if ($rows === []) {
                    return 0;
                }
                try {
                    // The savepoint permits a race recovery without hiding FK or malformed-data failures.
                    DB::transaction(fn () => DB::table('dossier_billings')->insert(array_values($rows)));

                    return count($rows);
                } catch (UniqueConstraintViolationException $exception) {
                    $collisions = DB::table('dossier_billings')->whereIn('dossier_operation_id', array_keys($rows))->lockForUpdate()->pluck('dossier_operation_id')->all();
                    if ($collisions === []) {
                        throw $exception;
                    }
                    $rows = array_diff_key($rows, array_flip($collisions));
                }
            }

            return 0;
        }, 3);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
