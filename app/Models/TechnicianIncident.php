<?php

namespace App\Models;

use App\Services\Tech\TechnicianOnSiteReports;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianIncident extends Model
{
    public const PLANNING_STATUSES = ['pending' => 'À traiter', 'resolved' => 'Traité'];

    public const BILLING_STATUSES = [
        'review' => 'Déplacement à vérifier', 'billable' => 'Déplacement à facturer',
        'invoiced' => 'Déplacement facturé', 'waived' => 'Déplacement non facturable',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'appointment_starts_at' => 'datetime',
            'mymar_dossier_id' => 'integer', 'mymar_rdv_id' => 'integer', 'source_context' => 'array',
            'handled_at' => 'datetime', 'billing_reviewed_at' => 'datetime', 'version' => 'integer'];
    }

    public function sourceRequest(): BelongsTo
    {
        return $this->belongsTo(MymarBookingRequest::class, 'request_id');
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class)->withTrashed();
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id')->withTrashed();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DossierDocument::class)->withTrashed();
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by')->withTrashed();
    }

    public function billingReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billing_reviewed_by')->withTrashed();
    }

    public function reasonLabel(): string
    {
        return TechnicianOnSiteReports::REASONS[$this->reason] ?? 'Problème sur place';
    }
}
