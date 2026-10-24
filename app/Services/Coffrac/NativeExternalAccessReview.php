<?php

namespace App\Services\Coffrac;

use App\Services\Crm\ClientAccess;
use App\Services\Crm\PortalCategoryAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Produces a private proposal, never permissions or enabled portal accounts. */
final class NativeExternalAccessReview
{
    public const POLICY_VERSION = '2026-10-24-report-access-after-billing';

    public function rehearse(ProductionDataset $dataset): array
    {
        NativeImportTarget::assertAllowed();
        $profiles = $dataset->keyed('user_type');
        $statuses = $dataset->keyed('status');
        $users = $dataset->keyed('users');
        $links = DB::table('coffrac_user_links as links')
            ->join('users as users', 'users.id', '=', 'links.user_id')
            ->join('external_delegataires as organisations', 'organisations.id', '=', 'users.external_client_id')
            ->whereIn('links.source_type', [5, 7])
            ->get(['links.source_user_id', 'links.source_type', 'users.id', 'users.role', 'users.admin',
                'users.portal_enabled', 'users.portal_permissions', 'organisations.source', 'organisations.external_id',
                'organisations.id as organisation_id', 'organisations.kind', 'organisations.portal_categories'])
            ->keyBy('source_user_id');
        $report = ['dataset_sha256' => $dataset->fingerprint, 'policy_version' => self::POLICY_VERSION,
            'confirmed_business_rules' => ['report_access_survives_billing' => true], 'profiles' => [], 'accounts' => [],
            'source_external_accounts' => 0, 'native_disabled_accounts_checked' => 0,
            'source_assignment_counts' => ['client' => 0, 'delegataire' => 0],
            'assignments_with_wrong_or_missing_profile' => ['client' => [], 'delegataire' => []],
            'native_permissions_changed' => 0, 'native_categories_changed' => 0, 'portals_enabled' => 0,
            'proposal_applied' => false, 'rights_approval_required' => true,
            'module_permissions_translated' => false, 'per_account_scope_semantics_verified' => false];

        foreach ([5 => 'client', 7 => 'delegataire'] as $type => $kind) {
            $report['profiles'][$kind] = $this->profile($type, $profiles[$type] ?? [], $statuses);
        }
        foreach ($users as $row) {
            if (! in_array($row['type'], [5, 7], true)) {
                continue;
            }
            $kind = $row['type'] === 5 ? 'client' : 'delegataire';
            $link = $links->get($row['id']);
            $permissions = $link ? json_decode($link->portal_permissions, true, 32, JSON_THROW_ON_ERROR) : null;
            if (! $link || (int) $link->source_type !== $row['type'] || (int) $link->role !== 3 || $link->admin
                || $link->portal_enabled || $link->source !== 'coffrac' || (string) $link->external_id !== (string) $row['id']
                || $link->kind !== $kind || json_decode($link->portal_categories, true, 32, JSON_THROW_ON_ERROR) !== []
                || ! is_array($permissions) || count($permissions) !== count(ClientAccess::PERMISSIONS)
                || array_diff_key(ClientAccess::PERMISSIONS, $permissions) !== []
                || array_filter($permissions, fn ($value) => $value !== false) !== []) {
                throw new RuntimeException('Compte externe, appartenance ou verrou de droits natif different.');
            }
            $report['accounts'][] = ['source_user_id' => $row['id'], 'native_user_id' => $link->id,
                'native_organisation_id' => $link->organisation_id, 'kind' => $kind,
                'source_active' => (int) $row['actif'] === 1 && ! $row['is_archived'],
                // These source fields are evidence, not a reason to widen dossier ownership.
                'source_scope_fields' => array_intersect_key($row, array_flip(['owner', 'show_on_all', 'show_on_attribut', 'parent_id', 'agence_id'])),
                'scope_review_required' => true];
            $report['source_external_accounts']++;
            $report['native_disabled_accounts_checked']++;
        }
        if ($links->count() !== $report['source_external_accounts']) {
            throw new RuntimeException('Couverture des comptes externes native differente.');
        }
        foreach ($dataset->rows('dossiers') as $row) {
            foreach (['client' => 5, 'delegataire' => 7] as $kind => $type) {
                $id = $row[$kind.'_id'] ?? null;
                if ($id === null || $id === 0) {
                    continue;
                }
                if (($users[$id]['type'] ?? null) !== $type) {
                    $report['assignments_with_wrong_or_missing_profile'][$kind][] = $row['id'];
                } else {
                    $report['source_assignment_counts'][$kind]++;
                }
            }
        }

        return $report;
    }

