<?php

namespace App\Services;

use App\Models\Appointment;

class AppointmentMailTemplateData
{
    /**
     * @return array<string, string>
     */
    public function forAppointment(Appointment $appointment): array
    {
        $appointment->loadMissing([
            'service:id,type,name',
            'technician:id,first_name,last_name,email',
            'creator:id,first_name,last_name,email',
        ]);

        $customerName = trim($appointment->customer_first_name.' '.$appointment->customer_last_name);
        $serviceLabel = $appointment->service
            ? trim($appointment->service->type.' - '.$appointment->service->name)
            : 'Prestation non renseignée';

        return [
            'app_name' => config('app.name', 'Tech Calendar'),
            'appointment_id' => (string) $appointment->id,
            'client_name' => $customerName,
            'customer_name' => $customerName,
            'customer_first_name' => (string) $appointment->customer_first_name,
            'customer_last_name' => (string) $appointment->customer_last_name,
            'customer_phone' => (string) $appointment->customer_phone,
            'customer_email' => $this->defaultRecipientEmail($appointment) ?? '',
            'company_name' => (string) $this->firstFilledFromPayload($appointment, ['company_name', 'customer_company_name', 'raison_sociale']),
            'site_name' => (string) $this->firstFilledFromPayload($appointment, ['site_name', 'site', 'nom_site']),
            'technician_name' => (string) ($appointment->technician?->full_name ?? ''),
            'technician_email' => (string) ($appointment->technician?->email ?? ''),
            'service_label' => $serviceLabel,
            'service_type' => (string) ($appointment->service?->type ?? ''),
            'service_name' => (string) ($appointment->service?->name ?? ''),
            'appointment_date' => $appointment->starts_at?->format('d/m/Y') ?? '',
            'appointment_time' => $appointment->starts_at?->format('H:i') ?? '',
            'appointment_end_time' => $appointment->ends_at?->format('H:i') ?? '',
            'appointment_duration' => $appointment->duration_minutes ? $appointment->duration_minutes.' min' : '',
            'address' => (string) $appointment->address,
            'comment' => (string) ($appointment->comment ?? ''),
            'manager_name' => (string) ($appointment->creator?->full_name ?? ''),
        ];
    }

    public function defaultRecipientEmail(Appointment $appointment): ?string
    {
        if (config('crm.v2_enabled') && $appointment->dossier_operation_id !== null) {
            $appointment->loadMissing('dossierOperation.dossier');
            $email = trim((string) $appointment->dossierOperation?->dossier?->email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        $payload = is_array($appointment->external_payload) ? $appointment->external_payload : [];
        foreach ([
            'customer_email',
            'client_email',
            'email',
            'contact_email',
            'contact.email',
            'customer.email',
            'client.email',
        ] as $key) {
            $value = data_get($payload, $key);
            if (is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstFilledFromPayload(Appointment $appointment, array $keys): ?string
    {
        $payload = is_array($appointment->external_payload) ? $appointment->external_payload : [];

        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
