<?php

namespace App\Console\Commands;

use App\Services\Simulation\PortalFixtures;
use Illuminate\Console\Command;
use Throwable;

class SeedPortalDemo extends Command
{
    protected $signature = 'demo:seed-portals
        {--apply : Creer les deux comptes et leurs donnees fictives sans modifier l\'existant}
        {--service= : Identifiant d\'une prestation COFRAC active (premiere du catalogue par defaut)}';

    protected $description = 'Prepare deux espaces externes fictifs, uniquement dans une base de simulation V2.';

    public function handle(PortalFixtures $fixtures): int
    {
        $serviceId = $this->option('service');
        if ($serviceId !== null && filter_var($serviceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('--service doit etre un identifiant positif.');

            return self::FAILURE;
        }
        try {
            $plan = $fixtures->inspect($serviceId === null ? null : (int) $serviceId);
            if (! $plan['existing']) {
                $this->line('Prestation : #'.$plan['service_id'].' '.$plan['service_name']);
                $this->line('A creer : 2 organisations, 2 comptes, 6 dossiers, 2 lots, 18 PDF et 18 commentaires fictifs.');
                if ($this->option('apply')) {
                    if (! $this->input->isInteractive()) {
                        $this->error('Utiliser un terminal interactif pour saisir les mots de passe masques.');

                        return self::FAILURE;
                    }
                    $passwords = [];
                    foreach (PortalFixtures::ACCOUNTS as $kind => $email) {
                        $passwords[$kind] = $this->secret('Mot de passe pour '.$email, false);
                        if ($passwords[$kind] !== $this->secret('Confirmer le mot de passe '.$kind, false)) {
                            $this->error('Les mots de passe ne correspondent pas. Rien n\'a ete cree.');

                            return self::FAILURE;
                        }
                    }
                    $plan = $fixtures->create($passwords, $plan['service_id']);
                    unset($passwords);
                }
            }
            $this->table(['Type', 'Identifiant', 'Acces'], array_map(fn ($account) => [
                $account['kind'], $account['email'], isset($account['active']) ? ($account['active'] ? 'Actif' : 'Inactif / supprime') : 'A creer',
            ], $plan['accounts']));
            $this->info(($plan['created'] ?? false) ? 'Jeu fictif cree. Aucun compte existant modifie, aucun message envoye.'
                : ($plan['existing'] ? 'Jeu deja cree : essais, droits et mots de passe conserves.' : 'Apercu uniquement. Ajouter --apply pour creer le jeu fictif.'));
            $this->line('Droits habituels : le delegataire ne lit pas les commentaires par defaut. Reglables dans la gestion des comptes.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // SQL/storage exceptions can contain credentials or row contents.
            $this->error(get_class($exception) === \RuntimeException::class ? $exception->getMessage()
                : 'Creation interrompue. Verifier la base, le stockage prive et l\'absence d\'une execution concurrente.');

            return self::FAILURE;
        }
    }
}
