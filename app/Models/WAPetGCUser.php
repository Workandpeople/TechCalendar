<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class WAPetGCUser extends Authenticatable
{
    use HasFactory, SoftDeletes, HasApiTokens;

    protected $table = 'WAPetGC_Users';
    protected $primaryKey = 'id';
    public $incrementing = false; // UUIDs ne s'incrémentent pas automatiquement
    protected $keyType = 'string'; // UUID est de type string

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Boot function to assign a UUID on model creation.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    // Relations
    public function tech()
    {
        return $this->hasOne(WAPetGCTech::class, 'user_id', 'id');
    }
}
