<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WAPetGCAppointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'WAPetGC_Appointments';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tech_id',
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

    // Relations
    public function tech()
    {
        return $this->belongsTo(WAPetGCTech::class, 'tech_id', 'id');
    }
}