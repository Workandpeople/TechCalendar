<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapceeDelivery extends Model
{
    public const LABELS = [
        'queued' => 'En attente du worker', 'processing' => 'Envoi en cours',
        'sent' => 'Envoyé et rattaché', 'failed' => 'Envoi interrompu',
        'review' => 'Résultat à vérifier',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'revision' => 'integer', 'attempts' => 'integer',
            'size' => 'integer', 'next_attempt_at' => 'datetime', 'started_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CapceeReport::class, 'capcee_report_id');
    }

    public function active(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }
}
