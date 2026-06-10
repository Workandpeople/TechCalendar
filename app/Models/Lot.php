<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'status',
    'sampling_percentage',
    'source',
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

    public const STATUS_NOT_STARTED = 'a_commencer';
    public const STATUS_IN_PROGRESS = 'en_cours';
    public const STATUS_COMPLETED = 'complet';

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_FULL_CONTACT_CONTROL => '100% controle contact',
            self::TYPE_SAMPLE_CONTACT_CONTROL => 'Echantillonage controle contact',
            self::TYPE_FULL_CONTROL => '100% controle',
            self::TYPE_SAMPLE_CONTROL => 'Echantillonage controle',
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

    public function appointments(): HasMany
    {
        return $this->hasMany(LotAppointment::class);
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
            'original_file_size' => 'integer',
            'import_summary' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}
