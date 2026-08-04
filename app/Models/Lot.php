<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'service_id',
    'status',
    'sampling_percentage',
    'physical_sampling_percentage',
    'contact_sampling_percentage',
    'physical_appointment_target_count',
    'contact_appointment_target_count',
    'source',
    'delegataire',
    'received_at',
    'comment',
    'original_filename',
    'original_file_disk',
    'original_file_path',
    'original_file_size',
    'original_file_mime',
    'import_status',
    'total_rows',
    'imported_rows',
    'rejected_rows',
    'ai_model',
    'import_summary',
    'created_by',
    'imported_at',
])]
class Lot extends Model
{
    public const TYPE_FULL_CONTACT_CONTROL = '100% controle contact';
    public const TYPE_SAMPLE_CONTACT_CONTROL = 'echantillonage controle contact';
    public const TYPE_FULL_CONTROL = '100% controle';
    public const TYPE_SAMPLE_CONTROL = 'echantillonage controle';
    public const TYPE_HYBRID_LOCATION_CONTACT = 'hybride sur lieu/contact';

    public const STATUS_NOT_STARTED = 'a_commencer';
    public const STATUS_IN_PROGRESS = 'en_cours';
    public const STATUS_COMPLETED = 'complet';

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_FULL_CONTACT_CONTROL => '100% contrôle contact',
            self::TYPE_SAMPLE_CONTACT_CONTROL => 'Échantillonnage contrôle contact',
            self::TYPE_FULL_CONTROL => '100% contrôle',
            self::TYPE_SAMPLE_CONTROL => 'Échantillonnage contrôle',
            self::TYPE_HYBRID_LOCATION_CONTACT => 'Hybride sur lieu/contact',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_NOT_STARTED => 'A commencer',
            self::STATUS_IN_PROGRESS => 'En cours',
            self::STATUS_COMPLETED => 'Complet',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function samplingTypes(): array
    {
        return [
            self::TYPE_SAMPLE_CONTACT_CONTROL,
            self::TYPE_SAMPLE_CONTROL,
        ];
    }

    public static function requiresSamplingPercentageFor(?string $type): bool
    {
        return in_array($type, self::samplingTypes(), true);
    }

    public static function requiresPhysicalSamplingPercentageFor(?string $type): bool
    {
        return in_array($type, [self::TYPE_SAMPLE_CONTROL, self::TYPE_HYBRID_LOCATION_CONTACT], true);
    }

    public static function requiresContactSamplingPercentageFor(?string $type): bool
    {
        return in_array($type, [self::TYPE_SAMPLE_CONTACT_CONTROL, self::TYPE_HYBRID_LOCATION_CONTACT], true);
    }

    public function isContactOnly(): bool
    {
        return in_array($this->type, [self::TYPE_FULL_CONTACT_CONTROL, self::TYPE_SAMPLE_CONTACT_CONTROL], true);
    }

    public function isPhysicalOnly(): bool
    {
        return in_array($this->type, [self::TYPE_FULL_CONTROL, self::TYPE_SAMPLE_CONTROL], true);
    }

    public function isHybrid(): bool
    {
        return $this->type === self::TYPE_HYBRID_LOCATION_CONTACT;
    }

    public function supportsContactProcessing(): bool
    {
        return $this->isContactOnly() || $this->isHybrid();
    }

    public function supportsPhysicalProcessing(): bool
    {
        return $this->isPhysicalOnly() || $this->isHybrid();
    }

    public function physicalSamplingPercentage(): ?float
    {
        if ($this->isHybrid()) {
            return $this->physical_sampling_percentage !== null
                ? (float) $this->physical_sampling_percentage
                : null;
        }

        return $this->type === self::TYPE_SAMPLE_CONTROL && $this->sampling_percentage !== null
            ? (float) $this->sampling_percentage
            : null;
    }

    public function contactSamplingPercentage(): ?float
    {
        if ($this->isHybrid()) {
            return $this->contact_sampling_percentage !== null
                ? (float) $this->contact_sampling_percentage
                : null;
        }

        return $this->type === self::TYPE_SAMPLE_CONTACT_CONTROL && $this->sampling_percentage !== null
            ? (float) $this->sampling_percentage
            : null;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(LotAppointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function typeLabel(): ?string
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? self::statuses()[self::STATUS_NOT_STARTED];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'rejected_rows' => 'integer',
            'sampling_percentage' => 'float',
            'physical_sampling_percentage' => 'float',
            'contact_sampling_percentage' => 'float',
            'physical_appointment_target_count' => 'integer',
            'contact_appointment_target_count' => 'integer',
            'original_file_size' => 'integer',
            'import_summary' => 'array',
            'received_at' => 'date',
            'imported_at' => 'datetime',
        ];
    }
}
