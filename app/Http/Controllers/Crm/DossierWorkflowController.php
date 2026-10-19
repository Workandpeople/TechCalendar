<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\PostVisitRequest;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Services\Crm\ContentAudience;
use App\Services\Crm\DossierWorkflow;
use App\Services\Crm\PostVisitWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DossierWorkflowController extends Controller
{
    public function postVisit(PostVisitRequest $request, Dossier $dossier, DossierOperation $operation, PostVisitWorkflow $workflow): RedirectResponse
    {
        $workflow->apply($request->user(), $dossier, $operation, [...$request->validated(), ...ContentAudience::fromRequest($request)]);

        return redirect(route('v2.dossiers.show', $dossier).'#fiche-'.$operation->id)->with('status', 'Traitement enregistré. Aucun envoi externe automatique.');
    }

    public function reopen(Request $request, Dossier $dossier, DossierOperation $operation, DossierWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'comment' => ['required', 'string', 'max:2000']]);
        $workflow->reopen($request->user(), $dossier, $operation, $data['version'], $data['comment']);

        return redirect(route('v2.dossiers.show', $dossier).'#prestations')->with('status', 'Fiche remise en prise de RDV. Tu peux maintenant la planifier.');
    }
}
