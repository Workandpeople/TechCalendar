<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\LotAppointmentDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierLinkedDocuments
{
    public function query(User $actor, Dossier $dossier): Builder
    {
        Gate::forUser($actor)->authorize('view', $dossier);
        abort_if($actor->isExternal() && ! app(ClientAccess::class)->allows($actor, 'documents'), 403);
        $appointments = $dossier->appointments()->withTrashed()->select('appointments.id');

        return LotAppointmentDocument::query()->when($actor->isExternal(), fn ($query) => $query
            ->where(app(ClientAccess::class)->audienceColumn($actor), true)->where('is_private', false))
            ->where(function ($query) use ($appointments): void {
                $query->whereIn('appointment_id', $appointments)
                    ->orWhereHas('lotAppointment', fn ($row) => $row->whereIn('appointment_id', clone $appointments));
            });
    }

    public function share(User $actor, Dossier $dossier, int $documentId, bool $visible, ?bool $delegataireVisible = null): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $documentId, $visible, $delegataireVisible): void {
            $document = $this->query($actor, $dossier)->lockForUpdate()->findOrFail($documentId);
            $document->update(['client_visible' => $visible, 'delegataire_visible' => $visible && ($delegataireVisible ?? $visible), 'is_private' => ! $visible]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_visibility',
                'body' => 'Accès « '.ContentAudience::LABELS[ContentAudience::key($document->client_visible, $document->delegataire_visible)].' » pour le document de lot #'.$documentId,
                'changed_fields' => ['lot_document_id' => $documentId, 'client_visible' => $document->client_visible, 'delegataire_visible' => $document->delegataire_visible]]);
        });
    }

    public function download(User $actor, Dossier $dossier, int $documentId, bool $preview = false): StreamedResponse
    {
        $document = $this->query($actor, $dossier)->findOrFail($documentId);
        abort_unless($document->disk === 'local', 404);
        $prefix = 'lot-appointment-documents/'.$document->lot_appointment_id.'/';
        $filename = basename($document->path);
        abort_unless($document->path === $prefix.$filename && $filename !== '.' && $filename !== '..', 404);

        $disk = Storage::disk('local');
        $root = realpath($disk->path($prefix));
        $path = realpath($disk->path($document->path));
        $storageRoot = realpath($disk->path(''));
        abort_unless($root && $path && $storageRoot && is_file($path)
            && $root === rtrim($storageRoot, '/').'/'.rtrim($prefix, '/')
            && str_starts_with($path, $root.'/'), 404);

        $name = preg_replace('/[^\pL\pN ._-]/u', '_', $document->name) ?: 'Document';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if ($extension !== '' && ! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        $mime = $preview ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : 'application/octet-stream';
        $inline = $preview && DocumentResponseHeaders::previewable($mime);

        return $disk->response($document->path, $name,
            DocumentResponseHeaders::forFile($mime, $inline), $inline ? 'inline' : 'attachment');
    }
}
