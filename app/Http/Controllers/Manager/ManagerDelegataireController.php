<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\ExternalDelegataire;
use App\Services\CoffracAppointmentService;
use App\Services\CoffracDelegataireService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class ManagerDelegataireController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canManageDelegataires($request), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $query = ExternalDelegataire::query()
            ->where('source', CoffracAppointmentService::SOURCE);

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        if (($filters['status'] ?? '') === 'active') {
            $query->where('is_active', true);
        }

        if (($filters['status'] ?? '') === 'inactive') {
            $query->where('is_active', false);
        }

        $delegataires = $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('manager.delegataires.index', [
            'delegataires' => $delegataires,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'stats' => [
                'total' => ExternalDelegataire::query()->where('source', CoffracAppointmentService::SOURCE)->count(),
                'active' => ExternalDelegataire::query()->where('source', CoffracAppointmentService::SOURCE)->where('is_active', true)->count(),
                'inactive' => ExternalDelegataire::query()->where('source', CoffracAppointmentService::SOURCE)->where('is_active', false)->count(),
            ],
        ]);
    }

    public function sync(Request $request, CoffracDelegataireService $delegataires): RedirectResponse
    {
        abort_unless($this->canManageDelegataires($request), 403);

        try {
            $result = $delegataires->sync();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['coffrac' => $exception->getMessage()]);
        }

        return redirect()
            ->route('manager.delegataires')
            ->with('status', sprintf(
                'Délégataires Coffrac synchronisés : %d actif(s) reçu(s), %d désactivé(s), %d total en local.',
                $result['synced'],
                $result['disabled'],
                $result['total'],
            ));
    }

    private function canManageDelegataires(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
