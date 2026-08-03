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
    'company_name',
    'site_name',
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
    'processing_mode',
    'contact_satisfaction',
    'contact_comment',
    'contact_processed_at',
    'contact_processed_by',
    'physical_satisfaction',
    'physical_satisfaction_synced_at',
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

    public const STATUS_CONTACT_PROCESSED = 'contact_processed';

    public const PROCESSING_MODE_PHYSICAL = 'physical';

    public const PROCESSING_MODE_CONTACT = 'contact';

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'A placer',
            self::STATUS_NEEDS_REVIEW => 'A verifier',
            self::STATUS_PLACED => 'Place',
            self::STATUS_CONTACT_PROCESSED => 'Traité par téléphone',
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

    public function contactProcessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_processed_by');
    }

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'duration_minutes' => 'integer',
            'contact_satisfaction' => 'boolean',
            'contact_processed_at' => 'datetime',
            'physical_satisfaction' => 'boolean',
            'physical_satisfaction_synced_at' => 'datetime',
            'ai_confidence' => 'float',
            'ai_warnings' => 'array',
            'raw_payload' => 'array',
        ];
    }
}
