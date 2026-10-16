<?php

namespace App\Jobs;

use App\Models\DossierSms;
use App\Services\Sms\DossierSmsProcessor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDossierSms implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $smsId) {}

    public function uniqueId(): string
    {
        return (string) $this->smsId;
    }

    public function handle(DossierSmsProcessor $processor): void
    {
        $processor->process($this->smsId);
    }

    public function failed(?Throwable $exception): void
    {
        DossierSms::whereKey($this->smsId)->where('status', 'sending')->update([
            'status' => 'unknown', 'finished_at' => now(),
            'last_error' => 'Worker interrompu : vérifier SMSFactor avant tout nouvel envoi.',
        ]);
        Log::warning('SMS : worker interrompu.', ['sms_id' => $this->smsId, 'exception_class' => $exception ? $exception::class : null]);
    }
}
