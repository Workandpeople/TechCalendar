<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\MobilePushToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebaseCloudMessagingService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function sendAppointmentNotification(Appointment $appointment, User $recipient, string $eventType): void
    {
        if (! (bool) $recipient->notification_push_enabled) {
            return;
        }

        if (! $this->isConfigured()) {
            return;
        }

        $tokens = MobilePushToken::query()
            ->where('user_id', $recipient->id)
            ->pluck('token');

        if ($tokens->isEmpty()) {
            return;
        }

        $appointment->loadMissing(['service:id,type,name']);
        $title = $this->eventLabel($eventType);
        $body = $this->bodyForAppointment($appointment);

        foreach ($tokens as $token) {
            $this->sendToToken((string) $token, [
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => [
                    'type' => 'appointment',
                    'event_type' => $eventType,
                    'appointment_id' => (string) $appointment->id,
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'appointments',
                        'click_action' => 'OPEN_APPOINTMENT',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ]);
        }
    }

    public function isConfigured(): bool
    {
        try {
            $serviceAccount = $this->serviceAccount();

            return filled($this->projectId())
                && filled($serviceAccount['client_email'] ?? null)
                && filled($serviceAccount['private_key'] ?? null);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function sendToToken(string $token, array $message): void
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post(sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->projectId()), [
                    'message' => ['token' => $token, ...$message],
                ]);

            if ($response->successful()) {
                MobilePushToken::query()->where('token', $token)->update(['last_used_at' => now()]);
                return;
            }

            if ($response->status() === 404 || str_contains($response->body(), 'UNREGISTERED')) {
                MobilePushToken::query()->where('token', $token)->delete();
                return;
            }

            Log::warning('FCM push failed.', [
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('FCM push exception.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase.fcm.access_token', now()->addMinutes(55), function (): string {
            $serviceAccount = $this->serviceAccount();
            $now = time();
            $assertion = $this->jwt([
                'iss' => $serviceAccount['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
            ], (string) $serviceAccount['private_key']);

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post(self::TOKEN_URI, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Impossible de récupérer le token OAuth Firebase.');
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function jwt(array $claims, string $privateKey): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $unsignedToken = $header.'.'.$payload;

        $signed = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new \RuntimeException('Signature JWT Firebase impossible.');
        }

        return $unsignedToken.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceAccount(): array
    {
        $json = config('services.firebase.service_account_json');

        if (filled($json)) {
            return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        }

        $path = config('services.firebase.service_account_path');

        if (! filled($path)) {
            return [];
        }

        $path = str_starts_with((string) $path, '/') ? (string) $path : base_path((string) $path);

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function projectId(): ?string
    {
        return config('services.firebase.project_id') ?: ($this->serviceAccount()['project_id'] ?? null);
    }

    private function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            'created' => 'Nouveau rendez-vous',
            'details_updated' => 'Rendez-vous modifié',
            'reassigned_to' => 'Rendez-vous ajouté',
            'reassigned_from' => 'Rendez-vous retiré',
            'cancelled' => 'Rendez-vous annulé',
            'restored' => 'Rendez-vous réactivé',
            'comment_updated' => 'Commentaire mis à jour',
            default => 'Mise à jour de rendez-vous',
        };
    }

    private function bodyForAppointment(Appointment $appointment): string
    {
        $customer = trim($appointment->customer_first_name.' '.$appointment->customer_last_name) ?: 'Client';
        $date = $appointment->starts_at?->format('d/m H:i') ?? 'date à confirmer';
        $service = $appointment->service ? $appointment->service->type.' - '.$appointment->service->name : 'Prestation';

        return sprintf('%s - %s - %s', $customer, $date, $service);
    }
}
