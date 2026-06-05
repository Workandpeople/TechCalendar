<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'application_setting_id',
    'key',
    'changed_by',
    'had_value_before',
    'has_value_after',
    'changed_at',
])]
class ApplicationSettingAudit extends Model
{
    protected function casts(): array
    {
        return [
            'had_value_before' => 'boolean',
            'has_value_after' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }
}
