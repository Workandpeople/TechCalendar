<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Rendezvous extends Model
{
    use HasFactory;

    protected $table = 'rendezvous';
    public $incrementing = false;
    protected $keyType = 'string';


    protected $fillable = [
        'technician_id', 'nom', 'prenom', 'adresse', 'code_postal', 'ville', 'tel', 
        'date', 'start_at', 'prestation', 'duree', 'commentaire'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}