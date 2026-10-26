<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierAddressRequest;
use App\Models\Dossier;
use App\Services\Crm\DossierAddressCorrection;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DossierAddressController extends Controller
{
    public function suggestions(Request $request, Dossier $dossier, MapboxAddressGeocoder $geocoder): JsonResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:255', 'not_regex:/[;\x00-\x1F\x7F]/']]);

        return response()->json(['suggestions' => $geocoder->suggestions($data['q'])])->header('Cache-Control', 'private, no-store');
    }

    public function update(DossierAddressRequest $request, Dossier $dossier, DossierAddressCorrection $correction): RedirectResponse
    {
        $correction->correct($request->user(), $dossier, $request->validated());

        return redirect()->to(route('v2.dossiers.show', $dossier).'#synthese')
            ->with('status', 'Adresse validée et position recalculée avec Mapbox.');
    }
}
