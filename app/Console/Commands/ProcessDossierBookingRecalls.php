<?php

namespace App\Console\Commands;

use App\Services\Crm\DossierBookingRecalls;
use Illuminate\Console\Command;

class ProcessDossierBookingRecalls extends Command
{
    protected $signature = 'dossiers:process-recalls
        {--apply : Appliquer, sinon controle en lecture seule}
        {--backfill : Preparer les anciens rappels structures, sans changer les etats}
        {--limit=200 : Nombre maximal de fiches examinees (1 a 5000)}
        {--after-id=0 : Reprendre la preparation historique apres cet identifiant de fiche}';

    protected $description = 'Remet en prise de RDV les seuls problemes dont le rappel arrive a echeance, sans envoi ni appel externe.';

    public function handle(DossierBookingRecalls $recalls): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (! config('crm.v2_enabled') || ($this->option('apply') && config('simulation.enabled')) || $limit === false || $after === false) {
            $this->error('V2 active hors simulation requise pour appliquer ; limite et curseur valides obligatoires. Aucun traitement effectue.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $backfill = (bool) $this->option('backfill');
        $counts = $backfill ? $recalls->backfill($apply, $limit, $after) : $recalls->process($apply, $limit);
        $this->line(json_encode(['dry_run' => ! $apply, 'backfill' => $backfill, ...$counts,
            'messages_sent' => false, 'external_requests' => false], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
