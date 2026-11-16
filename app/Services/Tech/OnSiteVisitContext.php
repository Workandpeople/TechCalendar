<?php

namespace App\Services\Tech;

use App\Models\Appointment;
use App\Models\User;
use App\Services\Crm\DossierStatusCatalog;

final class OnSiteVisitContext
{
    public function booking(Appointment $appointment): array
    {
        return [
            'appointment_id' => (int) $appointment->id, 'technician_id' => (int) $appointment->technician_id,
            'operation_id' => (int) $appointment->dossier_operation_id,
            'dossier_id' => (int) $appointment->dossierOperation?->dossier_id,
            'service_id' => (int) $appointment->service_id,
            'starts_at' => $appointment->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $appointment->ends_at?->format('Y-m-d H:i:s'),
            'address' => (string) $appointment->address,
            'first_name' => (string) $appointment->customer_first_name, 'last_name' => (string) $appointment->customer_last_name,
            'source' => (string) $appointment->external_source, 'reference' => (string) $appointment->external_reference,
        ];
    }

    public function fingerprint(Appointment $appointment): string
    {
        // Contact notes, GPS refinements and problem flags do not identify a different visit.
        return hash_hmac('sha256', 'on-site-booking-v2:'.json_encode($this->booking($appointment), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function issue(Appointment $appointment): array
    {
        $json = json_encode(['version' => 1, 'issued_at' => now('UTC')->timestamp, 'booking' => $this->booking($appointment)], JSON_THROW_ON_ERROR);

        return ['report_context' => $json, 'context_signature' => $this->sign($json)];
    }

    public function read(User $actor, Appointment $appointment, array $data): ?array
    {
        $json = $data['report_context'] ?? null;
        $signature = $data['context_signature'] ?? null;
        if (! is_string($json) || strlen($json) > 4096 || ! is_string($signature) || strlen($signature) !== 64
            || ! hash_equals($this->sign($json), $signature)) {
            return null;
        }
        $context = json_decode($json, true, 16);
        if (! is_array($context)) {
            return null;
        }
        $booking = $context['booking'] ?? null;
        if (! is_array($booking) || ! is_string($booking['starts_at'] ?? null) || ($context['version'] ?? null) !== 1 || ! is_int($context['issued_at'] ?? null)
            || $context['issued_at'] > now('UTC')->timestamp || now('UTC')->timestamp - $context['issued_at'] >= 86400
            || ($booking['appointment_id'] ?? null) !== (int) $appointment->id || ($booking['technician_id'] ?? null) !== (int) $actor->id
            || ($booking['operation_id'] ?? null) !== (int) $appointment->dossier_operation_id
            || ($booking['dossier_id'] ?? null) !== (int) $appointment->dossierOperation?->dossier_id) {
            return null;
        }

        return $booking;
    }

    public function allowsHistorical(User $actor, Appointment $appointment, array $data): bool
    {
        return config('crm.v2_enabled') && ! $actor->trashed() && ! $actor->isExternal() && ! $actor->must_change_password
            && ($actor->role === 2 || $actor->admin) && ! $appointment->trashed()
            && ! in_array($appointment->external_source, ['mymar', 'mar'], true)
            && in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_PROBLEM], true)
            && $appointment->dossierOperation?->dossier && ! $appointment->dossierOperation->dossier->trashed()
            && in_array(DossierStatusCatalog::describe($appointment->dossierOperation->legacy_status)['key'], ['pending', 'awaiting_visit', 'problem'], true)
            && $this->read($actor, $appointment, $data) !== null;
    }

    private function sign(string $json): string
    {
        return hash_hmac('sha256', 'on-site-evidence-v1:'.$json, config('app.key'));
    }
}
