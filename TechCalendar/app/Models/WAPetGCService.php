<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WAPetGCService extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'WAPetGC_Services';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'type',
        'name',
        'default_time',
    ];
}