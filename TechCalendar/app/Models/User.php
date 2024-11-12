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
        'nom',
        'prenom',
        'email',
        'password',
        'telephone',
        'adresse',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected static function boot()
    {
        parent::boot();

        // Génération automatique d'un UUID lors de la création de l'utilisateur
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }

    // Définir la relation avec le modèle Role
    public function role()
    {
        return $this->hasOne(Role::class, 'user_id');
    }

    // Définir les relations pour les jours de la semaine
    public function monday() { return $this->hasMany(Monday::class, 'user_id'); }
    public function tuesday() { return $this->hasMany(Tuesday::class, 'user_id'); }
    public function wednesday() { return $this->hasMany(Wednesday::class, 'user_id'); }
    public function thursday() { return $this->hasMany(Thursday::class, 'user_id'); }
    public function friday() { return $this->hasMany(Friday::class, 'user_id'); }
    public function saturday() { return $this->hasMany(Saturday::class, 'user_id'); }
    public function sunday() { return $this->hasMany(Sunday::class, 'user_id'); }
}