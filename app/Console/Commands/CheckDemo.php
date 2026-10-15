<?php

namespace App\Console\Commands;

use App\Models\ApplicationSetting;
use App\Models\MailSender;
use App\Services\Simulation\SimulationSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckDemo extends Command
{
    protected $signature = 'demo:check';

    protected $description = 'Verifie la configuration de demonstration sans ecriture ni appel externe.';

    public function handle(SimulationSafety $safety): int
    {
        if (! app()->environment('demo')) {
            $this->error('Commande reservee a APP_ENV=demo. Aucune modification effectuee.');

            return self::FAILURE;
        }
        $checks = [
            'Isolation de la demo' => function () use ($safety): bool {
                $safety->assertDemoConfiguration();

                return true;
            },
            'PHP >= 8.4.1' => fn () => PHP_VERSION_ID >= 80401,
            'Migrations presentes' => function (): bool {
                $applied = DB::table('migrations')->pluck('migration')->all();
                $expected = array_map(fn ($path) => basename($path, '.php'), glob(database_path('migrations/*.php')));

                return array_diff($expected, $applied) === [];
            },
            'Donnees metier presentes' => fn () => DB::table('dossiers')->exists() && DB::table('users')->exists(),
            'Cle de chiffrement compatible' => function (): bool {
                foreach (ApplicationSetting::cursor() as $setting) {
                    $setting->getAttribute('value');
                }
                foreach (MailSender::withTrashed()->cursor() as $sender) {
                    $sender->getAttribute('mail_password');
                }

                return filled(config('app.key'));
            },
            'Pas de sessions ni de jobs importes' => function (): bool {
                foreach (['sessions', 'jobs', 'failed_jobs', 'job_batches', 'mobile_access_tokens', 'mobile_push_tokens', 'password_reset_tokens'] as $table) {
                    if (DB::table($table)->exists()) {
                        return false;
                    }
                }

                return true;
            },
            'Assets compiles, pas de serveur Vite local' => fn () => is_file(public_path('build/manifest.json')) && ! file_exists(public_path('hot')),
            'Repertoires inscriptibles par le processus PHP' => function (): bool {
                foreach (['logs', 'framework/cache', 'framework/views', 'framework/sessions', 'app/private', 'app/public'] as $path) {
                    if (! is_writable(storage_path($path))) {
                        return false;
                    }
                }

                return is_writable(base_path('bootstrap/cache'));
            },
            'Collecte privee accessible' => function (): bool {
                $root = realpath((string) config('crm.collected_documents_root'));
                $public = realpath(public_path());

                return $root && $public && is_dir($root) && is_readable($root)
                    && $root !== $public && ! str_starts_with($root, $public.DIRECTORY_SEPARATOR)
                    && is_readable((string) config('crm.collected_documents_manifest'));
            },
            'Cle Mapbox configuree' => fn () => filled(config('services.mapbox.token')),
            'Cle OpenAI configuree' => fn () => filled(config('services.openai.api_key')),
            'Cle Global+ configuree sur son environnement TEST' => fn () => filled(config('services.global_plus.api_key'))
                && rtrim((string) config('services.global_plus.api_url'), '/') === 'https://cee-api.test.globalplus.fr',
            'Reverb dedie et origine HTTPS' => fn () => config('broadcasting.default') === 'reverb'
                && (int) config('broadcasting.connections.reverb.options.port') === 8081
                && filled(config('broadcasting.connections.reverb.key'))
                && filled(config('broadcasting.connections.reverb.secret'))
                && config('reverb.client.host') === parse_url(config('app.url'), PHP_URL_HOST)
                && config('reverb.client.scheme') === 'https'
                && config('reverb.apps.apps.0.allowed_origins') === [parse_url(config('app.url'), PHP_URL_HOST)],
        ];
        $failed = 0;
        foreach ($checks as $label => $check) {
            try {
                $passed = (bool) $check();
            } catch (Throwable) {
                // SQL and decryption exceptions may expose private configuration.
                $passed = false;
            }
            $this->line(($passed ? 'OK   ' : 'FAIL ').$label);
            $failed += $passed ? 0 : 1;
        }
        $this->comment('Controle pre-ouverture uniquement. Ne teste ni le reseau, ni le worker, ni tous les fichiers.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
