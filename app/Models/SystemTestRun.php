<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'triggered_by',
    'suite',
    'status',
    'command',
    'exit_code',
    'output',
    'error_message',
    'started_at',
    'finished_at',
])]
class SystemTestRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ERROR = 'error';

    public const SUITE_ALL = 'all';

    public const SUITE_UNIT = 'unit';

    public const SUITE_FEATURE = 'feature';

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    protected function casts(): array
    {
        return [
            'command' => 'array',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
