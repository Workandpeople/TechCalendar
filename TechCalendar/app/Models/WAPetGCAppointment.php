<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WAPetGCAppointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'WAPetGC_Appointments';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tech_id',
        'service_id', // Ajout du service
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

    /**
     * Boot function to assign a UUID when creating a new record.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString(); // Génère un UUID
            }
        });
    }

    // Relations
    public function tech()
    {
        return $this->belongsTo(WAPetGCTech::class, 'tech_id', 'id');
    }

    public function service()
    {
        return $this->belongsTo(WAPetGCService::class, 'service_id', 'id');
    }
}