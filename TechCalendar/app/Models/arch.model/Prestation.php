<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Prestation extends Model
{
    use HasFactory;

    protected $table = 'prestation';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['type', 'name', 'default_time'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }
}