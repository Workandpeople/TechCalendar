<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Services\Mymar\MymarDocuments;
use Cofrac2\Deploy\Mymar\MymarDocumentFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MymarDocumentController extends Controller
{
    public function index(Request $request, int $rdv, MymarDocuments $documents)
    {
        $id = $this->technicianId($request);
        try {
            $catalogue = $documents->catalogue($request->user(), $rdv, $id);

            return response()->view('tech.mymar-documents', ['catalogue' => $catalogue, 'rdv' => $rdv, 'technicianId' => $id, 'unavailable' => false])
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (Throwable $error) {
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            if ($error instanceof MymarDocumentFailure && in_array($error->httpStatus, [403, 404], true)) {
                abort($error->httpStatus);
            }

            return response()->view('tech.mymar-documents', ['catalogue' => null, 'rdv' => $rdv, 'technicianId' => $id, 'unavailable' => true], 503)
                ->header('Cache-Control', 'private, no-store')->header('Retry-After', '30');
        }
    }

    public function download(Request $request, int $rdv, int $document, MymarDocuments $documents)
    {
        $id = $this->technicianId($request);
        try {
            $file = $documents->open($request->user(), $rdv, $document, $id);
        } catch (Throwable $error) {
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            if ($error instanceof MymarDocumentFailure && in_array($error->httpStatus, [403, 404], true)) {
                abort($error->httpStatus);
            }
            abort(503, 'Ce document MAR est momentanément indisponible.');
        }
        $name = trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '-', $file['name']));
        $name = mb_substr(preg_replace('/\.pdf$/i', '', $name) ?: 'Document de travaux', 0, 150).'.pdf';

        return response()->stream(function () use ($file): void {
            try {
                fpassthru($file['stream']);
            } finally {
                fclose($file['stream']);
            }
        }, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) $file['bytes'],
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, str_replace('%', '_', Str::ascii($name))),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }

    private function technicianId(Request $request): ?int
    {
        $data = $request->validate(['technician_id' => ['nullable', 'integer', 'min:1']]);

        return isset($data['technician_id']) ? (int) $data['technician_id'] : null;
    }
}
