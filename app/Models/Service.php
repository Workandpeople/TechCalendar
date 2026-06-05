<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['type', 'name', 'average_duration_minutes'])]
class Service extends Model
{
    public const TYPE_COFFRAC = 'COFFRAC';

    public const TYPE_MAR = 'MAR';

    public const TYPE_AUDIT = 'AUDIT';

    public const TYPES = [
        self::TYPE_COFFRAC,
        self::TYPE_MAR,
        self::TYPE_AUDIT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'average_duration_minutes' => 'integer',
        ];
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
