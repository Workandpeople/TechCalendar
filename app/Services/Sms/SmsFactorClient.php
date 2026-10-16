<?php

namespace App\Services\Sms;

use App\Models\DossierSms;
use Illuminate\Support\Facades\Http;

class SmsFactorClient
{
    public function send(DossierSms $sms): array
    {
        $config = app(SmsConfiguration::class);
        $config->assertReady();
        if ($sms->mode !== $config->mode() || $sms->sender !== (string) config('services.smsfactor.sender', '')) {
            return ['status' => 'blocked', 'last_error' => 'Configuration modifiée depuis la confirmation. Aucun appel effectué.'];
        }
        // No retry or redirect: a timeout/5xx can occur after provider acceptance.
        $response = Http::withToken(config('services.smsfactor.token'))->acceptJson()->asJson()
            ->connectTimeout(max(1, min(10, (int) config('services.smsfactor.connect_timeout'))))
            ->timeout(max(1, min(40, (int) config('services.smsfactor.timeout'))))
            ->withoutRedirecting()
            ->post('https://api.smsfactor.com'.($sms->mode === 'simulate' ? '/send/simulate' : '/send'), [
                'sms' => [
                    'message' => ['text' => $sms->body, 'sender' => $sms->sender, 'pushtype' => 'alert', 'unicode' => SmsText::metrics($sms->body)['unicode']],
                    'recipients' => ['gsm' => [['value' => ltrim($sms->recipient, '+'), 'gsmsmsid' => $sms->submission_token]]],
                ],
            ]);
        $data = $response->json();
        $code = is_array($data) && isset($data['status']) && is_numeric($data['status']) ? (int) $data['status'] : null;
        $ticket = is_array($data) && is_scalar($data['ticket'] ?? null) ? (string) $data['ticket'] : null;
        $result = ['http_status' => $response->status(), 'provider_status' => $code,
            'provider_ticket' => $ticket && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $ticket) ? $ticket : null];
        if ($response->successful() && $code === 1 && (int) ($data['sent'] ?? 0) === 1
            && ($sms->mode === 'simulate' || $result['provider_ticket'])) {
            return [...$result, 'status' => $sms->mode === 'simulate' ? 'simulated' : 'accepted', 'last_error' => null];
        }
        if ($code === -8 || $response->status() === 408 || $response->serverError()
            || ($code === 1 && (! isset($data['sent']) || (int) $data['sent'] > 0))
            || (! $response->clientError() && ! ($response->successful() && $code !== null))) {
            return [...$result, 'status' => 'unknown', 'last_error' => 'Réponse incertaine. Vérifier le compte SMSFactor avant tout nouvel envoi ; aucune relance automatique.'];
        }

        // Never persist the provider's raw response: it may echo numbers, text or credentials.
        return [...$result, 'status' => 'rejected', 'last_error' => $code === 1
            ? 'Destinataire filtré ou confirmation incomplète (numéro invalide, liste noire, limite ou anti-doublon). Vérifier SMSFactor.'
            : 'Envoi refusé (HTTP '.$response->status().', code '.($code ?? 'absent').'). Vérifier la clé, les crédits et les droits du compte SMSFactor.'];
    }
}
