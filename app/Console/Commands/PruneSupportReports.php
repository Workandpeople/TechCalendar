<?php

namespace App\Console\Commands;

use App\Services\Support\SupportReports;
use Illuminate\Console\Command;

class PruneSupportReports extends Command
{
    protected $signature = 'support:prune';

    protected $description = 'Supprime les signalements privés de plus de 30 jours, sans envoi de mail.';

    public function handle(SupportReports $reports): int
    {
        $this->info($reports->prune().' signalement(s) expiré(s) supprimé(s).');

        return self::SUCCESS;
    }
}
