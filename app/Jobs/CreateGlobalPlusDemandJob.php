<?php

namespace App\Jobs;

use App\Models\LotAppointment;
use App\Models\User;
use App\Services\GlobalPlus\GlobalPlusAppointmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class CreateGlobalPlusDemandJob implements ShouldQueue
{
    use Queueable;

    // A timed-out POST may still have created the remote dossier: never repeat it automatically.
    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $lotAppointmentId,
        public readonly string $operationId,
        public readonly array $payload,
        public readonly ?int $actorId,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('global-plus-workflow:'.$this->lotAppointmentId))->shared()->releaseAfter(15)->expireAfter(330)];
    }

    public function handle(GlobalPlusAppointmentService $service): void
    {
        $appointment = LotAppointment::find($this->lotAppointmentId);
        if (! $appointment || data_get($appointment->global_plus_payload, 'workflow.id') !== $this->operationId
            || filled($appointment->global_plus_demand_id)
            || $appointment->global_plus_status !== GlobalPlusAppointmentService::STATUS_CREATION_PENDING) {
            return;
        }

        $service->createDemandFromLotAppointment($appointment, $this->payload, $this->actorId ? User::find($this->actorId) : null);
    }

    public function failed(Throwable $exception): void
    {
        app(GlobalPlusAppointmentService::class)->failWorkflow($this->lotAppointmentId, $this->operationId, $exception);
    }
}
