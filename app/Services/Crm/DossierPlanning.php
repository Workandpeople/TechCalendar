<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierPlanning
{
    public function search(User $actor, Dossier $dossier, DossierOperation $operation, array $filters): LengthAwarePaginator
    {
        $this->authorizeOperation($actor, $dossier, $operation);
        $text = trim($filters['q'] ?? '');
        $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';

        return Appointment::query()->with(['technician' => fn ($query) => $query->withTrashed(), 'lotAppointments.lot'])
            ->where('service_id', $operation->service_id)
            ->where(fn ($query) => $query->whereNull('dossier_operation_id')->orWhere('dossier_operation_id', $operation->id))
            ->when($text === '' && empty($filters['date']), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($text !== '', function ($query) use ($text, $search): void {
                $query->where(function ($query) use ($text, $search): void {
                    foreach (['customer_first_name', 'customer_last_name', 'address', 'external_reference'] as $column) {
                        $query->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$search]);
                    }
                    if (ctype_digit($text) && strlen($text) < 19) {
                        $query->orWhere('id', (int) $text);
                    }
                    $query->orWhereHas('technician', fn ($tech) => $tech->withTrashed()->whereRaw("email LIKE ? ESCAPE '!'", [$search]));
                });
            })
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->where('starts_at', '>=', $date.' 00:00:00')->where('starts_at', '<=', $date.' 23:59:59'))
            ->orderByDesc('starts_at')->orderByDesc('id')->paginate(20)->withQueryString();
    }

    public function link(User $actor, Dossier $dossier, DossierOperation $operation, int $appointmentId, int $version): void
    {
        $this->authorizeOperation($actor, $dossier, $operation);
        DB::transaction(function () use ($actor, $dossier, $operation, $appointmentId, $version): void {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $locked->operations()->lockForUpdate()->findOrFail($operation->id);
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->dossier_operation_id === $operation->id) {
                return;
            }
            $this->checkVersion($locked, $version);
            PostVisitWorkflow::assertPlanningMutable($operation);
            if ($appointment->dossier_operation_id !== null) {
                throw ValidationException::withMessages(['appointment_id' => 'Ce RDV est déjà rattaché à une autre prestation. Aucun dossier n’a été fusionné.']);
            }
            if ($appointment->service_id !== $operation->service_id) {
                throw ValidationException::withMessages(['appointment_id' => 'La prestation du rendez-vous ne correspond pas à celle du dossier.']);
            }

            // A link must not trigger booking notifications, CRM calls or a date update.
            DB::table('appointments')->where('id', $appointment->id)->update(['dossier_operation_id' => $operation->id]);
            $this->record($locked, $actor, 'appointment_linked', 'RDV #'.$appointment->id.' rattaché à la prestation #'.$operation->id.'.');
        }, 3);
    }

    public function unlink(User $actor, Dossier $dossier, DossierOperation $operation, int $appointmentId, int $version): void
    {
        $this->authorizeOperation($actor, $dossier, $operation);
        DB::transaction(function () use ($actor, $dossier, $operation, $appointmentId, $version): void {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $locked->operations()->lockForUpdate()->findOrFail($operation->id);
            $appointment = $locked->appointments()->withTrashed()->where('dossier_operation_id', $operation->id)
                ->lockForUpdate()->findOrFail($appointmentId);
            $this->checkVersion($locked, $version);
            PostVisitWorkflow::assertPlanningMutable($operation);
            DB::table('appointments')->where('id', $appointment->id)->update(['dossier_operation_id' => null]);
            $this->record($locked, $actor, 'appointment_unlinked', 'Liaison du RDV #'.$appointment->id.' retirée de la prestation #'.$operation->id.'. Le rendez-vous est conservé.');
        }, 3);
    }

    private function authorizeOperation(User $actor, Dossier $dossier, DossierOperation $operation): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless($operation->dossier_id === $dossier->id, 404);
    }

    private function checkVersion(Dossier $dossier, int $version): void
    {
        if ($dossier->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de modifier ses rendez-vous.']);
        }
    }

    private function record(Dossier $dossier, User $actor, string $kind, string $body): void
    {
        $dossier->increment('version');
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => $kind, 'body' => $body]);
    }
}
