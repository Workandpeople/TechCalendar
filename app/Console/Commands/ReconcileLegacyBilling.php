<?php

namespace App\Console\Commands;

use App\Services\Crm\LegacyBillingReconciliation;
use Illuminate\Console\Command;

class ReconcileLegacyBilling extends Command
{
    protected $signature = 'crm:reconcile-billing {--apply : Appliquer les marques et blocages de revue depuis les archives locales}';

    protected $description = 'Reprend les marquages historiques de facturation sans facture ni paiement invente.';

    public function handle(LegacyBillingReconciliation $reconciliation): int
    {
        $apply = (bool) $this->option('apply');
        $counts = $reconciliation->run($apply);
        $this->line($apply ? 'Application locale terminee.' : 'Simulation sans ecriture. Ajouter --apply pour appliquer.');
        $this->line(sprintf('created=%d existing=%d review=%d invalid=%d', ...array_values($counts)));

        return self::SUCCESS;
    }
}
