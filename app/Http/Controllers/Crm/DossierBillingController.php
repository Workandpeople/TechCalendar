<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierBillingRequest;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Services\Crm\DossierBillingService;
use Illuminate\Http\RedirectResponse;

class DossierBillingController extends Controller
{
    public function store(DossierBillingRequest $request, Dossier $dossier, DossierBillingService $billing): RedirectResponse
    {
        $billing->mark($request->user(), $dossier, null, $request->validated());

        return redirect()->to(route('v2.dossiers.show', $dossier).'#facturation')
            ->with('status', 'Dossier marqué facturé. Le statut métier reste inchangé. Aucune facture ni aucun envoi externe généré.');
    }

    public function __invoke(DossierBillingRequest $request, Dossier $dossier, DossierOperation $operation, DossierBillingService $billing): RedirectResponse
    {
        $billing->mark($request->user(), $dossier, $operation, $request->validated());

        return redirect()->to(route('v2.dossiers.show', $dossier).'#facturation')
            ->with('status', 'Dossier marqué facturé. Le statut métier reste inchangé. Aucune facture ni aucun envoi externe généré.');
    }
}
