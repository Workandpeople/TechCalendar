<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source',
    'external_reference',
    'status',
    'source_label',
    'remote_status_name',
    'service_type',
    'service_name',
    'customer_first_name',
    'customer_last_name',
    'customer_name',
    'phone',
    'address',
    'address_line',
    'postal_code',
    'city',
    'department_code',
    'latitude',
    'longitude',
    'technician_email',
    'starts_at',
    'duration_minutes',
    'comment',
    'documents',
    'payload',
    'appointment_id',
    'remote_updated_at',
    'fetched_at',
])]
class ExternalAppointmentRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PLACED = 'placed';

    public const STATUS_PROBLEM = 'problem';

    public const STATUS_ARCHIVED = 'archived';

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'documents' => 'array',
            'payload' => 'array',
            'remote_updated_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }
}
