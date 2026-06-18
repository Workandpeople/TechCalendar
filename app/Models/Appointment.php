<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'service_id',
    'technician_id',
    'created_by',
    'customer_first_name',
    'customer_last_name',
    'customer_phone',
    'address',
    'latitude',
    'longitude',
    'starts_at',
    'duration_minutes',
    'ends_at',
    'comment',
    'status',
    'problem_reported_at',
    'external_source',
    'external_reference',
    'external_payload',
])]
class Appointment extends Model
{
    use SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PROBLEM = 'problem';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'duration_minutes' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'problem_reported_at' => 'datetime',
            'external_payload' => 'array',
            'deleted_at' => 'datetime',
        ];
    }
}
