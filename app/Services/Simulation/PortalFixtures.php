<?php

namespace App\Services\Simulation;

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierStatusCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class PortalFixtures
{
    public const SOURCE = 'demo_portal_v1';

    public const ACCOUNTS = [
        'client' => 'client@portail-test.example.test',
        'delegataire' => 'delegataire@portail-test.example.test',
    ];

    private const SCENARIOS = [
        'commun-prise-rdv' => ['pending', true, true],
        'commun-probleme-rdv' => ['problem', true, true],
        'commun-recontrole-client' => ['non_compliant', true, true],
        'commun-recontrole-delegataire' => ['non_compliant', true, true],
        'client-seul' => ['pending', true, false],
        'delegataire-seul' => ['pending', false, true],
    ];

    private const AUDIENCES = [
        'Interne uniquement' => [false, false],
        'Client uniquement' => [true, false],
        'Client et delegataire' => [true, true],
    ];

    public function __construct(private readonly SimulationSafety $safety) {}

    public function inspect(?int $serviceId = null): array
    {
        $this->safety->assertPortalFixtureTarget();
        $organisations = ExternalDelegataire::query()->where('source', self::SOURCE)->get()->keyBy('external_id');
        if ($organisations->isNotEmpty()) {
            if ($organisations->count() !== 2 || collect(array_keys(self::ACCOUNTS))->contains(
                fn ($kind) => ($organisations->get($kind)?->payload['complete'] ?? false) !== true
            )) {
                throw new RuntimeException('Jeu de test incomplet ou conflit de source : aucune donnee modifiee.');
            }

            return ['existing' => true, 'accounts' => collect(self::ACCOUNTS)->map(function ($email, $kind) use ($organisations): array {
                $user = User::withTrashed()->find($organisations[$kind]->payload['user_id'] ?? null);

                return ['kind' => $kind, 'email' => $user?->email ?? '(compte supprime)',
                    'active' => $user && app(ClientAccess::class)->active($user)];
            })->values()->all()];
        }
        if (User::withTrashed()->whereIn(DB::raw('LOWER(TRIM(email))'), array_values(self::ACCOUNTS))->exists()) {
            throw new RuntimeException('Une adresse de test est deja utilisee : aucun compte existant ne sera reutilise.');
        }
        $service = Service::query()->catalog()->where('type', Service::TYPE_COFFRAC)
            ->when($serviceId !== null, fn ($query) => $query->whereKey($serviceId))->orderBy('id')->first();
        if (! $service) {
            throw new RuntimeException('Aucune prestation COFRAC active disponible. Verifier le catalogue ou --service.');
        }

        return ['existing' => false, 'service_id' => $service->id, 'service_name' => $service->name,
            'accounts' => collect(self::ACCOUNTS)->map(fn ($email, $kind) => ['kind' => $kind, 'email' => $email])->values()->all()];
    }

    public static function validatePasswords(#[SensitiveParameter] array $passwords): void
    {
        foreach (array_keys(self::ACCOUNTS) as $kind) {
            $password = $passwords[$kind] ?? null;
            if (! is_string($password) || mb_strlen(trim($password)) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new RuntimeException('Choisir deux mots de passe de 8 caracteres minimum et 72 octets maximum.');
            }
        }
        if (hash_equals($passwords['client'], $passwords['delegataire'])) {
            throw new RuntimeException('Choisir un mot de passe different pour chaque compte de test.');
        }
    }

    public function create(#[SensitiveParameter] array $passwords, ?int $serviceId = null): array
    {
        $this->safety->assertPortalFixtureTarget();

        return Cache::store('file')->lock('demo-portal-fixtures-v1', 300)->block(5, function () use ($passwords, $serviceId): array {
            $plan = $this->inspect($serviceId);
            if ($plan['existing']) {
                return $plan;
            }
            self::validatePasswords($passwords);
            $pdf = file_get_contents(resource_path('fixtures/portal-test.pdf'));
            if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('Document de test absent ou invalide.');
            }
            $written = [];
            try {
                // No observers, jobs or notifications: only synthetic rows and private files.
                Model::withoutEvents(function () use ($plan, $passwords, $pdf, &$written): void {
                    DB::transaction(function () use ($plan, $passwords, $pdf, &$written): void {
                        $organisations = [];
                        foreach (self::ACCOUNTS as $kind => $email) {
                            $organisation = ExternalDelegataire::create([
                                'source' => self::SOURCE, 'external_id' => $kind, 'kind' => $kind,
                                'name' => '[TEST] '.ucfirst($kind).' fictif', 'company_name' => '[TEST] '.ucfirst($kind).' fictif',
                                'email' => $email, 'is_active' => true,
                            ]);
                            $user = User::forceCreate([
                                'first_name' => '[TEST]', 'last_name' => ucfirst($kind).' fictif', 'email' => $email,
                                'password' => $passwords[$kind], 'role' => User::ROLE_EXTERNAL, 'admin' => false,
                                'can_manage_billing' => false, 'external_client_id' => $organisation->id,
                                'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults($kind),
                                'must_change_password' => false, 'notification_mail_enabled' => false, 'notification_push_enabled' => false,
                            ]);
                            $organisation->update(['payload' => ['user_id' => $user->id, 'complete' => true]]);
                            $organisations[$kind] = $organisation;
                            $this->createLot($organisation, $plan['service_id']);
                        }
                        foreach (self::SCENARIOS as $key => [$state, $client, $delegate]) {
                            $dossier = Dossier::create([
                                'reference' => 'TEST-PORTAIL-'.strtoupper($key), 'source' => self::SOURCE, 'source_reference' => $key,
                                'client_id' => $client ? $organisations['client']->id : null,
                                'delegataire_id' => $delegate ? $organisations['delegataire']->id : null,
                                'client_contact' => $client ? ['company_name' => $organisations['client']->name] : null,
                                'delegataire_contact' => $delegate ? ['company_name' => $organisations['delegataire']->name] : null,
                                'company_name' => '[TEST] '.str_replace('-', ' ', $key),
                                'site_name' => '[TEST] Site fictif', 'installer_name' => '[TEST] Installateur fictif',
                                'address' => '[TEST] Adresse fictive - ne pas contacter', 'city' => '[TEST] Ville fictive',
                            ]);
                            $operation = $dossier->operations()->create([
                                'service_id' => $plan['service_id'], 'source' => self::SOURCE, 'source_reference' => $key,
                            ]);
                            $operation->forceFill(['legacy_status' => DossierStatusCatalog::STATUSES[$state]['values'][0]])->save();
                            foreach (self::AUDIENCES as $label => [$clientVisible, $delegateVisible]) {
                                $path = 'crm/dossiers/'.$dossier->id.'/'.Str::uuid().'.pdf';
                                $written[] = $path;
                                if (! Storage::disk('local')->put($path, $pdf, ['visibility' => 'private'])) {
                                    throw new RuntimeException('Ecriture du document fictif impossible. Creation annulee.');
                                }
                                $dossier->documents()->create([
                                    'name' => '[TEST] '.$label, 'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen($pdf),
                                    'document_type' => 'free', 'description' => 'PDF fictif pour tester les acces et l\'apercu. Aucune donnee reelle.',
                                    'client_visible' => $clientVisible, 'delegataire_visible' => $delegateVisible,
                                ]);
                                $dossier->activities()->create([
                                    'kind' => 'comment', 'body' => '[TEST] Commentaire : '.$label.'. Donnees fictives.',
                                    'client_visible' => $clientVisible, 'delegataire_visible' => $delegateVisible,
                                    'visibility_reviewed_at' => now(), 'changed_fields' => ['fixture' => self::SOURCE],
                                ]);
                            }
                        }
                    });
                });
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($written);
                throw $exception;
            }

            return [...$this->inspect(), 'created' => true];
        });
    }

    private function createLot(ExternalDelegataire $organisation, int $serviceId): void
    {
        $lot = Lot::create([
            'name' => '[TEST] Lot '.$organisation->kind, 'source' => self::SOURCE,
            'type' => Lot::TYPE_FULL_CONTACT_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS,
            'service_id' => $serviceId, 'delegataire_id' => $organisation->id, 'delegataire' => $organisation->name,
            'total_rows' => 3, 'imported_rows' => 3, 'rejected_rows' => 0,
            'import_summary' => ['fixture' => self::SOURCE], 'comment' => '[TEST] Lot fictif independant des dossiers de test.',
        ]);
        foreach ([null, true, false] as $index => $satisfied) {
            $lot->appointments()->create([
                'source' => self::SOURCE, 'row_number' => $index + 1, 'service_id' => $serviceId,
                'customer_name' => '[TEST] Beneficiaire '.($index + 1), 'installer_name' => '[TEST] Installateur fictif',
                'address' => '[TEST] Adresse fictive', 'city' => '[TEST] Ville fictive',
                'status' => $satisfied === null ? LotAppointment::STATUS_PENDING : LotAppointment::STATUS_CONTACT_PROCESSED,
                'processing_mode' => LotAppointment::PROCESSING_MODE_CONTACT, 'contact_satisfaction' => $satisfied,
                'contact_processed_at' => $satisfied === null ? null : now(),
                'contact_comment' => '[TEST] Aucun appel reel.',
            ]);
        }
    }
}
