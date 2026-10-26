<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Services\Crm\DossierServiceAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DossierServiceController extends Controller
{
    public function __invoke(Request $request, Dossier $dossier, DossierOperation $operation, DossierServiceAssignment $assignment): JsonResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate(['service_id' => ['required', 'integer'], 'version' => ['required', 'integer', 'min:1']]);

        return response()->json($assignment->change($request->user(), $dossier, $operation,
            (int) $data['service_id'], (int) $data['version']))->header('Cache-Control', 'private, no-store');
    }
}
