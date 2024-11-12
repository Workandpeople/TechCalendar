<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Sunday extends Model
{
    use HasFactory;

    protected $table = 'tuesday';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'start',
        'end'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
