<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierSms extends Model
{
    protected $table = 'dossier_sms';

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'body', 'submission_token', 'fingerprint'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'body' => 'encrypted', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'dossier_id' => 'integer', 'requested_by' => 'integer', 'appointment_id' => 'integer'];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => 'En attente du worker',
            'sending' => $this->started_at?->lt(now()->subMinutes(2)) ? 'Résultat inconnu : vérifier chez SMSFactor' : 'Transmission en cours',
            'accepted' => 'Accepté par SMSFactor (livraison non confirmée)',
            'simulated' => 'Simulation réussie : aucun SMS envoyé',
            'rejected' => 'Refusé par SMSFactor',
            'blocked' => 'Non envoyé : configuration ou accès à vérifier',
            default => 'Résultat inconnu : vérifier chez SMSFactor avant tout nouvel envoi',
        };
    }
}
