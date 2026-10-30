<?php

namespace App\Console\Commands;

use App\Services\Mymar\MymarObservationSync;
use Illuminate\Console\Command;

class SyncMymarObservation extends Command
{
    protected $signature = 'mymar:sync-observation';

    protected $description = 'Actualise uniquement le cache pilote MYMAR, sans modifier les RDV ou les disponibilites.';

    public function handle(MymarObservationSync $sync): int
    {
        $result = $sync->run();
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
