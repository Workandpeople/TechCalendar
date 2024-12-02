<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'nom', 'prenom', 'email', 'password', 'telephone', 'adresse', 'code_postal', 'ville', 
        'default_start_at', 'default_end_at', 'default_traject_time', 'default_rest_time'
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }

    // Relation avec le modèle Role
    public function role()
    {
        return $this->hasOne(Role::class, 'user_id', 'id');
    }

    public function rendezvous()
    {
        return $this->hasMany(Rendezvous::class, 'technician_id', 'id');
    }
}