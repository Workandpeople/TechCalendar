<?php

namespace App\Console\Commands;

use App\Services\Crm\NativeServiceCatalog;
use Illuminate\Console\Command;

class ReconcileNativeServices extends Command
{
    protected $signature = 'crm:reconcile-services';

    protected $description = 'Rapproche les prestations natives du snapshot collecté, sans appel distant';

    public function handle(NativeServiceCatalog $catalog): int
    {
        if (! config('simulation.enabled') || ! app()->environment('local', 'testing')) {
            $this->error('Commande réservée à la simulation locale.');

            return self::FAILURE;
        }
        $mapping = $catalog->reconcile();
        $this->info(count($mapping).' prestations natives disponibles. Aucune donnée source supprimée.');

        return self::SUCCESS;
    }
}
