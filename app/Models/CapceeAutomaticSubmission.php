<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapceeAutomaticSubmission extends Model
{
    protected $guarded = ['id'];

    public const BLOCKS = [
        'configuration' => 'API désactivée ou configuration incomplète. Aucun envoi effectué.',
        'metadata' => 'Référence EMMY, objet ou type de contrôle à renseigner.',
        'document' => 'Rapport indisponible ou contrôle non validé : vérification nécessaire.',
        'delegataire' => 'Délégataire modifié, inactif ou accès CAPCEE désactivé.',
        'actor' => 'Le validateur ne dispose plus des droits de transmission.',
        'environment' => 'Environnement CAPCEE différent : vérification nécessaire.',
        'previous_delivery' => 'Envoi précédent à terminer ou cible à vérifier avant cette version.',
        'preparation' => 'Préparation interrompue. Le rapport local est conservé ; aucun nouvel envoi confirmé.',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DossierDocument::class, 'dossier_document_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(CapceeDelivery::class, 'capcee_delivery_id');
    }
}
