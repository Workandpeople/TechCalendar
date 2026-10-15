<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dossier extends Model
{
    protected $fillable = [
        'reference', 'source', 'source_reference', 'delegataire_id', 'created_by',
        'company_name', 'first_name', 'last_name', 'email', 'phone', 'site_name',
        'address', 'postal_code', 'city', 'beneficiary_address',
        'beneficiary_postal_code', 'beneficiary_city', 'installer_name', 'installer_siren',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'planning_user_id' => 'integer'];
    }

    public function beneficiaryLabel(): string
    {
        return $this->company_name ?: (trim($this->first_name.' '.$this->last_name) ?: ($this->legacy_label ?: $this->reference));
    }

    public function delegataire(): BelongsTo
    {
        return $this->belongsTo(ExternalDelegataire::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(DossierOperation::class);
    }

    public function planningUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planning_user_id')->withTrashed();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DossierActivity::class);
    }

    public function latestActivity(): HasOne
    {
        return $this->hasOne(DossierActivity::class)->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    public function listingAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'listing_appointment_id')->withTrashed();
    }

    public function comments(): HasMany
    {
        return $this->activities()->whereIn('kind', ['comment', 'legacy_comment', 'post_visit']);
    }

    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(Appointment::class, DossierOperation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DossierDocument::class);
    }
}
