<?php

namespace App\Services\Sms;

use Illuminate\Validation\ValidationException;

class SmsConfiguration
{
    public function mode(): string
    {
        return app()->environment('local', 'demo') || config('simulation.enabled')
            ? 'simulate' : (string) config('services.smsfactor.mode', 'simulate');
    }

    public function unavailable(): ?string
    {
        if (! config('services.smsfactor.enabled')) {
            return 'SMS désactivés : renseigner SMSFACTOR_ENABLED dans la configuration V2.';
        }
        if (! config('crm.v2_enabled')) {
            return 'Le registre V2 doit être activé.';
        }
        if (blank(config('services.smsfactor.token'))) {
            return 'Clé SMSFactor absente : renseigner SMSFACTOR_API_TOKEN.';
        }
        $sender = (string) config('services.smsfactor.sender', '');
        if ($sender !== '' && ! preg_match('/\A(?=.*[A-Za-z])[A-Za-z0-9]{1,11}\z/', $sender)) {
            return 'Expéditeur invalide : 11 caractères alphanumériques maximum, avec au moins une lettre (SMSFACTOR_SENDER).';
        }
        if (! in_array($this->mode(), ['simulate', 'live'], true)) {
            return 'SMSFACTOR_MODE doit valoir simulate ou live.';
        }
        if ((config('simulation.enabled') || app()->environment('demo')) && ! config('simulation.allow_external_apis')) {
            return 'La simulation interdit les API externes. Activer SIMULATION_ALLOW_EXTERNAL_APIS pour tester SMSFactor sans envoyer.';
        }
        $connection = config('services.smsfactor.queue_connection');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            return 'Une queue asynchrone est obligatoire pour les SMS (SMSFACTOR_QUEUE_CONNECTION).';
        }

        return null;
    }

    public function assertReady(): void
    {
        if ($message = $this->unavailable()) {
            throw ValidationException::withMessages(['sms' => $message]);
        }
    }
}
