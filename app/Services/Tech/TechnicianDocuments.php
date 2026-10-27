<?php

namespace App\Services\Tech;

use App\Models\Appointment;
use App\Models\DossierDocument;
use App\Models\User;
use App\Services\Crm\CollectedDossierDocuments;
use App\Services\Crm\DocumentResponseHeaders;
use App\Services\Crm\DossierLinkedDocuments;
use App\Services\Crm\DossierRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class TechnicianDocuments
{
    public function __construct(private readonly CollectedDossierDocuments $archive, private readonly DossierLinkedDocuments $linked) {}

    public function page(User $actor, Appointment $appointment, string $search = ''): array
    {
        Gate::forUser($actor)->authorize('view', $appointment);
        $appointment->loadMissing('dossierOperation.dossier');
        $dossier = $appointment->dossierOperation?->dossier;
        if ($dossier) {
            Gate::forUser($actor)->authorize('viewDocuments', $dossier);
        }

        $native = $dossier?->documents()->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->latest('id')->paginate(30, ['*'], 'documents_page')->withQueryString()->fragment('tech-documents');
        $native?->through(fn (DossierDocument $document) => [
            'id' => $document->id, 'name' => $document->name, 'description' => $document->description,
            'date' => $document->created_at?->format('d/m/Y'), 'size' => $document->size,
            'label' => match ($document->document_type) {
                'report' => 'Rapport', 'visit' => 'Visite technique', default => 'Document'
            },
            'outcome' => $document->document_type === 'report' ? $document->control_outcome : null,
            'url' => $this->url($appointment, 'dossier', $document->id),
            'previewable' => DocumentResponseHeaders::previewable($document->mime_type),
        ]);

        $collected = $dossier ? DB::table('legacy_records')->where('source_table', 'dossier_document')->where('dossier_id', $dossier->id)
            ->whereNull('hidden_at')->when($search !== '', fn ($q) => $q->where('payload->name', 'like', '%'.$search.'%'))
            ->orderByDesc('source_id')->paginate(30, ['*'], 'archive_page')->withQueryString()->fragment('tech-documents') : null;
        $collected?->through(function ($record) use ($appointment): array {
            $file = $this->archive->describe($record);

            return [...$file, 'label' => 'Archive Coffrac', 'date' => substr((string) $file['created_at'], 0, 10),
                'url' => $file['url'] ? $this->url($appointment, 'archive', $record->source_id) : null];
        });

        $lots = ($dossier ? $this->linked->query($actor, $dossier) : $this->linked->queryForAppointment($actor, $appointment))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->latest('id')->paginate(30, ['*'], 'lot_documents_page')->withQueryString()->fragment('tech-documents')
            ->through(fn ($document) => [
                'id' => $document->id, 'name' => $document->name, 'description' => null,
                'date' => $document->created_at?->format('d/m/Y'), 'size' => $document->size, 'label' => 'Document de lot', 'outcome' => null,
                'url' => $this->url($appointment, 'lot', $document->id), 'previewable' => DocumentResponseHeaders::previewable($document->mime),
            ]);

        return ['documentGroups' => ['Documents du dossier' => $native, 'Archives Coffrac' => $collected, 'Documents de lot' => $lots],
            'documentCount' => ($native?->total() ?? 0) + ($collected?->total() ?? 0) + $lots->total(), 'documentSearch' => $search];
    }

    public function download(User $actor, Appointment $appointment, string $kind, int $id, bool $preview): Response
    {
        Gate::forUser($actor)->authorize('view', $appointment);
        $appointment->loadMissing('dossierOperation.dossier');
        $dossier = $appointment->dossierOperation?->dossier;
        if ($dossier) {
            Gate::forUser($actor)->authorize('viewDocuments', $dossier);
        }
        if ($kind === 'lot') {
            return $dossier ? $this->linked->download($actor, $dossier, $id, $preview)
                : $this->linked->downloadForAppointment($actor, $appointment, $id, $preview);
        }
        abort_unless($dossier, 404);

        return match ($kind) {
            'dossier' => app(DossierRegistry::class)->download($actor, $dossier->documents()->findOrFail($id), $preview),
            'archive' => $this->archive->download($actor, $dossier, $id, $preview),
            default => abort(404),
        };
    }

    private function url(Appointment $appointment, string $kind, int $id): string
    {
        return route('tech.documents.download', [$appointment, $kind, $id]);
    }
}
