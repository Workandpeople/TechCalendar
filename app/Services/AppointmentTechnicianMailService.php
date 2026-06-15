<?php

namespace App\Services;

use App\Mail\TechnicianAppointmentNotificationMail;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AppointmentTechnicianMailService
{
    public function created(Appointment $appointment): void
    {
        $this->notifyCurrentTechnician($appointment, 'created');
    }

    public function detailsUpdated(Appointment $appointment): void
    {
        $this->notifyCurrentTechnician($appointment, 'details_updated');
    }

    public function commentUpdated(Appointment $appointment): void
    {
        $this->notifyCurrentTechnician($appointment, 'comment_updated');
    }

    public function cancelled(Appointment $appointment): void
    {
        $this->notifyCurrentTechnician($appointment, 'cancelled');
    }

    public function restored(Appointment $appointment): void
    {
        $this->notifyCurrentTechnician($appointment, 'restored');
    }

    public function reassigned(Appointment $appointment, int $previousTechnicianId): void
    {
        $previousTechnician = User::withTrashed()
            ->whereKey($previousTechnicianId)
            ->first();

        $this->notify($appointment, $previousTechnician, 'reassigned_from');
        $this->notifyCurrentTechnician($appointment, 'reassigned_to');
    }

    private function notifyCurrentTechnician(Appointment $appointment, string $eventType): void
    {
        $appointment = $this->freshAppointment($appointment);

        $this->notify($appointment, $appointment->technician, $eventType);
    }

    private function notify(Appointment $appointment, ?User $recipient, string $eventType): void
    {
        if (! $recipient || ! filled($recipient->email)) {
            return;
        }

        Mail::to($recipient->email)->queue(
            new TechnicianAppointmentNotificationMail(
                appointment: $this->freshAppointment($appointment),
                recipient: $recipient,
                eventType: $eventType,
            )
        );
    }

    private function freshAppointment(Appointment $appointment): Appointment
    {
        return Appointment::withTrashed()
            ->with(['service:id,type,name', 'technician:id,first_name,last_name,email', 'creator:id,first_name,last_name'])
            ->findOrFail($appointment->id);
    }
}
