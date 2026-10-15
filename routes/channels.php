<?php

use App\Models\LotImportPreview;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return ! $user->isExternal() && (int) $user->id === (int) $id;
});

Broadcast::channel('lot-import-preview.{uuid}', function ($user, string $uuid): bool {
    if ($user->isExternal()) {
        return false;
    }
    $preview = LotImportPreview::query()
        ->where('uuid', $uuid)
        ->first(['id', 'uuid', 'created_by']);

    if (! $preview) {
        return false;
    }

    return (int) $preview->created_by === (int) $user->id
        || (bool) $user->admin
        || (int) $user->role === 0;
});

Broadcast::channel('external-api-sync.{source}', function ($user, string $source): bool {
    return ! $user->isExternal() && in_array($source, ['coffrac'], true)
        && ((bool) $user->admin || in_array((int) $user->role, [0, 1], true));
});
