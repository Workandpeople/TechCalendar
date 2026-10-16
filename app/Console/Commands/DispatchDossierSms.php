<?php

namespace App\Console\Commands;

use App\Models\DossierSms;
use App\Services\Sms\DossierSmsService;
use App\Services\Sms\SmsConfiguration;
use Illuminate\Console\Command;

class DispatchDossierSms extends Command
{
    protected $signature = 'sms:dispatch';

    protected $description = 'Remet en file les SMS confirmés jamais transmis, sans reprendre les envois incertains';

    public function handle(DossierSmsService $service, SmsConfiguration $config): int
    {
        if ($reason = $config->unavailable()) {
            $this->error($reason);

            return self::FAILURE;
        }
        $messages = DossierSms::where('status', 'queued')->orderBy('id')->limit(100)->get();
        foreach ($messages as $sms) {
            $service->dispatch($sms);
        }
        $this->info($messages->count().' demande(s) de mise en file. Résultat à consulter sur les dossiers.');

        return self::SUCCESS;
    }
}
