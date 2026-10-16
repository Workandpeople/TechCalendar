<?php

namespace App\Services\Sms;

use App\Models\DossierSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class DossierSmsProcessor
{
    public function process(int $id): void
    {
        // Atomic claim: duplicate jobs (including queue:retry) cannot send twice.
        if (! DossierSms::whereKey($id)->where('status', 'queued')->update(['status' => 'sending', 'started_at' => now(), 'updated_at' => now(), 'last_error' => null])) {
            return;
        }
        $sms = DossierSms::with(['dossier', 'requester'])->findOrFail($id);
        if (! $sms->dossier || ! $sms->requester || ! Gate::forUser($sms->requester)->allows('sendSms', $sms->dossier)) {
            $this->finish($sms, ['status' => 'blocked', 'last_error' => 'Accès révoqué depuis la confirmation. Aucun appel effectué.']);

            return;
        }
        if ($reason = app(SmsConfiguration::class)->unavailable()) {
            $this->finish($sms, ['status' => 'blocked', 'last_error' => $reason]);

            return;
        }
        if ($sms->created_at->lt(now()->subHours(24))) {
            $this->finish($sms, ['status' => 'blocked', 'last_error' => 'Confirmation de plus de 24 heures : nouvel aperçu requis. Aucun appel effectué.']);

            return;
        }
        Log::info('SMS : traitement démarré.', ['sms_id' => $id, 'dossier_id' => $sms->dossier_id, 'mode' => $sms->mode]);
        try {
            $result = app(SmsFactorClient::class)->send($sms);
        } catch (Throwable $exception) {
            // Do not log getMessage(): HTTP exception messages may contain personal data.
            Log::warning('SMS : réponse indisponible.', ['sms_id' => $id, 'exception_class' => $exception::class]);
            $result = ['status' => 'unknown', 'last_error' => 'Réponse indisponible. Vérifier SMSFactor avant tout nouvel envoi ; aucune relance automatique.'];
        }
        $this->finish($sms, $result);
    }

    private function finish(DossierSms $sms, array $result): void
    {
        DB::transaction(function () use ($sms, $result): void {
            $sms->update([...$result, 'finished_at' => now()]);
            $sms->dossier?->activities()->create(['author_id' => $sms->requested_by, 'kind' => 'sms', 'client_visible' => false,
                'body' => 'SMS #'.$sms->id.' : '.$sms->statusLabel().'.', 'changed_fields' => ['sms_id' => $sms->id, 'status' => $sms->status]]);
        });
        Log::info('SMS : traitement terminé.', ['sms_id' => $sms->id, 'dossier_id' => $sms->dossier_id, 'mode' => $sms->mode,
            'status' => $sms->status, 'http_status' => $sms->http_status, 'provider_status' => $sms->provider_status]);
    }
}
