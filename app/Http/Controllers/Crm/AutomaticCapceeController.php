<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CapceeAutomaticSubmission;
use App\Models\Dossier;
use App\Services\Capcee\AutomaticCapceeReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AutomaticCapceeController extends Controller
{
    public function update(Request $request, Dossier $dossier, CapceeAutomaticSubmission $submission, AutomaticCapceeReports $reports): RedirectResponse
    {
        Gate::authorize('transmitReport', $dossier);
        abort_unless($submission->document?->dossier_id === $dossier->id, 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], ...AutomaticCapceeReports::targetRules()]);
        $reports->updateTarget($request->user(), $dossier, $submission, $data);

        return redirect(route('v2.dossiers.show', $dossier).'#capcee')->with('status', 'Identifiants du rapport enregistrés. L’envoi automatique reste soumis à l’activation et aux contrôles CAPCEE.');
    }
}
