<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'severity',
    'fingerprint',
    'message',
    'context',
    'occurred_at',
    'first_seen_at',
    'last_seen_at',
    'occurrences',
])]
class SystemErrorEvent extends Model
{
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }
}
