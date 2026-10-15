<?php

namespace App\Services;

use App\Models\DossierOperation;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ServiceDeletion
{
    public function delete(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);

            // Protect existing dossiers even if the V2 feature has been disabled again.
            if (Schema::hasTable('dossier_operations') && DossierOperation::query()->where('service_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'service' => 'Cette prestation est utilisée par un dossier V2 et ne peut pas être supprimée.',
                ]);
            }

            $locked->delete();
        });
    }
}
