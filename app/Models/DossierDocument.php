<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DossierDocument extends Model
{
    use SoftDeletes;

    protected $fillable = ['author_id', 'name', 'path', 'mime_type', 'size', 'client_visible', 'delegataire_visible',
        'description', 'document_type', 'dossier_operation_id', 'control_reference', 'inspected_on', 'issued_on', 'control_outcome'];

    protected function casts(): array
    {
        return ['client_visible' => 'boolean', 'delegataire_visible' => 'boolean', 'inspected_on' => 'date', 'issued_on' => 'date'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(DossierOperation::class, 'dossier_operation_id');
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
