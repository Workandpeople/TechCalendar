<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'status',
    'progress',
    'stage',
    'name',
    'type',
    'sampling_percentage',
    'original_filename',
    'original_file_disk',
    'original_file_path',
    'original_file_size',
    'original_file_mime',
    'total_rows',
    'normalized_rows',
    'rejected_rows',
    'ai_model',
    'payload',
    'error_message',
    'created_by',
    'confirmed_lot_id',
    'completed_at',
    'confirmed_at',
])]
class LotImportPreview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONFIRMED = 'confirmed';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedLot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'confirmed_lot_id');
    }

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'sampling_percentage' => 'float',
            'original_file_size' => 'integer',
            'total_rows' => 'integer',
            'normalized_rows' => 'integer',
            'rejected_rows' => 'integer',
            'payload' => 'array',
            'completed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
