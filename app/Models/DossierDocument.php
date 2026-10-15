<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierDocument extends Model
{
    protected $fillable = ['author_id', 'name', 'path', 'mime_type', 'size', 'client_visible'];

    protected function casts(): array
    {
        return ['client_visible' => 'boolean'];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
