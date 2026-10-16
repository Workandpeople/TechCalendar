<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierActivity extends Model
{
    public const COMMENT_KINDS = ['comment', 'legacy_comment', 'post_visit'];

    protected $fillable = ['author_id', 'kind', 'body', 'changed_fields', 'client_visible', 'delegataire_visible', 'visibility_reviewed_at', 'hidden_at'];

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'client_visible' => 'boolean', 'delegataire_visible' => 'boolean', 'visibility_reviewed_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
