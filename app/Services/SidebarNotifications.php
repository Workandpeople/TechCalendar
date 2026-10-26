<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DossierOperation;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\User;

class SidebarNotifications
{
    public function forUser(User $user): array
    {
        if ($user->isExternal()) {
            return ['settings' => 0, 'today' => 0, 'pending' => 0];
        }

        return [
            'settings' => $user->admin ? app(ApplicationSettings::class)->missingCount() : 0,
            'today' => Appointment::query()->where('technician_id', $user->id)
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('starts_at', '>=', today())->where('starts_at', '<', today()->addDay())->count(),
            'pending' => $user->admin || in_array($user->role, [0, 1], true) ? $this->pendingAppointmentsCount() : 0,
        ];
    }

    private function pendingAppointmentsCount(): int
    {
        $requests = config('crm.v2_enabled')
            ? DossierOperation::query()->awaitingBooking()->count()
            : app(CoffracAppointmentService::class)->pendingCountLocally();

        $lotRequests = LotAppointment::query()
            ->whereNull('appointment_id')
            ->whereIn('status', [LotAppointment::STATUS_PENDING, LotAppointment::STATUS_NEEDS_REVIEW])
            ->where('excluded_from_lot_stats', false)
            ->whereHas('lot', fn ($query) => $query->where('status', '!=', Lot::STATUS_COMPLETED))
            ->where(function ($query): void {
                // Match booking eligibility: completed contacts are not pending appointments.
                $query->where('processing_mode', LotAppointment::PROCESSING_MODE_PHYSICAL)
                    ->orWhere(fn ($query) => $query->whereNull('processing_mode')->whereNull('contact_satisfaction'));
            })
            ->count();

        return $requests + $lotRequests;
    }
}
