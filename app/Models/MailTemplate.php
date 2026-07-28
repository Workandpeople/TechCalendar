<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[Fillable([
    'name',
    'slug',
    'subject',
    'markdown_body',
    'is_active',
    'created_by_user_id',
    'updated_by_user_id',
])]
class MailTemplate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    protected function usedVariables(): Attribute
    {
        return Attribute::get(function (): Collection {
            $content = $this->subject."\n".$this->markdown_body;
            preg_match_all('/{{\s*([a-zA-Z0-9_.-]+)\s*}}/', $content, $matches);

            return collect($matches[1] ?? [])
                ->map(fn (string $variable): string => trim($variable))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
