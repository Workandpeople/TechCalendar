<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lot_id',
    'service_id',
    'appointment_id',
    'external_reference',
    'row_number',
    'source',
    'customer_name',
    'customer_first_name',
    'customer_last_name',
    'customer_phone',
    'address',
    'postal_code',
    'city',
    'department_code',
    'latitude',
    'longitude',
    'service_type',
    'service_name',
    'duration_minutes',
    'status',
    'ai_confidence',
    'ai_warnings',
    'raw_payload',
    'comment',
])]
class LotAppointment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_PLACED = 'placed';

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'A placer',
            self::STATUS_NEEDS_REVIEW => 'A verifier',
            self::STATUS_PLACED => 'Place',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? self::statuses()[self::STATUS_PENDING];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'duration_minutes' => 'integer',
            'ai_confidence' => 'float',
            'ai_warnings' => 'array',
            'raw_payload' => 'array',
        ];
    }
}
