<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierActivity extends Model
{
    protected $fillable = ['author_id', 'kind', 'body', 'changed_fields', 'client_visible', 'visibility_reviewed_at'];

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'client_visible' => 'boolean', 'visibility_reviewed_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
