<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        $query = Service::query();

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
            ->where('role', 2)
            ->where('admin', false)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department_code']);

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
        $technicianIds = $this->validateTechnicianIds($request);

        DB::transaction(function () use ($payload, $technicianIds): void {
            $service = Service::query()->create($payload);
            $service->technicians()->sync($technicianIds);
        });

        return redirect()->route('manager.services')->with('status', 'Prestation créée avec succès.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        abort_unless($this->canManageServices($request), 403);

        $payload = $this->validatePayload($request, $service->id);

        $service->update($payload);

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

    private function canManageServices(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
