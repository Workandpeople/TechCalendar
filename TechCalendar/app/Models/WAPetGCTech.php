<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log; // <-- Ajouter

class WAPetGCTech extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'WAPetGC_Tech';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'phone',
        'adresse',
        'zip_code',
        'city',
        'default_start_at',
        'default_end_at',
        'default_rest_time',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            Log::info('[WAPetGCTech] Creating new tech', [
                'attributes' => $model->attributes
            ]);

            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(WAPetGCUser::class, 'user_id', 'id');
    }

    public function appointments()
    {
        return $this->hasMany(WAPetGCAppointment::class, 'tech_id', 'id');
    }
}