<?php

namespace App\Console\Commands;

use App\Services\Crm\LotReportExports;
use Illuminate\Console\Command;

class PruneLotReportExports extends Command
{
    protected $signature = 'reports:prune';

    protected $description = 'Supprime uniquement les archives ZIP temporaires expirées, jamais les rapports originaux.';

    public function handle(LotReportExports $exports): int
    {
        $this->info($exports->prune().' archive(s) expirée(s) supprimée(s).');

        return self::SUCCESS;
    }
}
