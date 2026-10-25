<?php

namespace App\Console\Commands;

use App\Models\CapceeAutomaticSubmission;
use App\Models\CapceeDelivery;
use App\Services\Capcee\AutomaticCapceeReports;
use App\Services\Capcee\CapceeDeliveryProcessor;
use App\Services\Capcee\CapceeReports;
use Illuminate\Console\Command;

class DispatchCapceeReports extends Command
{
    protected $signature = 'capcee:dispatch';

    protected $description = 'Reprend les livraisons CAPCEE validées, en attente ou interrompues.';

    public function handle(CapceeReports $reports): int
    {
        if ($reason = $reports->availability()) {
            $this->warn($reason);

            return self::FAILURE;
        }
        $count = 0;
        CapceeAutomaticSubmission::query()->with('document')->whereNull('capcee_delivery_id')
            ->where('environment', config('capcee.environment'))
            ->whereIn('block_reason', ['configuration', 'previous_delivery', 'preparation'])
            ->orderBy('updated_at')->orderBy('id')->limit(50)->get()
            ->each(fn ($submission) => app(AutomaticCapceeReports::class)->dispatch($submission));
        CapceeDelivery::query()->whereHas('report', fn ($query) => $query->where('environment', config('capcee.environment')))
            ->where(function ($query): void {
                $query->where(fn ($query) => $query->where('status', 'queued')->where('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($query) => $query->where('status', 'processing')->where('started_at', '<=', now()->subSeconds(CapceeDeliveryProcessor::LEASE_SECONDS)));
            })->orderBy('id')->limit(100)->get()->each(function ($delivery) use ($reports, &$count): void {
                $reports->dispatch($delivery);
                $count++;
            });
        $this->info($count.' livraison(s) proposée(s) au worker.');

        return self::SUCCESS;
    }
}
