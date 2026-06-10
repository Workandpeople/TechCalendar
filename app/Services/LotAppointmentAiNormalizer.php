<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LotAppointmentAiNormalizer
{
    /**
     * @param Collection<int, array{row_number:int,data:array<string, string|null>}> $rows
     * @return array{
     *     lot_name:string,
     *     appointments:array<int, array<string, mixed>>,
     *     rejected_rows:array<int, array{row_number:int,reason:string}>,
     *     summary:string
     * }
     */
    public function normalize(Collection $rows, ?string $requestedLotName = null, ?string $lotType = null): array
    {
        if ($rows->isEmpty()) {
            throw new RuntimeException('Le fichier ne contient aucune ligne exploitable.');
        }

        $chunkSize = max(1, min(100, (int) config('services.openai.import_chunk_size', 10)));

        if ($rows->count() <= $chunkSize) {
            return $this->normalizeChunk($rows, $requestedLotName, $lotType);
        }

        $payloads = $rows
            ->values()
            ->chunk($chunkSize)
            ->map(fn (Collection $chunk): array => $this->normalizeChunk($chunk->values(), $requestedLotName, $lotType));

        return [
            'lot_name' => filled($requestedLotName)
                ? (string) $requestedLotName
                : (string) ($payloads->first()['lot_name'] ?? 'Lot importe'),
            'appointments' => $payloads
                ->flatMap(fn (array $payload): array => $payload['appointments'] ?? [])
                ->values()
                ->all(),
            'rejected_rows' => $payloads
                ->flatMap(fn (array $payload): array => $payload['rejected_rows'] ?? [])
                ->values()
                ->all(),
            'summary' => $payloads
                ->pluck('summary')
                ->filter()
                ->implode(' '),
        ];
    }

    /**
     * @param Collection<int, array{row_number:int,data:array<string, string|null>}> $rows
     */
    private function normalizeChunk(Collection $rows, ?string $requestedLotName = null, ?string $lotType = null): array
    {
        $apiKey = config('services.openai.api_key');
        $model = config('services.openai.model', 'gpt-4o-mini');
        $timeout = max(10, (int) config('services.openai.timeout', 75));
        $connectTimeout = max(3, (int) config('services.openai.connect_timeout', 10));

        if (! filled($apiKey)) {
            throw new RuntimeException('OpenAI API key manquante. Configure OPENAI_API_KEY ou l override BDD.');
        }

        try {
            $response = Http::withToken((string) $apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'requested_lot_name' => $requestedLotName,
                                'lot_type' => $lotType,
                                'rows' => $rows->values()->all(),
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'lot_appointment_import',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                    'max_output_tokens' => 12000,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(sprintf(
                'OpenAI ne repond pas dans le delai imparti (%d s). Relance l import ou reduis la taille du fichier.',
                $timeout,
            ), previous: $exception);
        }

        if ($response->failed()) {
            $message = $response->json('error.message') ?: 'OpenAI a refuse ou echoue pendant la normalisation.';
            throw new RuntimeException((string) $message);
        }

        $json = $this->extractOutputText($response->json());
        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw new RuntimeException('OpenAI n a pas retourne un JSON lisible.');
        }

        return $payload;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Tu nettoies des lignes Excel/CSV issues d'un fichier d'import pour créer un lot de RDV à placer.
Tu dois retourner uniquement un JSON respectant le schema.
Ne devine pas les informations absentes: utilise null ou une chaine vide selon le schema.
Normalise les téléphones français au mieux, conserve les adresses complètes.
Si l'adresse est separee en plusieurs colonnes, conserve aussi adresse, code postal et ville dans address_line, postal_code et city.
Nettoie les adresses avant de les retourner: retire les references cadastrales, codes parcelle, suffixes techniques et morceaux non postaux.
Exemple: "1 LES PETITES GRANGES - 000 0E 0369 - 000 0E 0370 - 000 0Z 0172" doit devenir "1 LES PETITES GRANGES".
Ne garde dans address/address_line que l adresse postale utile au geocodage; mets les morceaux retires dans raw_address_parts ou warnings si utile.
Inferer le code département depuis le code postal français si l'adresse le contient.
Si une ligne est inutilisable, mets-la dans rejected_rows avec une raison courte.
confidence doit représenter la confiance globale de normalisation de la ligne entre 0 et 1.
PROMPT;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        $chunks = [];

        foreach ($response['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $chunks[] = $content['text'];
                }
            }
        }

        $text = trim(implode('', $chunks));

        if ($text === '') {
            throw new RuntimeException('OpenAI n a retourne aucun contenu exploitable.');
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['lot_name', 'appointments', 'rejected_rows', 'summary'],
            'properties' => [
                'lot_name' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'appointments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'row_number',
                            'external_reference',
                            'customer_name',
                            'customer_first_name',
                            'customer_last_name',
                            'customer_phone',
                            'address',
                            'address_line',
                            'postal_code',
                            'city',
                            'raw_address_parts',
                            'department_code',
                            'latitude',
                            'longitude',
                            'comment',
                            'confidence',
                            'warnings',
                        ],
                        'properties' => [
                            'row_number' => ['type' => 'integer'],
                            'external_reference' => $this->nullableString(),
                            'customer_name' => ['type' => 'string'],
                            'customer_first_name' => $this->nullableString(),
                            'customer_last_name' => $this->nullableString(),
                            'customer_phone' => $this->nullableString(),
                            'address' => $this->nullableString(),
                            'address_line' => $this->nullableString(),
                            'postal_code' => $this->nullableString(),
                            'city' => $this->nullableString(),
                            'raw_address_parts' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'department_code' => $this->nullableString(),
                            'latitude' => $this->nullableNumber(),
                            'longitude' => $this->nullableNumber(),
                            'comment' => $this->nullableString(),
                            'confidence' => ['type' => 'number'],
                            'warnings' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'rejected_rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['row_number', 'reason'],
                        'properties' => [
                            'row_number' => ['type' => 'integer'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableString(): array
    {
        return [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableNumber(): array
    {
        return [
            'anyOf' => [
                ['type' => 'number'],
                ['type' => 'null'],
            ],
        ];
    }
}
