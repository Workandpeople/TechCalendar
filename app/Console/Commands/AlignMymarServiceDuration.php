<?php

namespace App\Console\Commands;

use App\Services\Mymar\MymarAfterWorksService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AlignMymarServiceDuration extends Command
{
    protected $signature = 'mymar:align-service-duration {--apply : Aligner la seule duree MAR Cofrac de 50 a 60 minutes}';

    protected $description = 'Controle la duree MAR Cofrac, sans acces ni modification de MYMAR.';

    public function handle(MymarAfterWorksService $services): int
    {
        return DB::transaction(function () use ($services): int {
            $service = $services->resolve(lock: true);
            if ($service->average_duration_minutes !== 50) {
                $services->duration($service);
                $this->info('Duree MAR Cofrac deja compatible : '.$service->average_duration_minutes.' min. Aucun changement.');

                return self::SUCCESS;
            }
            if ($this->option('apply')) {
                $service->update(['average_duration_minutes' => 60]);
                Log::info('Cofrac MAR service duration aligned.', ['service_id' => $service->id, 'before_minutes' => 50, 'after_minutes' => 60]);
                $this->info('Duree MAR Cofrac alignee : 50 -> 60 min. MYMAR inchange.');
            } else {
                $this->info('Duree MAR Cofrac : 50 min. --apply alignera cette seule prestation a 60 min. MYMAR inchange.');
            }

            return self::SUCCESS;
        });
    }
}
