<?php

namespace App\Services\Crm;

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\UserNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientManagement
{
    public function save(User $actor, array $data, ?ExternalDelegataire $client = null): ExternalDelegataire
    {
        app(ClientAccess::class)->manage($actor);
        $client ??= new ExternalDelegataire(['source' => 'native', 'external_id' => (string) Str::uuid()]);
        if (isset($data['category_mode'])) {
            $data['portal_categories'] = $data['category_mode'] === 'custom' ? array_values($data['categories'] ?? []) : null;
        }
        unset($data['category_mode'], $data['categories']);
        DB::transaction(function () use ($client, $data): void {
            $client->fill($data)->save();
            if ($client->wasChanged('name')) {
                foreach (['lots', 'lot_import_previews'] as $table) {
                    DB::table($table)->where('delegataire_id', $client->id)->update(['delegataire' => $client->name]);
                }
            }
        });

        return $client;
    }

    public function saveAccount(User $actor, ExternalDelegataire $client, array $data, ?User $account = null): User
    {
        app(ClientAccess::class)->manage($actor);
        abort_if($account && (! $account->isExternal() || $account->external_client_id !== $client->id), 404);
        $data = UserNameNormalizer::normalizePayload($data);

        return DB::transaction(function () use ($client, $data, $account): User {
            $account ??= new User;
            $permissions = [];
            foreach (ClientAccess::PERMISSIONS as $key => $label) {
                $permissions[$key] = (bool) ($data['permissions'][$key] ?? false);
            }
            // Writing requires the corresponding read permission; no invisible submissions.
            $permissions['add_comments'] = $permissions['add_comments'] && $permissions['comments'];
            $permissions['upload_documents'] = $permissions['upload_documents'] && $permissions['documents'];
            $permissions['upload_corrections'] = $permissions['upload_corrections'] && $permissions['documents'];
            $account->forceFill([
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'],
                'external_client_id' => $client->id, 'role' => User::ROLE_EXTERNAL, 'admin' => false,
                'portal_permissions' => $permissions, 'portal_enabled' => (bool) ($data['portal_enabled'] ?? false),
                'notification_mail_enabled' => false, 'notification_push_enabled' => false,
            ]);
            if (filled($data['password'] ?? null)) {
                $account->forceFill(['password' => $data['password'], 'must_change_password' => true,
                    'remember_token' => Str::random(60)]);
                if ($account->exists) {
                    DB::table('sessions')->where('user_id', $account->id)->delete();
                }
            }
            $account->save();

            return $account;
        });
    }
}
