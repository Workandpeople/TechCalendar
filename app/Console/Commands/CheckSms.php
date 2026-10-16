<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsConfiguration;
use Illuminate\Console\Command;

class CheckSms extends Command
{
    protected $signature = 'sms:check';

    protected $description = 'Vérifie la configuration SMS sans réseau, sans envoi et sans afficher la clé';

    public function handle(SmsConfiguration $configuration): int
    {
        $this->line('Fournisseur : SMSFactor');
        $this->line('Clé : '.(filled(config('services.smsfactor.token')) ? 'présente (masquée)' : 'absente'));
        $this->line('Mode effectif : '.$configuration->mode());
        $this->line('Expéditeur : '.(config('services.smsfactor.sender') ?: 'par défaut chez SMSFactor'));
        $this->line('Queue : '.config('services.smsfactor.queue_connection').':'.config('services.smsfactor.queue'));
        $this->line('Aucun appel réseau effectué. Ne vérifie ni les crédits, ni la validité de la clé, ni le worker.');
        if ($reason = $configuration->unavailable()) {
            $this->error($reason);

            return self::FAILURE;
        }
        $this->info('Configuration prête. Utiliser l’aperçu de la fiche dossier puis confirmer pour lancer le traitement.');

        return self::SUCCESS;
    }
}
