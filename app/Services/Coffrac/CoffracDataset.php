<?php

namespace App\Services\Coffrac;

use Generator;

interface CoffracDataset
{
    public const TABLES = ['clients', 'dossier_comment', 'dossier_document', 'dossier_fiche_facturation', 'dossier_fiche_rdv', 'dossier_fiches', 'dossier_historique', 'dossier_letige', 'dossiers', 'entreprise', 'fiche_document', 'fiche_type', 'lot_dossier', 'mail_templates', 'media', 'planings', 'rdv_extern', 'sms_tempalte', 'status', 'user_type', 'users'];

    public function rows(string $table): Generator;

    public function keyed(string $table): array;
}
