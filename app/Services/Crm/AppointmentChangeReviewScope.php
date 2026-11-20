<?php

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder;

final class AppointmentChangeReviewScope
{
    public const REASONS = ['technician', 'schedule', 'problem', 'cancelled'];

    public static function transition(?string $before, ?string $after): ?string
    {
        $target = DossierStatusCatalog::describe($after)['key'];

        return DossierStatusCatalog::describe($before)['key'] === 'awaiting_visit' && in_array($target, ['problem', 'cancelled'], true)
            ? $target : null;
    }

    public static function reasons(array $review): array
    {
        if (array_key_exists('reasons', $review)) {
            return is_array($review['reasons']) ? array_values(array_filter(self::REASONS,
                fn ($reason) => in_array($reason, $review['reasons'], true))) : [];
        }
        $before = $review['before'] ?? [];
        $after = $review['after'] ?? [];
        $reasons = [];
        if (isset($before['technician_id'], $after['technician_id']) && (int) $before['technician_id'] !== (int) $after['technician_id']) {
            $reasons[] = 'technician';
        }
        if (isset($before['starts_at'], $after['starts_at']) && $before['starts_at'] !== $after['starts_at']) {
            $reasons[] = 'schedule';
        }
        if ($transition = self::transition($before['operation_status'] ?? null, $after['operation_status'] ?? null)) {
            $reasons[] = $transition;
        }

        return $reasons;
    }

    public static function apply(Builder $query): void
    {
        $query->where(function (Builder $relevant): void {
            foreach (self::REASONS as $reason) {
                $relevant->orWhereJsonContains('global_plus_review->reasons', $reason);
            }
            $relevant->orWhere(function (Builder $legacy): void {
                $legacy->whereNull('global_plus_review->reasons')->where(function (Builder $changed): void {
                    foreach (['technician_id', 'starts_at'] as $field) {
                        $changed->orWhere(function (Builder $pair) use ($field): void {
                            $pair->whereNotNull('global_plus_review->before->'.$field)
                                ->whereNotNull('global_plus_review->after->'.$field)
                                ->whereColumn('global_plus_review->before->'.$field, '!=', 'global_plus_review->after->'.$field);
                        });
                    }
                    $changed->orWhere(fn (Builder $status) => $status
                        ->whereIn('global_plus_review->before->operation_status', DossierStatusCatalog::STATUSES['awaiting_visit']['values'])
                        ->whereIn('global_plus_review->after->operation_status', [...DossierStatusCatalog::STATUSES['problem']['values'],
                            ...DossierStatusCatalog::STATUSES['cancelled']['values']]));
                });
            });
        });
    }
}
