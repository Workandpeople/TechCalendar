<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MymarBookingRequest extends Model
{
    public const LABELS = [
        'queued' => 'Demande en attente de transmission',
        'dispatching' => 'Confirmation MYMAR en cours',
        'uncertain' => 'Resultat a verifier, ne pas renouveler la demande',
        'confirmed' => 'Action confirmee par MYMAR',
        'rejected' => 'Action refusee, aucun changement confirme',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['command', 'claim_id', 'fingerprint'];

    protected function casts(): array
    {
        return ['command' => 'encrypted:array', 'result' => 'array', 'author_id' => 'integer',
            'source_dossier_id' => 'integer', 'active_dossier_id' => 'integer', 'lease_until' => 'integer',
            'attempts' => 'integer', 'finished_at' => 'datetime', 'cofrac_technician_id' => 'integer',
            'reserved_starts_at' => 'datetime', 'reserved_ends_at' => 'datetime'];
    }
}
