<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key',
    'group',
    'label',
    'type',
    'value',
    'is_secret',
    'is_active',
    'description',
    'validation_rules',
    'updated_by',
])]
class ApplicationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
            'is_active' => 'boolean',
            'validation_rules' => 'array',
        ];
    }
}
