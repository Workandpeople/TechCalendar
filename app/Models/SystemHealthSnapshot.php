<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'overall_status',
    'score',
    'summary',
    'checked_at',
])]
class SystemHealthSnapshot extends Model
{
    public function checks(): HasMany
    {
        return $this->hasMany(SystemHealthCheck::class);
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'summary' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
