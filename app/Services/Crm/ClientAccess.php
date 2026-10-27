<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierOperation;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ClientAccess
{
    public function __construct(private readonly PortalCategoryAccess $categories) {}

    public const PERMISSIONS = [
        'create_dossiers' => 'Créer des dossiers en prise de RDV',
        'documents' => 'Consulter les documents partagés',
        'comments' => 'Lire les commentaires partagés',
        'add_comments' => 'Ajouter des commentaires',
        'upload_documents' => 'Ajouter des documents',
        'upload_corrections' => 'Ajouter des justificatifs',
        'lots' => 'Consulter les lots attribués',
    ];

    public static function defaults(string $kind): array
    {
        return ['create_dossiers' => $kind === 'client', 'documents' => true, 'comments' => true,
            'add_comments' => $kind === 'client', 'upload_documents' => $kind === 'client',
            'upload_corrections' => $kind === 'client', 'lots' => true];
    }

    public function permissions(User $user): array
    {
        // Preserve explicit revocations; neither fallback grants document read access.
        $client = $user->externalClient?->kind === 'client';
        $permissions = ['create_dossiers' => $client,
            'upload_corrections' => $client, ...($user->portal_permissions ?? [])];
        if (! $client) {
            // Old forms and stored flags cannot enable a client-only action for a delegataire.
            foreach (['create_dossiers', 'upload_documents', 'upload_corrections'] as $permission) {
                $permissions[$permission] = false;
            }
        }

        return $permissions;
    }

    public function active(User $user): bool
    {
        return config('crm.v2_enabled') && $user->isExternal() && $user->role === User::ROLE_EXTERNAL
            && ! $user->admin && ! $user->trashed() && $user->portal_enabled
            && (bool) $user->externalClient?->is_active;
    }

    public function allows(User $user, string $permission): bool
    {
        $permissions = $this->permissions($user);
        $readPermission = ['add_comments' => 'comments', 'upload_documents' => 'documents', 'upload_corrections' => 'documents'][$permission] ?? null;

        return $this->active($user) && ! $user->must_change_password
            && in_array($permission, array_keys(self::PERMISSIONS), true)
            && ($readPermission === null || ($permissions[$readPermission] ?? false) === true)
            && ($permissions[$permission] ?? false) === true;
    }

    public function uploadDocument(User $user, Dossier $dossier): bool
    {
        if (! $this->viewDossier($user, $dossier)) {
            return false;
        }
        if (! $this->allows($user, 'upload_corrections') && ! $this->allows($user, 'upload_documents')) {
            return false;
        }

        return $user->externalClient->kind === 'client';
    }

    public function createDossier(User $user): bool
    {
        return $this->allows($user, 'create_dossiers') && $user->externalClient->kind === 'client'
            && in_array('pending', $this->categoryKeys($user), true);
    }

    public function addComment(User $user, Dossier $dossier): bool
    {
        return $this->viewDossier($user, $dossier) && $this->allows($user, 'add_comments')
            && $this->isNonCompliant($dossier);
    }

    private function isNonCompliant(Dossier $dossier): bool
    {
        $statuses = DossierStatusCatalog::STATUSES['non_compliant']['values'];

        return $dossier->relationLoaded('operations')
            ? $dossier->operations->contains(fn ($operation) => in_array($operation->legacy_status, $statuses, true))
            : $dossier->operations()->whereIn('legacy_status', $statuses)->exists();
    }

    public function dossiers(User $user): Builder
    {
        abort_unless($this->active($user) && ! $user->must_change_password, 403);

        return $this->categories->restrict(Dossier::query(), $this->categoryKeys($user))->where(function ($query) use ($user): void {
            if ($user->externalClient->kind === 'client') {
                $query->where('client_id', $user->external_client_id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('client_id')->where('delegataire_id', $user->external_client_id));
            } else {
                $query->where('delegataire_id', $user->external_client_id);
            }
        });
    }

    public function viewDossier(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && $this->active($user) && ! $user->must_change_password
            && (int) ($user->externalClient->kind === 'client'
                ? ($dossier->client_id ?? $dossier->delegataire_id) : $dossier->delegataire_id) === $user->external_client_id
            && $this->categories->restrict(Dossier::query()->whereKey($dossier->id), $this->categoryKeys($user))->exists();
    }

    public function categoryKeys(User $user): array
    {
        return $this->categories->effective($user->externalClient);
    }

    public function lotRows(Builder $rows, User $user): Builder
    {
        $allowed = $this->categoryKeys($user);
        if ($allowed === []) {
            return $rows->whereRaw('1 = 0');
        }
        $visible = $this->categories->restrict(Dossier::query(), $allowed)->select('dossiers.id');
        $forbiddenOperations = DossierOperation::query()->whereNotIn('dossier_id', $visible)->select('id');
        // Query the appointment table directly to include cancelled/soft-deleted appointments.
        $forbiddenAppointments = DB::table('appointments')
            ->whereIn('dossier_operation_id', $forbiddenOperations)->select('id');
        $rows->where(fn ($query) => $query->whereNull('appointment_id')->orWhereNotIn('appointment_id', $forbiddenAppointments));
        if (! in_array('unknown', $allowed, true)) {
            $rows->whereIn('appointment_id', DB::table('appointments')
                ->whereIn('dossier_operation_id', DossierOperation::query()->whereIn('dossier_id', $visible)->select('id'))->select('id'));
        }

        return $rows;
    }

    public function lots(User $user): Builder
    {
        abort_unless($this->allows($user, 'lots'), 403);

        return Lot::query()->where('delegataire_id', $user->external_client_id);
    }

    public function comments(User $user, Dossier $dossier): Builder
    {
        abort_unless($this->viewDossier($user, $dossier) && $this->allows($user, 'comments'), 403);

        return $dossier->comments()->getQuery()->where(function ($query) use ($user): void {
            $query->where($this->audienceColumn($user), true);
            // Coffrac clients saw public comments; delegataires had no comment section.
            if ($user->externalClient->kind === 'client') {
                $query->orWhere(fn ($query) => $this->whereLegacyFlag($query->where('kind', 'legacy_comment')
                    ->whereNull('visibility_reviewed_at'), 'changed_fields->is_private', false));
            }
        });
    }

    public function commentVisibleToClient(DossierActivity $comment, string $kind = 'client'): bool
    {
        return $comment->hidden_at === null && (($kind === 'delegataire' ? $comment->delegataire_visible : $comment->client_visible) || ($kind === 'client' && $comment->kind === 'legacy_comment'
            && $comment->visibility_reviewed_at === null
            && in_array($comment->changed_fields['is_private'] ?? null, [false, 0, '0'], true)));
    }

    public function audienceColumn(User $user): string
    {
        return $user->externalClient?->kind === 'delegataire' ? 'delegataire_visible' : 'client_visible';
    }

    public function legacyDocumentVisibility(array $payload, ?bool $override = null, ?bool $delegataireOverride = null): array
    {
        $public = $override ?? in_array($payload['is_private'] ?? null, [false, 0, '0'], true);

        return ['client_visible' => $public, 'delegataire_visible' => $public
            && ($delegataireOverride ?? ($override === true || in_array($payload['is_delegataire'] ?? null, [true, 1, '1'], true)))];
    }

    public function filterLegacyDocuments(QueryBuilder $query, User $user): QueryBuilder
    {
        abort_unless($this->allows($user, 'documents'), 403);

        $query->whereNull('hidden_at');
        if ($user->externalClient->kind === 'delegataire') {
            $query->where(function ($query): void {
                $query->where('delegataire_visible_override', true)
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('delegataire_visible_override')->where(function ($fallback): void {
                            $fallback->where('client_visible_override', true)
                                ->orWhere(fn ($original) => $this->whereLegacyFlag($original->whereNull('client_visible_override'), 'payload->is_delegataire', true));
                        });
                    });
            });
        }

        return $query->where(function ($query): void {
            $query->where('client_visible_override', true)
                ->orWhere(function ($query): void {
                    $this->whereLegacyFlag($query->whereNull('client_visible_override'), 'payload->is_private', false);
                });
        });
    }

    public function canReadLegacyDocument(User $user, array $payload, ?bool $override = null, ?bool $delegataireOverride = null): bool
    {
        if (! $this->allows($user, 'documents')) {
            return false;
        }

        // An explicit local decision overrides the archive; missing metadata stays private.
        $visibility = $this->legacyDocumentVisibility($payload, $override, $delegataireOverride);

        return $visibility[$user->externalClient->kind === 'client' ? 'client_visible' : 'delegataire_visible'];
    }

    public function manage(User $actor): void
    {
        abort_unless(config('crm.v2_enabled') && ! $actor->isExternal() && ! $actor->trashed()
            && ! $actor->must_change_password && ($actor->admin || $actor->role === 0), 403);
    }

    private function whereLegacyFlag(Builder|QueryBuilder $query, string $field, bool $value): Builder|QueryBuilder
    {
        // Historical exports contain booleans, integers and strings; match only these exact values.
        return $query->where(fn ($query) => $query->whereJsonContains($field, $value)
            ->orWhereJsonContains($field, (int) $value)->orWhereJsonContains($field, $value ? '1' : '0'));
    }
}
