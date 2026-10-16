<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DossierSms;
use App\Services\Sms\DossierSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DossierSmsController extends Controller
{
    public function preview(Request $request, Dossier $dossier, DossierSmsService $service): JsonResponse
    {
        Gate::authorize('sendSms', $dossier);
        $data = $request->validate(['recipient' => ['required', 'string', 'max:50'], 'body' => ['required', 'string', 'max:1500'], 'appointment_id' => ['nullable', 'integer']]);

        return response()->json($service->preview($request->user(), $dossier, $data))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Dossier $dossier, DossierSmsService $service): RedirectResponse
    {
        Gate::authorize('sendSms', $dossier);
        $data = $request->validate(['preview_token' => ['required', 'string', 'max:20000'], 'confirmed' => ['accepted']]);
        $service->queue($request->user(), $dossier, $data['preview_token']);

        return redirect()->to(route('v2.dossiers.show', $dossier).'#sms')->with('status', 'Demande SMS enregistrée. Consulte le suivi ci-dessous pour connaître le résultat.');
    }

    public function status(Dossier $dossier): JsonResponse
    {
        Gate::authorize('sendSms', $dossier);
        $messages = DossierSms::where('dossier_id', $dossier->id)->latest('id')->limit(10)->get();

        return response()->json(['html' => view('crm.dossiers.detail.sms-status', compact('messages', 'dossier'))->render(),
            'active' => $messages->contains(fn ($sms) => $sms->status === 'queued' || ($sms->status === 'sending' && $sms->started_at?->gt(now()->subMinutes(2))))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function resume(Request $request, Dossier $dossier, DossierSms $sms, DossierSmsService $service): RedirectResponse
    {
        $service->resume($request->user(), $dossier, $sms);

        return redirect()->to(route('v2.dossiers.show', $dossier).'#sms')->with('status', 'Mise en file demandée. Aucun envoi déjà transmis n’a été relancé.');
    }
}
