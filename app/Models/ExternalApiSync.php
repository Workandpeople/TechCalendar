<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'state',
    'message',
    'last_started_at',
    'last_finished_at',
    'last_successful_at',
    'metadata',
])]
class ExternalApiSync extends Model
{
    public const STATE_NEVER_SYNCED = 'never_synced';

    public const STATE_AVAILABLE = 'available';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_NOT_CONFIGURED = 'not_configured';

    public const STATE_SYNCING = 'syncing';

    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
            'last_successful_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
