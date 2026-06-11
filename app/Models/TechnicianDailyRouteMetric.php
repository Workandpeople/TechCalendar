<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'technician_id',
    'service_date',
    'appointment_count',
    'drive_distance_km',
    'drive_duration_minutes',
    'overtime_minutes',
    'calculation_source',
    'route_hash',
    'route_points',
    'calculated_at',
])]
class TechnicianDailyRouteMetric extends Model
{
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'appointment_count' => 'integer',
            'drive_distance_km' => 'float',
            'drive_duration_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'route_points' => 'array',
            'calculated_at' => 'datetime',
        ];
    }
}
