<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'dashboard',
    'period_start',
    'period_end',
    'status',
    'total_steps',
    'processed_steps',
    'result',
    'error_message',
    'started_at',
    'finished_at',
])]
class DashboardMetricRun extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_steps' => 'integer',
            'processed_steps' => 'integer',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
