<?php

namespace App\Services\Crm;

use App\Models\ExternalDelegataire;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PortalCategoryAccess
{
    public static function catalogue(): array
    {
        return array_diff_key(DossierStatusCatalog::STATUSES, ['invoiced' => true]);
    }

    public function defaults(string $kind): array
    {
        if (! array_key_exists($kind, ExternalDelegataire::KINDS)) {
            return [];
        }
        $record = DB::table('portal_category_defaults')->where('kind', $kind)->first();

        return $record ? $this->normalize(json_decode($record->categories, true)) : array_keys(self::catalogue());
    }

    public function effective(ExternalDelegataire $organisation): array
    {
        return $organisation->portal_categories === null
            ? $this->defaults($organisation->kind) : $this->normalize($organisation->portal_categories);
    }

    public function saveDefaults(User $actor, string $kind, array $categories): void
    {
        app(ClientAccess::class)->manage($actor);
        abort_unless(array_key_exists($kind, ExternalDelegataire::KINDS), 422);
        DB::table('portal_category_defaults')->upsert([
            'kind' => $kind, 'categories' => json_encode($this->normalize($categories), JSON_THROW_ON_ERROR),
            'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ], ['kind'], ['categories', 'updated_by', 'updated_at']);
    }

    public function restrict(Builder $dossiers, array $allowed): Builder
    {
        if ($allowed === []) {
            return $dossiers->whereRaw('1 = 0');
        }
        $denied = array_diff(array_keys(self::catalogue()), $allowed);
        if ($denied === []) {
            return $dossiers;
        }
        // Shared dossier-level content must not leak through a second, allowed operation.
        $dossiers->whereDoesntHave('operations', function ($operations) use ($denied): void {
            $operations->where(function ($query) use ($denied): void {
                foreach ($denied as $status) {
                    $query->orWhere(fn ($query) => DossierStatusCatalog::filter($query, $status));
                }
            });
        });
        if (in_array('unknown', $denied, true)) {
            $dossiers->whereHas('operations');
        }

        return $dossiers;
    }

    private function normalize(mixed $categories): array
    {
        // Invalid stored settings fail closed, never restore unrestricted access.
        return is_array($categories) ? array_values(array_filter(array_keys(self::catalogue()),
            fn ($key) => in_array($key, $categories, true))) : [];
    }
}
