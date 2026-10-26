<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Targeted merge; no models, events, invitations or writes to business records. */
final class TechCalendarInternalUsersMerge
{
    public const DECISIONS = [
        'version' => 1,
        'statuses' => 'copy_source_including_deactivations',
        'new_accounts' => 'include_inactive_test_accounts',
        'existing_passwords' => 'preserve_exact_hashes',
        'new_passwords' => 'unique_unknown_random_requires_reset_without_delivery',
        'unmapped_habilitations' => 'audit_only_no_unproven_grants',
        'inactive_unknown_primary_department' => 'clear_primary_keep_explicit_intervention_departments_and_audit',
    ];

    public function resolve(TechCalendarInternalUsersPlan $snapshot, array $approved, array $target, array $decisions): array
    {
        if ($decisions !== self::DECISIONS || ($approved['format'] ?? null) !== 'cofrac2.internal-users-plan'
            || ($approved['version'] ?? null) !== 1 || ($approved['source_sha256'] ?? null) !== $snapshot->sourceSha256
            || ! preg_match('/^[a-f0-9]{64}$/D', $approved['target_fingerprint'] ?? '')) {
            throw new RuntimeException('Source, plan ou decisions non approuves.');
        }
        $fresh = $snapshot->build($target);
        if (! hash_equals($approved['target_fingerprint'], $fresh['target_fingerprint'])) {
            throw new RuntimeException('Les comptes ou reglages Cofrac2 ont change depuis le controle. Refaire le rapprochement avant toute fusion.');
        }
        foreach (['summary', 'users', 'services', 'issues'] as $part) {
            if (($approved[$part] ?? null) !== $fresh[$part]) {
                throw new RuntimeException('Plan different du rapprochement recalcule.');
            }
        }
        if ($fresh['summary']['review'] !== 0 || $fresh['services']['ambiguous'] !== [] || $fresh['services']['unmatched_target'] !== []) {
            throw new RuntimeException('Identite ou catalogue cible encore ambigu : fusion refusee.');
        }
        $sourceUsers = array_column($snapshot->source['tables']['users']['rows'], null, 'id');
        $members = $warnings = [];
        foreach ($fresh['users'] as $entry) {
            if ($entry['action'] === 'protected_super_admin') {
                continue;
            }
            $source = $sourceUsers[$entry['source_user_id']];
            $profile = $entry['profile'];
            $fieldIssues = $entry['profile_issues'];
            if ($fieldIssues === ['department_code'] && $profile['deleted_at'] !== null) {
                $warnings[] = ['source_user_id' => $entry['source_user_id'], 'kind' => 'inactive_unknown_primary_department',
                    'original_value' => $profile['department_code'], 'intervention_departments' => $entry['departments']];
                $profile['department_code'] = null;
            } elseif ($fieldIssues !== []) {
                throw new RuntimeException('Donnees de profil non resolues : fusion refusee.');
            }
            if ($entry['unmapped_source_service_ids'] !== []) {
                $warnings[] = ['source_user_id' => $entry['source_user_id'], 'kind' => 'legacy_habilitations_not_granted',
                    'source_service_ids' => $entry['unmapped_source_service_ids'], 'inactive' => $profile['deleted_at'] !== null];
            }
            $members[] = ['source_user_id' => $entry['source_user_id'], 'target_user_id' => $entry['target_user_id'],
                'email' => $entry['email'], 'profile' => $profile, 'departments' => $entry['departments'],
                'services' => $entry['mapped_service_ids'], 'source_created_at' => $source['created_at']];
        }

        return ['members' => $members, 'warnings' => $warnings, 'summary' => $fresh['summary'],
            'target_fingerprint' => $fresh['target_fingerprint']];
    }

