<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\CoffracAppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MobileAppointmentDocumentController extends Controller
{
    public function store(
        Request $request,
        Appointment $appointment,
        CoffracAppointmentService $coffracAppointments,
    ): JsonResponse {
        $technician = $request->user();

        abort_unless(
            $technician && (int) $appointment->technician_id === (int) $technician->id,
            403,
            'Ce rendez-vous ne fait pas partie de ton planning.'
        );

        if ($appointment->external_source !== CoffracAppointmentService::SOURCE || ! filled($appointment->external_reference)) {
            throw ValidationException::withMessages([
                'document' => 'Ce rendez-vous n’est pas rattaché à un dossier Coffrac.',
            ]);
        }

        $payload = $request->validate([
            'document' => [
                'required',
                'file',
                'max:30720',
                'mimes:jpg,jpeg,png,webp,heic,heif,pdf,doc,docx,xls,xlsx,csv,txt',
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'document.required' => 'Ajoute un fichier avant de valider.',
            'document.file' => 'Le document envoyé est invalide.',
            'document.max' => 'Le document ne doit pas dépasser 30 Mo.',
            'document.mimes' => 'Format non accepté. Utilise une image, un PDF, un document Office, CSV ou TXT.',
            'name.max' => 'Le nom du document est trop long.',
            'comment.max' => 'Le commentaire est trop long.',
        ]);

        try {
            $document = $coffracAppointments->uploadDocument(
                $appointment,
                $payload['document'],
                $payload['name'] ?? null,
                $payload['comment'] ?? null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'document' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Document ajouté au dossier Coffrac.',
            'document' => $document,
        ]);
    }
}
