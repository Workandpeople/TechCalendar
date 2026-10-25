<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Services\Crm\DossierArchiving;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DossierArchivingController extends Controller
{
    public function destroy(Request $request, Dossier $dossier, DossierArchiving $archiving): RedirectResponse
    {
        Gate::authorize('delete', $dossier);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $archiving->delete($request->user(), $dossier, (int) $data['version'], $data['reason'] ?? null);

        return to_route('v2.dossiers.index', ['status' => 'unknown'])->with('status', 'Dossier supprimé. Ses données et son marquage de facturation sont conservés.');
    }

    public function restore(Request $request, Dossier $dossier, DossierArchiving $archiving): RedirectResponse
    {
        Gate::authorize('restore', $dossier);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'service_id' => ['nullable', 'integer', 'exists:services,id']]);
        $archiving->restore($request->user(), $dossier, (int) $data['version'], isset($data['service_id']) ? (int) $data['service_id'] : null);

        return to_route('v2.dossiers.show', $dossier)->with('status', 'Dossier restauré. Facturation et historique conservés.');
    }
}
