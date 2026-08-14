<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lot_appointment_id',
    'appointment_id',
    'uploaded_by',
    'name',
    'original_name',
    'disk',
    'path',
    'mime',
    'size',
    'is_private',
    'status',
    'pushed_at',
    'remote_document',
    'error_message',
])]
class LotAppointmentDocument extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'En attente',
            self::STATUS_QUEUED => 'Envoi en cours',
            self::STATUS_UPLOADED => 'Envoyé à Coffrac',
            self::STATUS_FAILED => 'Erreur',
        ];
    }

    public function lotAppointment(): BelongsTo
    {
        return $this->belongsTo(LotAppointment::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? self::statuses()[self::STATUS_PENDING];
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_private' => 'boolean',
            'pushed_at' => 'datetime',
            'remote_document' => 'array',
        ];
    }
}
