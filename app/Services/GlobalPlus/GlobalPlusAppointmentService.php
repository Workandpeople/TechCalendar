<?php

namespace App\Services\GlobalPlus;

use App\Models\LotAppointment;
use App\Models\LotAppointmentDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GlobalPlusAppointmentService
{
    public const SOURCE = 'global_plus';

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DOCUMENTS_SYNCED = 'documents_synced';

    public const STATUS_DOCUMENTS_FAILED = 'documents_failed';

    private const ADDRESS_NAME_MAX_LENGTH = 50;

    private const ADDRESS_COMPANY_MAX_LENGTH = 255;

    private const ADDRESS_LINE_MAX_LENGTH = 200;

    private const POSTAL_CODE_MAX_LENGTH = 10;

    private const CITY_MAX_LENGTH = 50;

    private const SIREN_MAX_LENGTH = 20;

    private const TITLE_MAX_LENGTH = 50;

    private const SUB_TITLE_MAX_LENGTH = 25;

    public function __construct(private readonly GlobalPlusClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array<string, mixed>
     */
    public function referenceDataFor(LotAppointment $lotAppointment): array
    {
        $lotAppointment->loadMissing([
            'lot.service',
            'lot.coffracServiceAlias',
            'service',
            'appointment.technician',
            'appointment.service',
        ]);

        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'message' => 'API Global+ non configurée.',
                'installers' => [],
                'controllers' => [],
                'intervention_versions' => [],
                'suggested_installer_address_id' => null,
                'suggested_controller_id' => null,
                'suggested_version_formulaire_id' => null,
            ];
        }

        $installers = $this->client->installers();
        $controllers = $this->client->controllers();
        $versions = $this->client->activeFormVersions();

        return [
            'configured' => true,
            'installers' => $this->publicReferences($installers),
            'controllers' => $this->publicReferences($controllers),
            'intervention_versions' => $this->publicReferences($versions),
            'suggested_installer_address_id' => $this->suggestInstallerAddressId($lotAppointment, $installers),
            'suggested_controller_id' => $this->suggestControllerId($lotAppointment, $controllers),
            'suggested_version_formulaire_id' => $this->suggestVersionFormulaireId($lotAppointment, $versions),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDemandFromLotAppointment(LotAppointment $lotAppointment, array $payload, ?User $actor = null): LotAppointment
    {
        $this->assertCanCreateDemand($lotAppointment);

        $lotAppointment->loadMissing([
            'lot.service',
            'lot.coffracServiceAlias',
            'service',
            'appointment.technician',
            'appointment.service',
            'documents',
        ]);

        $demandPayload = $this->demandPayload($lotAppointment, $payload);

        try {
            $demandId = $this->client->createDemand($demandPayload);
        } catch (Throwable $exception) {
            $lotAppointment->update([
                'global_plus_status' => self::STATUS_FAILED,
                'global_plus_error_message' => $exception->getMessage(),
                'global_plus_payload' => [
                    'last_request' => $this->safeDemandPayload($demandPayload),
                    'failed_at' => now()->toIso8601String(),
                    'actor_id' => $actor?->id,
                ],
            ]);

            Log::warning('Création Global+ depuis un dossier de lot refusée.', [
                'lot_appointment_id' => $lotAppointment->id,
                'lot_id' => $lotAppointment->lot_id,
                'appointment_id' => $lotAppointment->appointment_id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $documentsWereSent = array_key_exists('files', $demandPayload);
        $now = now();

        $lotAppointment->update([
            'added_to_global_plus' => true,
            'global_plus_demand_id' => $demandId,
            'global_plus_status' => self::STATUS_CREATED,
            'global_plus_payload' => [
                'last_request' => $this->safeDemandPayload($demandPayload),
                'created_at' => $now->toIso8601String(),
                'actor_id' => $actor?->id,
            ],
            'global_plus_created_at' => $now,
            'global_plus_synced_at' => $now,
            'global_plus_error_message' => null,
        ]);

        if ($documentsWereSent) {
            $this->markDocumentsSynced($lotAppointment, $demandId, 'sent_in_creation');
        }

        return $lotAppointment->fresh([
            'lot.service',
            'lot.coffracServiceAlias',
            'service',
            'appointment.technician.departments',
            'appointment.service',
            'documents.uploader',
        ]);
    }

    public function syncDocuments(LotAppointment $lotAppointment): LotAppointment
    {
        $lotAppointment->loadMissing(['documents', 'lot']);
        $demandId = trim((string) $lotAppointment->global_plus_demand_id);

        if ($demandId === '') {
            throw new RuntimeException('Ce dossier n’a pas encore été créé dans Global+.');
        }

        $files = $this->documentsPayload($lotAppointment);

        return $this->withDemandFilesLock($demandId, function () use ($lotAppointment, $demandId, $files): LotAppointment {
            try {
                $response = $this->client->replaceDemandFiles($demandId, $files);
            } catch (Throwable $exception) {
                $lotAppointment->update([
                    'global_plus_status' => self::STATUS_DOCUMENTS_FAILED,
                    'global_plus_error_message' => $exception->getMessage(),
                ]);

                $lotAppointment->documents()->update([
                    'global_plus_error_message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $lotAppointment->update([
                'global_plus_status' => self::STATUS_DOCUMENTS_SYNCED,
                'global_plus_synced_at' => now(),
                'global_plus_error_message' => null,
                'global_plus_payload' => [
                    ...(is_array($lotAppointment->global_plus_payload) ? $lotAppointment->global_plus_payload : []),
                    'last_documents_sync' => [
                        'synced_at' => now()->toIso8601String(),
                        'files_count' => count($files),
                        'response' => $this->safeResponsePayload($response),
                    ],
                ],
            ]);

            $this->markDocumentsSynced($lotAppointment, $demandId, 'replace_files');

            return $lotAppointment->fresh(['documents.uploader']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function demandPayload(LotAppointment $lotAppointment, array $payload): array
    {
        $appointment = $lotAppointment->appointment;

        if (! $appointment) {
            throw new RuntimeException('Le dossier doit d’abord être placé physiquement dans TechCalendar.');
        }

        $versionFormulaire = $this->versionFormulaireDto((int) ($payload['version_formulaire_id'] ?? 0));
        $installer = $this->installerAddress($lotAppointment, $payload);
        $controllerId = $this->controllerId($payload);
        $title = $this->limit(trim((string) ($payload['title'] ?? '')) ?: $this->defaultTitle($lotAppointment), self::TITLE_MAX_LENGTH);
        $subTitle = $this->limit(trim((string) ($payload['sub_title'] ?? '')) ?: $this->defaultSubTitle($lotAppointment), self::SUB_TITLE_MAX_LENGTH);
        $sendDocuments = (bool) ($payload['send_documents'] ?? true);
        $files = $sendDocuments ? $this->documentsPayload($lotAppointment) : [];
        $demandPayload = [
            'idBureauInspection' => (int) config('services.global_plus.bureau_id'),
            'dateCreation' => now()->format('Y-m-d\TH:i:s'),
            'startDate' => $appointment->starts_at?->format('Y-m-d\TH:i:s'),
            'endDate' => $appointment->ends_at?->format('Y-m-d\TH:i:s'),
            'dateIntervention' => $appointment->starts_at?->format('Y-m-d\TH:i:s'),
            'idControleur' => $controllerId,
            'title' => $title,
            'subTitle' => $subTitle,
            'typeIntervention' => [
                $versionFormulaire,
            ],
            'client' => $this->clientAddress($lotAppointment),
            'lieuInspection' => $this->inspectionAddress($lotAppointment, $payload),
            'beneficiaire' => $this->beneficiaryAddress($lotAppointment),
            'entreprise' => $installer,
            'facturation' => false,
        ];

        if ($files !== []) {
            $demandPayload['files'] = $files;
        }

        return array_filter($demandPayload, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAddress(LotAppointment $lotAppointment): array
    {
        return $this->addressDto(1, $lotAppointment, [
            'raisonSociale' => $this->customerCompanyName($lotAppointment),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function inspectionAddress(LotAppointment $lotAppointment, array $payload): array
    {
        return $this->addressDto(2, $lotAppointment, [
            'raisonSociale' => $lotAppointment->site_name ?: $this->customerCompanyName($lotAppointment),
            'precariousness' => $payload['precariousness'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function beneficiaryAddress(LotAppointment $lotAppointment): array
    {
        return $this->addressDto(3, $lotAppointment, [
            'raisonSociale' => $this->customerCompanyName($lotAppointment),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function installerAddress(LotAppointment $lotAppointment, array $payload): array
    {
        $installerAddressId = (int) ($payload['installer_address_id'] ?? 0);

        if ($installerAddressId > 0) {
            $installer = collect($this->client->installers())
                ->first(fn (array $installer): bool => (int) $installer['address_id'] === $installerAddressId);

            if (! $installer) {
                throw new RuntimeException('L’installateur Global+ sélectionné est introuvable. Recharge la liste puis réessaie.');
            }

            if ((bool) ($installer['blocked'] ?? false)) {
                throw new RuntimeException('Cet installateur est bloqué côté Global+ et ne peut pas être utilisé.');
            }

            return array_filter([
                'id' => (int) $installer['address_id'],
                'idTypeAdresse' => 4,
                'civilite' => 'Mr',
                'raisonSociale' => $this->limit($installer['name'] ?? $installer['label'] ?? null, self::ADDRESS_COMPANY_MAX_LENGTH),
                'adresse' => $this->limit($installer['address'] ?? null, self::ADDRESS_LINE_MAX_LENGTH),
                'codePostal' => $this->limit($installer['postal_code'] ?? null, self::POSTAL_CODE_MAX_LENGTH),
                'ville' => $this->limit($installer['city'] ?? null, self::CITY_MAX_LENGTH),
                'phone' => $this->nullableString($installer['phone'] ?? null),
                'siren' => $this->limit($installer['siren'] ?? null, self::SIREN_MAX_LENGTH),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $name = trim((string) ($payload['installer_name'] ?? '')) ?: $lotAppointment->installer_name;

        if (! filled($name)) {
            throw new RuntimeException('Renseigne ou sélectionne l’installateur avant de créer le dossier Global+.');
        }

        return array_filter([
            'id' => 0,
            'idTypeAdresse' => 4,
            'civilite' => 'Mr',
            'raisonSociale' => $this->limit($name, self::ADDRESS_COMPANY_MAX_LENGTH),
            'adresse' => $this->limit($payload['installer_address'] ?? null, self::ADDRESS_LINE_MAX_LENGTH),
            'codePostal' => $this->limit($payload['installer_postal_code'] ?? null, self::POSTAL_CODE_MAX_LENGTH),
            'ville' => $this->limit($payload['installer_city'] ?? null, self::CITY_MAX_LENGTH),
            'phone' => $this->nullableString($payload['installer_phone'] ?? null),
            'siren' => $this->limit($payload['installer_siren'] ?? null, self::SIREN_MAX_LENGTH),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function versionFormulaireDto(int $versionFormulaireId): array
    {
        if ($versionFormulaireId <= 0) {
            throw new RuntimeException('Choisis une prestation Global+ avant de créer le dossier.');
        }

        $version = collect($this->client->activeFormVersions())
            ->first(fn (array $version): bool => (int) $version['version_formulaire_id'] === $versionFormulaireId);

        if (! $version) {
            throw new RuntimeException('La prestation Global+ sélectionnée est introuvable. Recharge la liste puis réessaie.');
        }

        $payload = is_array($version['payload'] ?? null) ? $version['payload'] : [];

        return collect([
            'versionFormulaireId',
            'codeRapport',
            'id',
            'idTypeIntervention',
            'idTypeInterventionGroupe',
            'libelleTypeInterventionGroupe',
            'numVersion',
            'libelle',
            'dateModification',
            'dateMiseEnService',
            'templateDoc',
            'templateSynthesisPDF',
            'templateSynthesisWORD',
            'actif',
            'hasStepsJSON',
            'enablePlanning',
            'versionPDF',
            'createdBy',
        ])
            ->mapWithKeys(fn (string $key): array => [$key => $payload[$key] ?? null])
            ->reject(fn (mixed $value): bool => $value === null)
            ->put('versionFormulaireId', $versionFormulaireId)
            ->all();
    }

    private function controllerId(array $payload): int
    {
        $controllerId = (int) ($payload['controller_id'] ?? 0);

        if ($controllerId <= 0) {
            throw new RuntimeException('Choisis le technicien Global+ avant de créer le dossier.');
        }

        $controller = collect($this->client->controllers())
            ->first(fn (array $controller): bool => (int) $controller['id'] === $controllerId);

        if (! $controller) {
            throw new RuntimeException('Le technicien Global+ sélectionné est introuvable. Recharge la liste puis réessaie.');
        }

        if (($controller['active'] ?? true) === false) {
            throw new RuntimeException('Le technicien Global+ sélectionné est inactif.');
        }

        return $controllerId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function addressDto(int $addressType, LotAppointment $lotAppointment, array $overrides = []): array
    {
        [$firstName, $lastName] = $this->splitCustomerName($lotAppointment);

        return array_filter([
            'id' => (int) ($overrides['id'] ?? 0),
            'idTypeAdresse' => $addressType,
            'civilite' => $this->limit($overrides['civilite'] ?? 'Mr', 4),
            'nom' => $this->limit($overrides['nom'] ?? $lastName, self::ADDRESS_NAME_MAX_LENGTH),
            'prenom' => $this->limit($overrides['prenom'] ?? $firstName, self::ADDRESS_NAME_MAX_LENGTH),
            'raisonSociale' => $this->limit($overrides['raisonSociale'] ?? $this->customerCompanyName($lotAppointment), self::ADDRESS_COMPANY_MAX_LENGTH),
            'adresse' => $this->limit($overrides['adresse'] ?? $lotAppointment->address, self::ADDRESS_LINE_MAX_LENGTH),
            'codePostal' => $this->limit($overrides['codePostal'] ?? $lotAppointment->postal_code, self::POSTAL_CODE_MAX_LENGTH),
            'ville' => $this->limit($overrides['ville'] ?? $lotAppointment->city, self::CITY_MAX_LENGTH),
            'phone' => $this->nullableString($overrides['phone'] ?? $lotAppointment->customer_phone),
            'precariousness' => $overrides['precariousness'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int, array{fileName:string,fileContent:string,category:string}>
     */
    private function documentsPayload(LotAppointment $lotAppointment): array
    {
        $lotAppointment->loadMissing('documents');

        return $lotAppointment->documents
            ->map(function (LotAppointmentDocument $document): ?array {
                $disk = Storage::disk($document->disk);

                if (! $disk->exists($document->path)) {
                    throw new RuntimeException(sprintf('Le fichier local du document "%s" est introuvable.', $document->name));
                }

                return [
                    'fileName' => $this->documentFileName($document),
                    'fileContent' => base64_encode($disk->get($document->path)),
                    'category' => $this->documentCategory($document),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function documentFileName(LotAppointmentDocument $document): string
    {
        $name = trim((string) ($document->name ?: $document->original_name ?: 'document'));
        $extension = pathinfo($document->original_name ?: $document->path, PATHINFO_EXTENSION);

        if ($extension !== '' && pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.'.$extension;
        }

        return Str::limit($name, 180, '');
    }

    private function documentCategory(LotAppointmentDocument $document): string
    {
        $mime = (string) $document->mime;

        if (str_starts_with($mime, 'image/')) {
            return 'Photos';
        }

        return 'Client';
    }

    private function markDocumentsSynced(LotAppointment $lotAppointment, string $demandId, string $mode): void
    {
        $lotAppointment->loadMissing('documents');

        $lotAppointment->documents->each(function (LotAppointmentDocument $document) use ($demandId, $mode): void {
            $document->update([
                'global_plus_pushed_at' => now(),
                'global_plus_remote_document' => [
                    'demand_id' => $demandId,
                    'mode' => $mode,
                    'file_name' => $this->documentFileName($document),
                    'category' => $this->documentCategory($document),
                ],
                'global_plus_error_message' => null,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function safeDemandPayload(array $payload): array
    {
        if (! isset($payload['files']) || ! is_array($payload['files'])) {
            return $payload;
        }

        $payload['files'] = collect($payload['files'])
            ->filter(fn (mixed $file): bool => is_array($file))
            ->map(fn (array $file): array => [
                'fileName' => $file['fileName'] ?? null,
                'category' => $file['category'] ?? null,
                'content_size' => strlen((string) ($file['fileContent'] ?? '')),
            ])
            ->values()
            ->all();

        return $payload;
    }

    private function safeResponsePayload(mixed $payload): mixed
    {
        if (is_scalar($payload) || $payload === null) {
            return $payload;
        }

        if (is_array($payload)) {
            return collect($payload)->take(50)->all();
        }

        return (string) $payload;
    }

    private function assertCanCreateDemand(LotAppointment $lotAppointment): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Global+ non configurée.');
        }

        $lotAppointment->loadMissing('appointment');

        if (filled($lotAppointment->global_plus_demand_id)) {
            throw new RuntimeException('Ce dossier existe déjà dans Global+.');
        }

        if (! $lotAppointment->appointment_id || ! $lotAppointment->appointment) {
            throw new RuntimeException('Le dossier doit être placé physiquement dans TechCalendar avant création Global+.');
        }

        if ($lotAppointment->processing_mode !== LotAppointment::PROCESSING_MODE_PHYSICAL) {
            throw new RuntimeException('Seuls les dossiers traités en RDV physique peuvent être créés dans Global+.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $installers
     */
    private function suggestInstallerAddressId(LotAppointment $lotAppointment, array $installers): ?int
    {
        $installerName = $this->normalizeMatchValue($lotAppointment->installer_name);

        if ($installerName === '') {
            return null;
        }

        $best = collect($installers)
            ->map(function (array $installer) use ($installerName): array {
                $candidate = $this->normalizeMatchValue($installer['name'] ?? $installer['label'] ?? '');
                $score = 0;

                if ($candidate === $installerName) {
                    $score += 100;
                }

                if ($candidate !== '' && (str_contains($candidate, $installerName) || str_contains($installerName, $candidate))) {
                    $score += 70;
                }

                similar_text($installerName, $candidate, $similarity);
                $score += (int) round($similarity / 2);

                return ['score' => $score, 'installer' => $installer];
            })
            ->sortByDesc('score')
            ->first();

        return ($best['score'] ?? 0) >= 55 ? (int) $best['installer']['address_id'] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $controllers
     */
    private function suggestControllerId(LotAppointment $lotAppointment, array $controllers): ?int
    {
        $email = Str::lower(trim((string) $lotAppointment->appointment?->technician?->email));

        if ($email === '') {
            return null;
        }

        $controller = collect($controllers)->first(fn (array $controller): bool => ($controller['email'] ?? null) === $email
            && ($controller['active'] ?? true) !== false);

        return $controller ? (int) $controller['id'] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $versions
     */
    private function suggestVersionFormulaireId(LotAppointment $lotAppointment, array $versions): ?int
    {
        $candidates = collect([
            $lotAppointment->service_name,
            $lotAppointment->service?->name,
            $lotAppointment->service?->type,
            $lotAppointment->appointment?->service?->name,
            $lotAppointment->appointment?->service?->type,
            $lotAppointment->lot?->service?->name,
            $lotAppointment->lot?->service?->type,
            $lotAppointment->lot?->coffracServiceAlias?->external_name,
            data_get($lotAppointment->raw_payload, 'coffrac_service_alias_name'),
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = collect($versions)
            ->map(function (array $version) use ($candidates): array {
                $score = $candidates
                    ->map(fn (string $candidate): int => $this->versionMatchScore($candidate, $version))
                    ->max() ?? 0;

                return ['score' => $score, 'version' => $version];
            })
            ->sortByDesc('score')
            ->first();

        return ($best['score'] ?? 0) >= 45 ? (int) $best['version']['version_formulaire_id'] : null;
    }

    /**
     * @param  array<string, mixed>  $version
     */
    private function versionMatchScore(string $candidate, array $version): int
    {
        $candidateTokens = $this->matchTokens($candidate);
        $versionTokens = $this->matchTokens(($version['label'] ?? '').' '.($version['code'] ?? ''));

        if ($candidateTokens === [] || $versionTokens === []) {
            return 0;
        }

        $candidateCompact = implode('', $candidateTokens);
        $versionCompact = implode('', $versionTokens);
        $score = 0;

        if ($candidateCompact === $versionCompact) {
            $score += 120;
        }

        if (str_contains($versionCompact, $candidateCompact) || str_contains($candidateCompact, $versionCompact)) {
            $score += 70;
        }

        $shared = array_intersect($candidateTokens, $versionTokens);
        $score += count($shared) * 18;

        similar_text($candidateCompact, $versionCompact, $similarity);
        $score += (int) round($similarity / 4);

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function matchTokens(string $value): array
    {
        $value = Str::upper(Str::ascii($value));
        $value = preg_replace('/([A-Z]+)([0-9]+)/', '$1 $2', (string) $value);
        $value = preg_replace('/([0-9]+)([A-Z]+)/', '$1 $2', (string) $value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', (string) $value);

        return collect(explode(' ', trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeMatchValue(?string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $value))));
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitCustomerName(LotAppointment $lotAppointment): array
    {
        $firstName = trim((string) $lotAppointment->customer_first_name);
        $lastName = trim((string) $lotAppointment->customer_last_name);

        if ($firstName !== '' || $lastName !== '') {
            return [$firstName ?: null, $lastName ?: null];
        }

        $name = trim((string) $lotAppointment->customer_name);

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) <= 1) {
            return [null, $this->limit($name, self::ADDRESS_NAME_MAX_LENGTH)];
        }

        return [
            $this->limit(array_shift($parts), self::ADDRESS_NAME_MAX_LENGTH),
            $this->limit(implode(' ', $parts), self::ADDRESS_NAME_MAX_LENGTH),
        ];
    }

    private function customerCompanyName(LotAppointment $lotAppointment): ?string
    {
        return $this->nullableString($lotAppointment->company_name)
            ?: $this->nullableString($lotAppointment->customer_name)
            ?: $this->nullableString($lotAppointment->site_name);
    }

    private function defaultTitle(LotAppointment $lotAppointment): string
    {
        return Str::limit('Lot '.$lotAppointment->lot?->name, self::TITLE_MAX_LENGTH, '');
    }

    private function defaultSubTitle(LotAppointment $lotAppointment): string
    {
        return Str::limit(trim(implode(' - ', array_filter([
            $lotAppointment->lot?->name,
            $lotAppointment->row_number ? 'Ligne '.$lotAppointment->row_number : null,
            $this->customerCompanyName($lotAppointment),
            $lotAppointment->site_name,
        ]))), self::SUB_TITLE_MAX_LENGTH, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function publicReferences(array $references): array
    {
        return collect($references)
            ->map(function (array $reference): array {
                unset($reference['payload']);

                return $reference;
            })
            ->values()
            ->all();
    }

    private function limit(mixed $value, int $limit): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : Str::limit($string, $limit, '');
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withDemandFilesLock(string $demandId, callable $callback): mixed
    {
        try {
            $lock = Cache::lock('global_plus:demand_files:'.$demandId, 300);

            if ($lock->get()) {
                try {
                    return $callback();
                } finally {
                    $lock->release();
                }
            }
        } catch (Throwable) {
            return $callback();
        }

        throw new RuntimeException('Une synchronisation des documents Global+ est déjà en cours pour ce dossier.');
    }
}
