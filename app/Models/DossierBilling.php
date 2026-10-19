<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierBilling extends Model
{
    protected $fillable = [
        'dossier_id', 'dossier_operation_id', 'marked_at', 'marked_by',
        'invoice_reference', 'source', 'source_reference', 'evidence',
    ];

    protected function casts(): array
    {
        return ['marked_at' => 'datetime', 'evidence' => 'array'];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(DossierOperation::class, 'dossier_operation_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by')->withTrashed();
    }
}
