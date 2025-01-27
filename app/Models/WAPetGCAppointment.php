<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log; // <-- Ajouter

class WAPetGCAppointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'WAPetGC_Appointments';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tech_id',
        'service_id',
        'client_fname',
        'client_lname',
        'client_adresse',
        'client_zip_code',
        'client_city',
        'client_phone',
        'start_at',
        'duration',
        'end_at',
        'comment',
        'trajet_time',
        'trajet_distance',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            Log::info('[WAPetGCAppointment] Creating new appointment', [
                'attributes' => $model->attributes
            ]);

            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    public function tech()
    {
        return $this->belongsTo(WAPetGCTech::class, 'tech_id', 'id');
    }

    public function service()
    {
        return $this->belongsTo(WAPetGCService::class, 'service_id', 'id');
    }
}
