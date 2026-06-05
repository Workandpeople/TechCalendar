<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name'])]
class Department extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user', 'department_code', 'user_id')
            ->withTimestamps();
    }
}
