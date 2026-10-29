<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Services\Crm\DossierArchiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DossierArchivingController extends Controller
{
    public function destroy(Request $request, Dossier $dossier, DossierArchiving $archiving): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $dossier);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $archiving->delete($request->user(), $dossier, (int) $data['version'], $data['reason'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['count' => 1, 'message' => 'Dossier supprimé. Données et facturation conservées.'])->header('Cache-Control', 'private, no-store');
        }

        return to_route('v2.dossiers.index', ['status' => 'unknown'])->with('status', 'Dossier supprimé. Ses données et son marquage de facturation sont conservés.');
    }

    public function batch(Request $request, DossierArchiving $archiving): JsonResponse
    {
        Gate::authorize('manageArchiving', Dossier::class);
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'action' => ['required', Rule::in(['cancel', 'delete'])],
            'dossiers' => ['required', 'array', 'list', 'min:1', 'max:'.DossierArchiving::MAX_BATCH_SIZE],
            'dossiers.*' => ['required', 'array:id,version'],
            'dossiers.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'dossiers.*.version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $count = $archiving->batch($request->user(), $data['dossiers'], $data['action'], $data['reason'] ?? null);

        return response()->json(['count' => $count,
            'message' => $count.' dossier(s) '.($data['action'] === 'delete' ? 'supprimé(s)' : 'traité(s) : prestations en problème RDV annulées').'. Données et facturation conservées.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, Dossier $dossier, DossierArchiving $archiving): RedirectResponse
    {
        Gate::authorize('restore', $dossier);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'service_id' => ['nullable', 'integer', 'exists:services,id']]);
        $archiving->restore($request->user(), $dossier, (int) $data['version'], isset($data['service_id']) ? (int) $data['service_id'] : null);

        return to_route('v2.dossiers.show', $dossier)->with('status', 'Dossier restauré. Facturation et historique conservés.');
    }
}
