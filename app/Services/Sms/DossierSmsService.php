<?php

namespace App\Services\Sms;

use App\Jobs\SendDossierSms;
use App\Models\Dossier;
use App\Models\DossierSms;
use App\Models\User;
use App\Services\Coffrac\LegacyTemplateText;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DossierSmsService
{
    public const VARIABLES = [
        '{client_name}' => 'Bénéficiaire', '{adresse}' => 'Adresse inspection', '{date_rdv}' => 'Date et plage du RDV',
        '{date_rappel}' => 'Date de rappel', '{societe}' => 'Client / délégataire', '{technicien}' => 'Technicien',
    ];

    public function templates(): array
    {
        // Reuse the archived models without importing or duplicating the Coffrac table.
        return DB::table('legacy_records')->where('source_table', 'sms_tempalte')->orderBy('source_id')->limit(100)
            ->get(['source_id', 'payload'])->map(function ($row): ?array {
                $data = json_decode($row->payload, true);
                $body = is_array($data) ? LegacyTemplateText::read($data, 'message') : null;
                $title = is_array($data) ? LegacyTemplateText::read($data, 'titre') : null;
                if ($body === null || trim($body) === '' || mb_strlen($body) > 1500
                    || ($title === null && (isset($data['titre']) || array_key_exists('titre_base64', $data)))
                    || ($title !== null && mb_strlen($title) > 190)) {
                    return null;
                }
                $body = SmsText::plain($body);
                if ($body === '') {
                    return null;
                }

                return ['id' => (int) $row->source_id, 'name' => filled($title) ? $title : 'Modèle #'.$row->source_id,
                    'body' => $body];
            })->filter()->values()->all();
    }

    public function preview(User $actor, Dossier $dossier, array $data): array
    {
        Gate::forUser($actor)->authorize('sendSms', $dossier);
        $config = app(SmsConfiguration::class);
        $config->assertReady();
        $appointment = filled($data['appointment_id'] ?? null)
            ? $dossier->appointments()->with(['technician', 'dossierOperation'])->findOrFail($data['appointment_id']) : null;
        $dossier->loadMissing('delegataire');
        $values = [
            '{client_name}' => $dossier->beneficiaryLabel(),
            '{adresse}' => trim(implode(', ', array_filter([$dossier->address, trim($dossier->postal_code.' '.$dossier->city)]))),
            '{societe}' => $dossier->delegataire?->company_name ?: $dossier->delegataire?->name,
            '{technicien}' => $appointment?->technician?->full_name,
            '{date_rdv}' => $appointment?->starts_at ? $appointment->starts_at->format('d/m/Y').' de '.$appointment->starts_at->format('H:i').' à '.$appointment->ends_at?->format('H:i') : null,
            '{date_rappel}' => null,
        ];
        if ($appointment) {
            $recall = $dossier->activities()->where('kind', 'status_changed')
                ->where('changed_fields->operation_id', $appointment->dossier_operation_id)->latest('id')->value('changed_fields');
            if (is_array($recall) && filled($recall['recall_date'] ?? null)) {
                $values['{date_rappel}'] = trim($recall['recall_date'].' '.($recall['recall_time'] ?? ''));
            }
        }
        $body = SmsText::plain($data['body']);
        preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $body, $tokens);
        foreach (array_unique($tokens[0]) as $token) {
            if (! array_key_exists($token, $values) || blank($values[$token])) {
                throw ValidationException::withMessages(['body' => 'Variable non renseignée : '.$token.'. Choisis le RDV concerné ou remplace-la manuellement.']);
            }
        }
        $body = strtr($body, array_map(fn ($value) => SmsText::plain((string) $value), $values));
        if ($body === '' || mb_strlen($body) > 1500) {
            throw ValidationException::withMessages(['body' => 'Le texte final doit contenir entre 1 et 1 500 caractères.']);
        }
        $preview = ['recipient' => SmsText::phone($data['recipient']), 'body' => $body,
            'sender' => (string) config('services.smsfactor.sender', ''), 'mode' => $config->mode(),
            'appointment_id' => $appointment?->id, 'dossier_id' => $dossier->id, 'requested_by' => $actor->id,
            'submission_token' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(20)->timestamp];

        return [...$preview, 'metrics' => SmsText::metrics($body), 'preview_token' => Crypt::encryptString(json_encode($preview, JSON_THROW_ON_ERROR))];
    }

    public function queue(User $actor, Dossier $dossier, string $token): DossierSms
    {
        Gate::forUser($actor)->authorize('sendSms', $dossier);
        $config = app(SmsConfiguration::class);
        $config->assertReady();
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['sms' => 'Aperçu invalide. Prépare un nouvel aperçu.']);
        }
        if (! is_array($data) || ($data['requested_by'] ?? null) !== $actor->id || ($data['dossier_id'] ?? null) !== $dossier->id
            || ($data['expires_at'] ?? 0) < now()->timestamp || ($data['mode'] ?? null) !== $config->mode()
            || ($data['sender'] ?? null) !== (string) config('services.smsfactor.sender', '')) {
            throw ValidationException::withMessages(['sms' => 'Aperçu expiré ou configuration modifiée. Prépare un nouvel aperçu.']);
        }
        $fingerprint = hash_hmac('sha256', json_encode([$data['recipient'], $data['body'], $data['sender'], $data['mode']]), config('app.key'));
        $sms = DB::transaction(function () use ($actor, $dossier, $data, $fingerprint): DossierSms {
            Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $existing = DossierSms::where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                abort_unless($existing->dossier_id === $dossier->id && $existing->requested_by === $actor->id, 403);

                return $existing;
            }
            if ($data['appointment_id'] && ! $dossier->appointments()->where('appointments.id', $data['appointment_id'])->exists()) {
                throw ValidationException::withMessages(['sms' => 'Ce RDV n’est plus disponible. Prépare un nouvel aperçu.']);
            }
            if (DossierSms::where('dossier_id', $dossier->id)->where('fingerprint', $fingerprint)
                ->where('created_at', '>=', now()->subMinutes(5))->exists()) {
                throw ValidationException::withMessages(['sms' => 'Ce même message a déjà été confirmé récemment. Consulte son suivi avant de recommencer.']);
            }
            $sms = DossierSms::create([...array_diff_key($data, ['expires_at' => true]), 'fingerprint' => $fingerprint, 'status' => 'queued']);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'sms', 'client_visible' => false,
                'body' => ($sms->mode === 'simulate' ? 'Simulation SMS' : 'SMS').' #'.$sms->id.' confirmé pour mise en file.', 'changed_fields' => ['sms_id' => $sms->id]]);

            return $sms;
        });
        if ($sms->status === 'queued') {
            $this->dispatch($sms);
        }

        return $sms;
    }

    public function dispatch(DossierSms $sms): void
    {
        try {
            SendDossierSms::dispatch($sms->id)->onConnection(config('services.smsfactor.queue_connection'))
                ->onQueue(config('services.smsfactor.queue'))->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('SMS : mise en file indisponible.', ['sms_id' => $sms->id, 'exception_class' => $exception::class]);
            $sms->update(['last_error' => 'Queue indisponible. Aucun appel SMSFactor effectué. Utiliser « Reprendre la mise en file ».']);
        }
    }

    public function resume(User $actor, Dossier $dossier, DossierSms $sms): void
    {
        Gate::forUser($actor)->authorize('sendSms', $dossier);
        abort_unless($sms->dossier_id === $dossier->id, 404);
        app(SmsConfiguration::class)->assertReady();
        if ($sms->status !== 'queued') {
            throw ValidationException::withMessages(['sms' => 'Seuls les messages jamais transmis peuvent être remis en file.']);
        }
        $this->dispatch($sms);
    }
}
