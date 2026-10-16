<?php

namespace App\Services\Crm;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ContentAudience
{
    public const LABELS = ['internal' => 'Interne uniquement', 'client' => 'Client uniquement', 'shared' => 'Client et délégataire'];

    public static function rules(bool $required = false): array
    {
        return [
            'audience' => [$required ? 'required_without:client_visible' : 'sometimes', Rule::in(array_keys(self::LABELS))],
            'client_visible' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromRequest(Request $request): array
    {
        // Compatibility for old open forms. New forms always send an explicit audience.
        if (! $request->has('audience')) {
            return array_fill_keys(['client_visible', 'delegataire_visible'], $request->boolean('client_visible'));
        }

        return self::flags($request->string('audience')->toString());
    }

    public static function flags(string $audience): array
    {
        return ['client_visible' => in_array($audience, ['client', 'shared'], true), 'delegataire_visible' => $audience === 'shared'];
    }

    public static function key(bool $client, bool $delegataire): string
    {
        return ! $client ? 'internal' : ($delegataire ? 'shared' : 'client');
    }
}
