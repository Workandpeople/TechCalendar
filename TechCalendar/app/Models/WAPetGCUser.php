<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;

class WAPetGCUser extends Authenticatable
{
    use HasFactory, SoftDeletes;

    protected $table = 'WAPetGC_Users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

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

    // Relations
    public function tech()
    {
        return $this->hasOne(WAPetGCTech::class, 'user_id', 'id');
    }
}