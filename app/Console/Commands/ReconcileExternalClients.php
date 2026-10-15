<?php

namespace App\Console\Commands;

use App\Services\Crm\LegacyClientReconciliation;
use Illuminate\Console\Command;

class ReconcileExternalClients extends Command
{
    protected $signature = 'clients:reconcile-legacy {--apply : Appliquer les rapprochements sur la base locale de cette application}';

    protected $description = 'Rapproche les organisations et dossiers depuis les archives Coffrac locales, sans ouvrir de comptes.';

    public function handle(LegacyClientReconciliation $reconciliation): int
    {
        if (! config('crm.v2_enabled')) {
            $this->error('Disponible uniquement en V2.');

            return self::FAILURE;
        }
        $this->info($this->option('apply') ? 'Rapprochement local. Aucun appel Coffrac, aucun compte active.' : 'Simulation sans ecriture. Ajouter --apply pour appliquer.');
        $this->table(['Controle', 'Nombre'], collect($reconciliation->run((bool) $this->option('apply')))->map(fn ($count, $key) => [$key, $count])->values()->all());

        return self::SUCCESS;
    }
}