    private function profile(int $type, array $profile, array $statuses): array
    {
        $allowed = $this->ids($profile['autorisation'] ?? null);
        $modules = $this->ids($profile['autorisation_types'] ?? null);
        $result = ['source_type_id' => $type, 'source_status_ids' => $allowed, 'source_module_ids' => $modules,
            'source_profile_valid' => $allowed !== null && $modules !== null,
            'candidate_categories' => [], 'categories_requiring_decision' => [],
            'approved_category_expansions' => [],
            'source_status_ids_without_native_category' => [], 'unknown_authorized_status_ids' => []];
        foreach ($allowed ?? [] as $id) {
            if (! isset($statuses[$id])) {
                $result['unknown_authorized_status_ids'][] = $id;
            }
        }
        $mapped = [];
        foreach (PortalCategoryAccess::catalogue() as $key => $category) {
            $ids = [];
            foreach ($statuses as $row) {
                if (in_array($row['name'] ?? null, $category['values'], true)) {
                    $ids[] = $row['id'];
                    $mapped[$row['id']] = true;
                }
            }
            $denied = array_values(array_diff($ids, $allowed ?? []));
            $approvedReportExpansion = $key === 'report' && $this->preservesReportAccessAfterBilling($allowed ?? [], $denied, $statuses);
            // Only the explicitly approved report/billing merge may widen a source category.
            if ($result['source_profile_valid'] && $result['unknown_authorized_status_ids'] === [] && $ids !== [] && ($denied === [] || $approvedReportExpansion) && $key !== 'unknown') {
                $result['candidate_categories'][] = $key;
                if ($approvedReportExpansion) {
                    $result['approved_category_expansions'][$key] = ['policy_version' => self::POLICY_VERSION,
                        'rule' => 'report_access_survives_billing', 'source_status_ids_not_authorized' => $denied];
                }
            } else {
                $result['categories_requiring_decision'][$key] = ['source_status_ids' => $ids,
                    'source_status_ids_not_authorized' => $denied,
                    'reason' => $key === 'unknown' || $ids === [] ? 'unclassified_or_missing_source_category'
                        : (! $result['source_profile_valid'] || $result['unknown_authorized_status_ids'] !== [] ? 'invalid_source_permissions' : 'source_does_not_authorize_entire_native_category')];
            }
        }
        $result['source_status_ids_without_native_category'] = array_values(array_diff($allowed ?? [], array_keys($mapped)));

        return $result;
    }

    private function preservesReportAccessAfterBilling(array $allowed, array $denied, array $statuses): bool
    {
        if ($denied === []) {
            return false;
        }
        $reportAuthorized = false;
        foreach ($allowed as $id) {
            $reportAuthorized = $reportAuthorized || ($statuses[$id]['name'] ?? null) === 'RAPPORT GENIUS';
        }
        if (! $reportAuthorized) {
            return false;
        }
        foreach ($denied as $id) {
            if (($statuses[$id]['name'] ?? null) !== 'DOSSIERS FACTURER') {
                return false;
            }
        }

        return true;
    }

    private function ids(mixed $value): ?array
    {
        if (! is_string($value) || strlen($value) > 4096) {
            return null;
        }
        if (trim($value) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $value) as $part) {
            if (! preg_match('/^[1-9][0-9]{0,8}$/D', trim($part))) {
                return null;
            }
            $ids[] = (int) trim($part);
        }
        sort($ids, SORT_NUMERIC);

        return array_values(array_unique($ids));
    }
}
