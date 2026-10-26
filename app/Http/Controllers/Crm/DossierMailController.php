<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierMailRequest;
use App\Models\Dossier;
use App\Services\Crm\DossierMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class DossierMailController extends Controller
{
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
