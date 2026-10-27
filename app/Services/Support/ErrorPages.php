<?php

namespace App\Services\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ErrorPages
{
    public function render(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();
        if ($status < 400 || $request->expectsJson() || config('app.debug')
            || ! str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }
        try {
            $context = app(ReportContext::class)->error($request, $exception, $status);
            [$title, $description] = match ($status) {
                401 => ['Connectez-vous pour continuer', 'Votre connexion est nécessaire pour accéder à cet espace.'],
                403 => ['Cet accès n’est pas autorisé', 'Votre compte ne dispose pas des droits nécessaires pour cette page.'],
                404 => ['Cette page est introuvable', 'Le lien est peut-être ancien, ou cette page n’existe plus.'],
                405 => ['Cette action n’est pas disponible', 'Revenez dans votre espace pour reprendre votre navigation.'],
                413 => ['Le fichier est trop volumineux', 'Pour un dossier, envoyez un seul document de 1 Go maximum. Pour un signalement Bêta : 3 captures de 4 Mo maximum chacune.'],
                419 => ['Votre session a expiré', 'Rechargez le formulaire depuis votre espace avant de réessayer.'],
                422 => ['Certaines informations sont à vérifier', 'Revenez au formulaire pour vérifier les champs renseignés.'],
                429 => ['Un petit instant…', 'Trop de demandes ont été reçues. Patientez avant de réessayer.'],
                503 => ['Votre espace est momentanément indisponible', 'Une intervention est en cours. Vous pourrez revenir dans quelques instants.'],
                default => $status >= 500
                    ? ['Une erreur a interrompu votre action', 'Nous n’avons pas pu terminer cette action. Vous pouvez nous transmettre le diagnostic ci-dessous.']
                    : ['Cette demande n’a pas pu aboutir', 'Revenez dans votre espace pour reprendre votre navigation.'],
            };
            $response->setContent(view('errors.application', [
                'status' => $status, 'title' => $title, 'description' => $description,
                'reference' => $context['id'], 'errorToken' => app(ReportContext::class)->seal($context),
            ])->render());
            $response->headers->remove('Content-Length');
            $response->headers->set('Cache-Control', 'no-store, private');
            if ($status >= 500) {
                Log::error('Support error reference.', [
                    'reference' => $context['id'], 'status' => $status, 'exception_class' => $context['exception_class'],
                ]);
            }
        } catch (Throwable) {
            // Keep Laravel's original response if the diagnostic itself cannot be rendered.
        }

        return $response;
    }
}