    public function apply(PDO $pdo, TechCalendarInternalUsersPlan $snapshot, array $approved, array $decisions, callable $beforeCommit): array
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Transaction cible deja ouverte.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $pdo->beginTransaction();
        try {
            $before = $snapshot->readTarget($pdo, true);
            $resolved = $this->resolve($snapshot, $approved, $before, $decisions);
            $byId = array_column($before['users'], null, 'id');
            $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
            $report = ['format' => 'cofrac2.internal-users-merge', 'version' => 1, 'source_sha256' => $snapshot->sourceSha256,
                'target_before_fingerprint' => $resolved['target_fingerprint'], 'created' => 0, 'updated' => 0,
                'deactivated' => 0, 'restored' => 0, 'protected_super_admin' => 1, 'mapping' => [], 'warnings' => $resolved['warnings'],
                'revoked' => ['sessions' => 0, 'mobile_access_tokens' => 0, 'mobile_push_tokens' => 0],
                'departments_added' => 0, 'departments_removed' => 0, 'habilitations_added' => 0, 'habilitations_removed' => 0];
            $touched = $expectedProfiles = [];
            foreach ($resolved['members'] as $member) {
                $id = $member['target_user_id'];
                $existing = $id === null ? null : $byId[$id];
                $values = $member['profile'];
                // Keep the historical deactivation date when both sources already consider an account inactive.
                if ($existing !== null && $values['deleted_at'] !== null && $existing['deleted_at'] !== null) {
                    $values['deleted_at'] = $existing['deleted_at'];
                }
                $revoke = $existing !== null && ($values['deleted_at'] !== null || (int) $existing['role'] !== $values['role']);
                if ($existing === null) {
                    $values += ['email' => $member['email'], 'admin' => 0, 'can_manage_billing' => 0,
                        'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
                        'must_change_password' => 1, 'notification_mail_enabled' => 0, 'notification_push_enabled' => 0,
                        'portal_enabled' => 0, 'external_client_id' => null, 'remember_token' => null,
                        'created_at' => $member['source_created_at'] ?? $now, 'updated_at' => $now];
                    $this->writeUser($pdo, $values);
                    $id = (int) $pdo->lastInsertId();
                    $report['created']++;
                } else {
                    $changes = [];
                    foreach ($values as $key => $value) {
                        if (! $this->equal($key, $value, $existing[$key])) {
                            $changes[$key] = $value;
                        }
                    }
                    if ($revoke && $existing['remember_token'] !== null) {
                        $changes['remember_token'] = null;
                    }
                    if ($changes !== []) {
                        $changes['updated_at'] = $now;
                        $this->writeUser($pdo, $changes, $id);
                        $report['updated']++;
                    }
                    if ($existing['deleted_at'] === null && $values['deleted_at'] !== null) {
                        $report['deactivated']++;
                    } elseif ($existing['deleted_at'] !== null && $values['deleted_at'] === null) {
                        $report['restored']++;
                    }
                }
                $touched[$id] = true;
                $expectedProfiles[$id] = $values;
                $report['mapping'][] = ['source_user_id' => $member['source_user_id'], 'target_user_id' => $id];
                [$added, $removed] = $this->sync($pdo, 'department_user', 'department_code', $id, $member['departments'], $now);
                $report['departments_added'] += $added;
                $report['departments_removed'] += $removed;
                [$added, $removed] = $this->sync($pdo, 'service_user', 'service_id', $id, $member['services'], $now);
                $report['habilitations_added'] += $added;
                $report['habilitations_removed'] += $removed;
                if ($revoke) {
                    foreach (array_keys($report['revoked']) as $table) {
                        $query = $pdo->prepare("DELETE FROM `$table` WHERE user_id = ?");
                        $query->execute([$id]);
                        $report['revoked'][$table] += $query->rowCount();
                    }
                }
            }
            $after = $snapshot->readTarget($pdo);
            $afterById = array_column($after['users'], null, 'id');
            foreach ($expectedProfiles as $id => $profile) {
                foreach ($profile as $field => $value) {
                    if (! $this->equal($field, $value, $afterById[$id][$field])) {
                        throw new RuntimeException('Une valeur metier n a pas ete reprise exactement : fusion annulee.');
                    }
                }
            }
            foreach ($byId as $id => $user) {
                $current = $afterById[$id] ?? throw new RuntimeException('Un compte existant a disparu : fusion annulee.');
                if (! hash_equals($user['password'], $current['password']) || (bool) $user['admin'] !== (bool) $current['admin']
                    || (! isset($touched[$id]) && $current !== $user)) {
                    throw new RuntimeException('Mot de passe, super-admin ou compte hors perimetre modifie : fusion annulee.');
                }
            }
            if (count($afterById) !== count($byId) + $report['created']) {
                throw new RuntimeException('Effectif des comptes inattendu : fusion annulee.');
            }
            foreach (['departments', 'services', 'external_service_aliases'] as $table) {
                if ($after[$table] !== $before[$table]) {
                    throw new RuntimeException('Catalogue modifie : fusion annulee.');
                }
            }
            $report['existing_password_hashes_preserved'] = count($byId);
            $report['target_only_users_untouched'] = $resolved['summary']['target_only_untouched'];
            $report['target_after_fingerprint'] = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR));
            $report['completed_at_utc'] = gmdate(DATE_ATOM);
            $report['outbound_deliveries'] = 0;
            $report['business_records_modified'] = false;
            $beforeCommit($report);
            $pdo->commit();

            return $report;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function writeUser(PDO $pdo, array $values, ?int $id = null): void
    {
        if ($id === null) {
            $columns = '`'.implode('`,`', array_keys($values)).'`';
            $query = $pdo->prepare('INSERT INTO users ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
            $query->execute(array_values($values));
        } else {
            // This immutable profile whitelist never includes password, global admin or external permissions.
            $allowed = ['first_name', 'last_name', 'role', 'phone', 'address', 'department_code', 'latitude', 'longitude',
                'day_start_time', 'day_end_time', 'break_duration_minutes', 'deleted_at', 'updated_at', 'remember_token'];
            if (array_diff(array_keys($values), $allowed)) {
                throw new RuntimeException('Champ utilisateur interdit pendant la fusion.');
            }
            $query = $pdo->prepare('UPDATE users SET '.implode(',', array_map(fn ($key) => '`'.$key.'` = ?', array_keys($values))).' WHERE id = ? AND admin = 0 AND external_client_id IS NULL AND role <> 3');
            $query->execute([...array_values($values), $id]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('Compte cible protege ou concurrent : fusion annulee.');
            }
        }
    }

    private function sync(PDO $pdo, string $table, string $column, int $id, array $wanted, string $now): array
    {
        if (! in_array([$table, $column], [['department_user', 'department_code'], ['service_user', 'service_id']], true)) {
            throw new RuntimeException('Relation cible non autorisee.');
        }
        $query = $pdo->prepare("SELECT `$column` FROM `$table` WHERE user_id = ?");
        $query->execute([$id]);
        $current = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        $wanted = array_map('strval', $wanted);
        $removed = array_diff($current, $wanted);
        $added = array_diff($wanted, $current);
        $delete = $pdo->prepare("DELETE FROM `$table` WHERE user_id = ? AND `$column` = ?");
        foreach ($removed as $value) {
            $delete->execute([$id, $value]);
        }
        $insert = $pdo->prepare("INSERT INTO `$table` (user_id, `$column`, created_at, updated_at) VALUES (?, ?, ?, ?)");
        foreach ($added as $value) {
            $insert->execute([$id, $value, $now, $now]);
        }

        return [count($added), count($removed)];
    }

    private function equal(string $key, mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (in_array($key, ['latitude', 'longitude'], true)) {
            return is_numeric($left) && is_numeric($right) && abs((float) $left - (float) $right) < 0.00000005;
        }

        return (string) $left === (string) $right;
    }
}
