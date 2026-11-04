<?php

namespace App\Models;

use App\Casts\CofracLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierActivity extends Model
{
    public const COMMENT_KINDS = ['comment', 'legacy_comment', 'post_visit'];

    protected $fillable = ['author_id', 'kind', 'body', 'changed_fields', 'client_visible', 'delegataire_visible', 'visibility_reviewed_at', 'hidden_at'];

    protected function casts(): array
    {
        return ['body' => CofracLabel::class, 'changed_fields' => 'array', 'client_visible' => 'boolean', 'delegataire_visible' => 'boolean', 'visibility_reviewed_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function scopeWithAuthorLabel(Builder $query): void
    {
        $query->with(['author' => fn ($authors) => $authors
            ->select(['id', 'first_name', 'last_name', 'role', 'external_client_id'])
            ->with('externalClient:id,name')]);
    }

    public function authorLabel(): string
    {
        if ($author = $this->author) {
            $organisation = $author->isExternal() ? trim((string) $author->externalClient?->name) : '';

            return $organisation ?: ($author->full_name ?: 'Compte #'.$author->id);
        }
        if (str_starts_with($this->kind, 'legacy_')) {
            return 'Auteur Cofrac #'.($this->changed_fields['source_author_id'] ?? '?');
        }

        return $this->author_id !== null || in_array($this->kind, self::COMMENT_KINDS, true)
            ? 'Auteur non renseigné' : 'Système';
    }

    public function scopeStatusChanges(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereIn('kind', ['dossier_deleted', 'dossier_restored'])->orWhere(function (Builder $query): void {
                $query->whereIn('kind', ['status_changed', 'appointment_linked'])
                    ->whereNotNull('changed_fields->status')
                    ->where(fn (Builder $query) => $query->whereNull('changed_fields->previous_status')
                        ->orWhereColumn('changed_fields->previous_status', '!=', 'changed_fields->status'));
            })->orWhere(function (Builder $query): void {
                $query->where('kind', 'post_visit')->whereNotNull('changed_fields->after->legacy_status')
                    ->where(fn (Builder $query) => $query->whereNull('changed_fields->before->legacy_status')
                        ->orWhereColumn('changed_fields->before->legacy_status', '!=', 'changed_fields->after->legacy_status'));
            })->orWhere(function (Builder $query): void {
                $query->where('kind', 'legacy_history')->where('changed_fields->source_status_id', '>', 0);
            })->orWhere(function (Builder $query): void {
                // Older booking events did not store the status; plain links and replacements are not proof of a transition.
                $query->where('kind', 'appointment_linked')->whereNull('changed_fields->status')
                    ->where('body', 'like', 'RDV #% placé le % : RDV en attente de visite.');
            });
        });
    }
}
