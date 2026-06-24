<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\ExternalServiceAlias;
use App\Models\Service;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ManagerServiceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canManageServices($request), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Service::TYPES)],
        ]);

        $query = Service::query()
            ->with(['externalAliases' => fn ($query) => $query
                ->where('source', CoffracAppointmentService::SOURCE)
                ->orderBy('external_name')]);

        if (! empty($validated['q'])) {
            $search = trim($validated['q']);
            $query->where('name', 'like', "%{$search}%");
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $services = $query
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        $technicians = User::query()
            ->with('departments:code')
            ->where('role', 2)
            ->where('admin', false)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department_code', 'role']);

        return view('manager.services.index', [
            'services' => $services,
            'types' => Service::TYPES,
            'technicians' => $technicians,
            'filters' => [
                'q' => $validated['q'] ?? '',
                'type' => $validated['type'] ?? '',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManageServices($request), 403);

        $payload = $this->validatePayload($request);
        $externalAliases = $this->validateExternalAliases($request);
        $technicianIds = $this->validateTechnicianIds($request);

        DB::transaction(function () use ($externalAliases, $payload, $technicianIds): void {
            $service = Service::query()->create($payload);
            $service->technicians()->sync($technicianIds);
            $this->syncExternalAliases($service, $externalAliases);
        });

        return redirect()->route('manager.services')->with('status', 'Prestation créée avec succès.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        abort_unless($this->canManageServices($request), 403);

        $payload = $this->validatePayload($request, $service->id);
        $externalAliases = $this->validateExternalAliases($request, $service->id);

        DB::transaction(function () use ($externalAliases, $payload, $service): void {
            $service->update($payload);
            $this->syncExternalAliases($service, $externalAliases);
        });

        return redirect()->route('manager.services')->with('status', 'Prestation mise à jour.');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        abort_unless($this->canManageServices($request), 403);

        $service->delete();

        return redirect()->route('manager.services')->with('status', 'Prestation supprimée.');
    }

    /**
     * @return array{type:string,name:string,average_duration_minutes:int}
     */
    private function validatePayload(Request $request, ?int $serviceId = null): array
    {
        $payload = $request->validate([
            'type' => ['required', Rule::in(Service::TYPES)],
            'name' => ['required', 'string', 'max:190'],
            'average_duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $request->validate([
            'name' => [
                Rule::unique('services', 'name')
                    ->where(fn ($query) => $query->where('type', $payload['type']))
                    ->ignore($serviceId),
            ],
        ]);

        return [
            'type' => $payload['type'],
            'name' => $payload['name'],
            'average_duration_minutes' => (int) $payload['average_duration_minutes'],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function validateTechnicianIds(Request $request): array
    {
        $payload = $request->validate([
            'technician_ids' => ['nullable', 'array'],
            'technician_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', 2)
                    ->where('admin', false)
                    ->whereNull('deleted_at')),
            ],
        ]);

        return collect($payload['technician_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{source:string,external_type:string,external_name:string,normalized_external_type:string,normalized_external_name:string}>
     */
    private function validateExternalAliases(Request $request, ?int $serviceId = null): array
    {
        $payload = $request->validate([
            'external_aliases' => ['nullable', 'string', 'max:20000'],
        ]);

        $aliases = $this->parseExternalAliases((string) ($payload['external_aliases'] ?? ''));
        $seen = [];

        foreach ($aliases as $alias) {
            $key = $alias['source'].'|'.$alias['normalized_external_type'].'|'.$alias['normalized_external_name'];

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'external_aliases' => 'Un même alias Coffrac est renseigné plusieurs fois.',
                ]);
            }

            $seen[$key] = true;

            $exists = ExternalServiceAlias::query()
                ->where('source', $alias['source'])
                ->where('normalized_external_type', $alias['normalized_external_type'])
                ->where('normalized_external_name', $alias['normalized_external_name'])
                ->when($serviceId, fn ($query) => $query->where('service_id', '!=', $serviceId))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'external_aliases' => "L'alias Coffrac \"{$alias['external_name']}\" est déjà lié à une autre prestation.",
                ]);
            }
        }

        return $aliases;
    }

    /**
     * @return array<int, array{source:string,external_type:string,external_name:string,normalized_external_type:string,normalized_external_name:string}>
     */
    private function parseExternalAliases(string $rawAliases): array
    {
        return collect(preg_split('/\R/u', $rawAliases) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->map(function (string $line): array {
                $parts = array_map('trim', explode('|', $line, 2));
                $externalType = count($parts) === 2 ? $parts[0] : Service::TYPE_COFFRAC;
                $externalName = count($parts) === 2 ? $parts[1] : $parts[0];

                return [
                    'source' => CoffracAppointmentService::SOURCE,
                    'external_type' => $externalType !== '' ? $externalType : Service::TYPE_COFFRAC,
                    'external_name' => $externalName,
                    'normalized_external_type' => ExternalServiceAlias::normalizeValue($externalType !== '' ? $externalType : Service::TYPE_COFFRAC),
                    'normalized_external_name' => ExternalServiceAlias::normalizeValue($externalName),
                ];
            })
            ->filter(fn (array $alias): bool => $alias['normalized_external_name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<int, array{source:string,external_type:string,external_name:string,normalized_external_type:string,normalized_external_name:string}> $aliases
     */
    private function syncExternalAliases(Service $service, array $aliases): void
    {
        $service->externalAliases()
            ->where('source', CoffracAppointmentService::SOURCE)
            ->delete();

        foreach ($aliases as $alias) {
            $service->externalAliases()->create($alias);
        }
    }

    private function canManageServices(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
