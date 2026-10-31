<?php

namespace App\Console\Commands;

use App\Services\Mymar\MymarPendingBookings;
use Illuminate\Console\Command;
use Throwable;

class MymarPendingProbe extends Command
{
    protected $signature = 'mymar:pending-probe';

    protected $description = 'Verifie une seule demande MAR en lecture seule, sans afficher ses donnees.';

    public function handle(MymarPendingBookings $pending): int
    {
        try {
            $page = $pending->page(0, 1, true);
            $this->line(json_encode(['reader_ready' => true, 'pending_items_probed' => count($page['items']), 'writes_enabled' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"reader_ready":false,"writes_enabled":false}');

            return self::FAILURE;
        }
    }
}
