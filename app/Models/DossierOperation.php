<?php

namespace App\Models;

use App\Services\Crm\DossierStatusCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DossierOperation extends Model
{
    protected $fillable = ['service_id', 'source', 'source_reference'];

    protected function casts(): array
    {
        return ['inspected_on' => 'date', 'control_submitted_at' => 'datetime', 'control_validated_at' => 'datetime'];
    }

    public function reportDocument(): BelongsTo
    {
        return $this->belongsTo(DossierDocument::class, 'report_document_id');
    }

    public function controlValidator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'control_validated_by')->withTrashed();
    }

    public function visitAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'visit_appointment_id')->withTrashed();
    }

    public function scopeAwaitingBooking(Builder $query): Builder
    {
        return $query->whereIn('legacy_status', DossierStatusCatalog::STATUSES['pending']['values'])
            ->whereHas('dossier', fn ($query) => $query->whereNull('dossiers.deleted_at'))
            ->whereDoesntHave('appointments', fn ($query) => $query->where('status', Appointment::STATUS_SCHEDULED));
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class)->withTrashed();
    }

    public function billing(): HasOne
    {
        return $this->hasOne(DossierBilling::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function capceeReports(): HasMany
    {
        return $this->hasMany(CapceeReport::class);
    }
}
