<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierMailRequest;
use App\Models\Dossier;
use App\Services\Crm\DossierMail;
use App\Services\Crm\DossierMailDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class DossierMailController extends Controller
{
    public function status(Dossier $dossier, DossierMailDelivery $delivery): JsonResponse
    {
        Gate::authorize('update', $dossier);

        return response()->json(['messages' => $delivery->recent($dossier)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function preview(DossierMailRequest $request, Dossier $dossier, DossierMail $mail): JsonResponse
    {
        return response()->json($mail->preview($request->user(), $dossier, $request->validated()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(DossierMailRequest $request, Dossier $dossier, DossierMail $mail): RedirectResponse
    {
        return back()->with('status', $mail->send($request->user(), $dossier, $request->validated()));
    }
}
