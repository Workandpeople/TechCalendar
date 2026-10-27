<?php

namespace App\Services\Admin;

use App\Models\MailSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class ConnectionDiagnostics
{
    public function catalog(): array
    {
        return [
            'database' => ['label' => 'Base de données', 'description' => 'Lecture SQL, sans modification.'],
            'cache' => ['label' => 'Cache', 'description' => 'Lecture du cache configuré.'],
            'mail' => ['label' => 'Service mail', 'description' => 'Connexion SMTP et authentification, sans email.'],
            'mapbox' => ['label' => 'Mapbox', 'description' => 'Validité du token, sans calcul de trajet.'],
            'openai' => ['label' => 'OpenAI', 'description' => 'Accès à la liste des modèles, sans génération.'],
            'global_plus' => ['label' => 'Global+', 'description' => 'Authentification seule, sans dossier ni document.'],
            'capcee' => ['label' => 'CAPCEE', 'description' => 'Authentification seule si le connecteur est activé.'],
            'smsfactor' => ['label' => 'SMSFactor', 'description' => 'Lecture des crédits, sans SMS ni débit.'],
        ];
    }

    public function mailSenders(): array
    {
        if (config('crm.v2_enabled')) {
            return [];
        }

        return MailSender::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (MailSender $sender): array => ['id' => $sender->id, 'name' => $sender->name])->all();
    }

    public function run(string $key, ?int $mailSenderId = null): array
    {
        if (! array_key_exists($key, $this->catalog())) {
            throw new InvalidArgumentException('Unknown diagnostic.');
        }
        $started = hrtime(true);
        try {
            [$status, $message] = match ($key) {
                'database' => $this->database(),
                'cache' => $this->cache(),
                'mail' => $this->mail($mailSenderId),
                'mapbox' => $this->mapbox(),
                'openai' => $this->openai(),
                'global_plus' => $this->globalPlus(),
                'capcee' => $this->capcee(),
                'smsfactor' => $this->smsfactor(),
            };
        } catch (ConnectionException) {
            [$status, $message] = ['error', 'Connexion impossible ou délai dépassé. Vérifier le réseau, le DNS et TLS du serveur.'];
        } catch (RequestException $exception) {
            $code = $exception->response->status();
            [$status, $message] = ['error', match (true) {
                in_array($code, [401, 403], true) => "Accès refusé (HTTP {$code}). Vérifier les identifiants et les droits de la clé.",
                $code === 429 => 'Limite du fournisseur atteinte (HTTP 429). Réessayer plus tard.',
                default => "Réponse distante inattendue (HTTP {$code}).",
            }];
        } catch (Throwable) {
            // Remote responses and exception messages may contain credentials: never return or log them.
            [$status, $message] = ['error', $key === 'mail'
                ? 'Connexion SMTP ou authentification impossible. Vérifier hôte, port, TLS et identifiants. Aucun email envoyé.'
                : 'Test impossible. Vérifier la configuration enregistrée et la disponibilité du service.'];
        }

        return ['key' => $key, 'status' => $status, 'message' => $message,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'checked_at' => now()->toIso8601String()];
    }

    private function database(): array
    {
        DB::connection()->select('SELECT 1');

        return ['ok', 'Connexion SQL et lecture réussies. Aucune donnée modifiée.'];
    }

    private function cache(): array
    {
        Cache::get('application-settings:connection-probe');

        return in_array(config('cache.default'), ['array', 'null'], true)
            ? ['warning', 'Cache local ou désactivé : aucune connexion persistante à vérifier.']
            : ['ok', 'Lecture du cache réussie. Aucune clé créée ou effacée.'];
    }

    private function mail(?int $senderId): array
    {
        if (config('crm.v2_enabled')) {
            $senderId = null;
        }
        if (app()->environment('demo') || (config('simulation.enabled') && ! config('simulation.allow_external_apis'))) {
            return ['warning', 'Connexion SMTP bloquée en démo ou simulation isolée. Aucun appel effectué.'];
        }
        if ($senderId !== null) {
            $sender = MailSender::query()->where('is_active', true)->find($senderId);
            if (! $sender) {
                return ['warning', 'Cet expéditeur est absent, désactivé ou supprimé.'];
            }
            $configuration = $sender->smtpConfig();
        } else {
            $configuration = config('mail.mailers.'.config('mail.default'), []);
            if (($configuration['transport'] ?? null) !== 'smtp') {
                return ['warning', 'Le transport par défaut n’est pas SMTP (simulation ou autre transport). Choisir un expéditeur SMTP pour tester sa connexion.'];
            }
        }
        $authenticated = app(SmtpConnectionProbe::class)->check($configuration);
        $message = $authenticated ? 'Connexion SMTP et authentification réussies.' : 'Connexion SMTP réussie ; authentification non confirmée (serveur sans AUTH ou identifiants absents).';

        $delivery = config('mail.delivery_enabled') ? '' : ' La livraison réelle est désactivée sur ce serveur.';

        return [$authenticated ? 'ok' : 'warning', $message.$delivery.' Aucun email envoyé ; le droit d’utiliser l’adresse d’expédition et la livraison en boîte de réception ne sont pas testés.'];
    }

    private function mapbox(): array
    {
        $token = config('services.mapbox.token');
        if (blank($token)) {
            return $this->missing();
        }
        $response = $this->http()->get('https://api.mapbox.com/tokens/v2', ['access_token' => $token])->throw();

        return $response->json('code') === 'TokenValid'
            ? ['ok', 'Token Mapbox valide. Les droits de géocodage et de trajet ne sont pas testés.']
            : ['error', 'Token Mapbox invalide, expiré ou révoqué.'];
    }

    private function openai(): array
    {
        $key = config('services.openai.api_key');
        if (blank($key)) {
            return $this->missing();
        }
        $response = $this->http()->withToken($key)->get('https://api.openai.com/v1/models')->throw();

        return $response->json('object') === 'list' && is_array($response->json('data'))
            ? ['ok', 'Authentification OpenAI réussie. Aucune génération ni consommation de tokens ; le quota de génération n’est pas testé.']
            : ['error', 'Réponse OpenAI inexploitable.'];
    }

    private function globalPlus(): array
    {
        $base = rtrim((string) config('services.global_plus.api_url'), '/');
        $key = config('services.global_plus.api_key');
        if (blank($key) || $base === '') {
            return $this->missing();
        }
        if (! in_array($base, ['https://cee-api.test.globalplus.fr', 'https://cee-api.globalplus.fr'], true)) {
            return ['warning', 'Destination Global+ non reconnue : test bloqué pour protéger la clé API.'];
        }
        $response = $this->http()->asJson()->post($base.'/api/Auth/token', ['apiKey' => $key])->throw();

        return is_string($response->json('token')) && filled($response->json('token'))
            ? ['ok', 'Authentification Global+ réussie ('.(str_contains($base, '.test.') ? 'TEST' : 'PRODUCTION').'). Aucun dossier envoyé ; les droits métier ne sont pas testés.']
            : ['error', 'Global+ n’a pas renvoyé de token exploitable.'];
    }

    private function capcee(): array
    {
        if (! config('crm.v2_enabled') || ! config('capcee.enabled')) {
            return ['warning', 'Connecteur CAPCEE désactivé : aucun appel effectué.'];
        }
        $base = match (config('capcee.environment')) {
            'uat2' => 'https://capcee--uat2.sandbox.my.salesforce.com',
            'production' => 'https://capcee.my.salesforce.com',
            default => null,
        };
        if ($base === null || blank(config('capcee.client_id')) || blank(config('capcee.client_secret'))) {
            return $this->missing();
        }
        $response = $this->http()->asForm()->post($base.'/services/oauth2/token', [
            'grant_type' => 'client_credentials', 'client_id' => config('capcee.client_id'),
            'client_secret' => config('capcee.client_secret'),
        ])->throw();

        return is_string($response->json('access_token')) && filled($response->json('access_token'))
            ? ['ok', 'Authentification CAPCEE réussie. Aucun dossier ni document envoyé ; les droits métier ne sont pas testés.']
            : ['error', 'CAPCEE n’a pas renvoyé de token exploitable.'];
    }

    private function smsfactor(): array
    {
        if (! config('services.smsfactor.enabled')) {
            return ['warning', 'SMSFactor désactivé : aucun appel effectué.'];
        }
        $token = config('services.smsfactor.token');
        if (blank($token)) {
            return $this->missing();
        }
        $response = $this->http()->withToken($token)->get('https://api.smsfactor.com/credits')->throw();

        return (int) $response->json('status') === 1 && is_numeric($response->json('credits'))
            ? ['ok', 'Authentification et lecture des crédits réussies. Aucun SMS envoyé ni crédit débité ; le mode d’envoi est inchangé.']
            : ['error', 'SMSFactor a refusé la lecture des crédits.'];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)
            ->withOptions(['verify' => true]);
    }

    private function missing(): array
    {
        return ['warning', 'Configuration incomplète ou non renseignée. Aucun appel effectué.'];
    }
}
