<?php

namespace App\Console\Commands;

use App\Services\Crm\CofracLabelCorrection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CorrectCofracLabels extends Command
{
    protected $signature = 'cofrac:correct-labels {--apply : Corriger les textes avec sauvegarde privée} {--restore= : Identifiant de sauvegarde à restaurer} {--confirm-production : Confirmer la base V2 de production}';

    protected $description = 'Corrige le nom Cofrac dans les textes visibles, sans modifier les identifiants ou archives.';

    public function handle(CofracLabelCorrection $correction): int
    {
        if (! config('crm.v2_enabled') || (app()->environment('production') &&
            (! $this->option('confirm-production') || DB::connection()->getDatabaseName() !== 'cofrac2production'))) {
            $this->error('Commande réservée à la V2 ; confirmation explicite de production requise.');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('restore')) {
            $this->error('Choisir application ou restauration.');

            return self::FAILURE;
        }
        $root = storage_path('app/private/cofrac-label-corrections');
        if (is_link($root) || (! is_dir($root) && ! mkdir($root, 0700, true)) || ! chmod($root, 0700)) {
            $this->error('Dossier privé indisponible.');

            return self::FAILURE;
        }
        $lock = fopen($root.'/operation.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Une autre correction est en cours.');

            return self::FAILURE;
        }
        try {
            if ($restore = $this->option('restore')) {
                if (! Str::isUuid($restore) || is_link($root.'/'.$restore.'.jsonl')) {
                    $this->error('Identifiant de sauvegarde invalide.');

                    return self::FAILURE;
                }
                $counts = $correction->restore($root.'/'.$restore.'.jsonl');
            } else {
                $id = (string) Str::uuid();
                if ($this->option('apply')) {
                    $this->info('Sauvegarde privée : '.$id);
                }
                $counts = $correction->run($this->option('apply') ? $root.'/'.$id.'.jsonl' : null);
            }
            $this->table(['Table / contrôle', 'Lignes'], collect($counts)->map(fn ($n, $key) => [$key, $n])->values()->all());
            $this->info($this->option('apply') ? 'Textes corrigés. Aucun envoi, droit, date métier ou fichier modifié.' : ($restore ? 'Restauration terminée.' : 'Audit uniquement. Ajouter --apply pour appliquer.'));

            return ($counts['conflicts'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof \DomainException ? $exception->getMessage()
                : 'Correction interrompue ('.class_basename($exception).'). Aucun détail personnel affiché. Conserver la sauvegarde privée indiquée.');

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
