<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CapceeReport extends Model
{
    protected $guarded = ['id'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(DossierOperation::class, 'dossier_operation_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(CapceeDelivery::class);
    }

    public function latestDelivery(): HasOne
    {
        return $this->hasOne(CapceeDelivery::class)->latestOfMany();
    }
}
