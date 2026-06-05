<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'system_health_snapshot_id',
    'name',
    'label',
    'status',
    'value',
    'message',
    'meta',
    'duration_ms',
    'checked_at',
])]
class SystemHealthCheck extends Model
{
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SystemHealthSnapshot::class, 'system_health_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }
}
