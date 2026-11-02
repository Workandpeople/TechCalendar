<?php

namespace App\Services\Mymar;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MymarAfterWorksService
{
    public function resolve(bool $lock = false): Service
    {
        $services = Service::query()->catalog()->where('type', Service::TYPE_MAR)->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->filter(fn (Service $service) => Str::squish(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($service->name))) ?? '') === 'visite apres travaux');
        if ($services->count() !== 1) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'Configurez une seule prestation MAR « Visite après travaux » active.']);
        }

        return $services->sole();
    }

    public function duration(Service $service): int
    {
        $duration = $service->average_duration_minutes;
        if ($duration < 60 || $duration > 480 || $duration % 60 !== 0) {
            throw ValidationException::withMessages(['mymar_dossier_id' => 'La durée MAR configurée dans Cofrac doit être compatible avec les heures entières de MYMAR.']);
        }

        return $duration;
    }

    public function assertQualified(User $technician): void
    {
        if (! $technician->services()->whereKey($this->resolve()->id)->exists()) {
            throw ValidationException::withMessages(['technician_id' => 'Ce technicien n’est plus habilité à la visite MAR après travaux.']);
        }
    }
}
