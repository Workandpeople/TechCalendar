<?php

use App\Models\LotImportPreview;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('lot-import-preview.{uuid}', function ($user, string $uuid): bool {
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
