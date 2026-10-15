<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use App\Services\LotAutoCompletionCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientPortal
{
    public function __construct(private readonly ClientAccess $access) {}

    public function dossiers(User $actor, array $filters): array
    {
        $query = $this->access->dossiers($actor)->with('operations.service');
        if (filled($filters['q'] ?? null)) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(function ($query) use ($search): void {
                foreach (['reference', 'company_name', 'last_name', 'first_name', 'legacy_lot_name'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '!'", [$search]);
                }
            });
        }
        if (filled($filters['status'] ?? null)) {
            $query->where(function ($query) use ($filters): void {
                $query->whereHas('operations', fn ($operations) => DossierStatusCatalog::filter($operations, $filters['status']));
                if ($filters['status'] === 'unknown') {
                    $query->orWhereDoesntHave('operations');
                }
            });
        }

        return ['dossiers' => $query->latest('id')->paginate(25)->withQueryString(), 'filters' => $filters];
    }

    public function dossier(User $actor, Dossier $dossier): array
    {
        Gate::forUser($actor)->authorize('view', $dossier);
        $dossier->load('operations.service');
        $documents = $this->access->allows($actor, 'documents')
            ? $dossier->documents()->where('client_visible', true)->latest('id')->paginate(15, ['*'], 'documents_page') : null;
        $collected = null;
        $lotDocuments = null;
        if ($this->access->allows($actor, 'documents')) {
            $collected = $this->access->filterLegacyDocuments(DB::table('legacy_records')->where('source_table', 'dossier_document')
                ->where('dossier_id', $dossier->id), $actor)
                ->orderByDesc('source_id')->paginate(15, ['*'], 'archive_page')->withQueryString();
            $collected->setCollection($collected->getCollection()->filter(fn ($record) => $this->access->canReadLegacyDocument($actor,
                json_decode($record->payload, true) ?: [], isset($record->client_visible_override) ? (bool) $record->client_visible_override : null))
                ->map(function ($record) use ($dossier): array {
                    $document = app(CollectedDossierDocuments::class)->describe($record);
                    $document['url'] = $document['url'] ? route('client.dossiers.archive', [$dossier, $record->source_id]) : null;

                    return $document;
                }));
            $lotDocuments = app(DossierLinkedDocuments::class)->query($actor, $dossier)
                ->latest('id')->paginate(15, ['*'], 'lot_documents_page')->withQueryString();
        }

        return [
            'dossier' => $dossier, 'documents' => $documents, 'collected' => $collected, 'lotDocuments' => $lotDocuments,
            'appointments' => $dossier->appointments()->orderByDesc('starts_at')->paginate(10, ['appointments.*'], 'rdv_page'),
            'comments' => $this->access->allows($actor, 'comments')
                ? $this->access->comments($actor, $dossier)->latest('created_at')->latest('id')->paginate(15, ['*'], 'comments_page') : null,
        ];
    }

    public function lot(User $actor, int $id): array
    {
        $lot = $this->access->lots($actor)->findOrFail($id);
        // Reuse the existing sampling/satisfaction rules; no parallel calculation.
        $stats = app(LotAutoCompletionCalculator::class)->calculate($lot, $lot->appointments()->get([
            'id', 'lot_id', 'appointment_id', 'status', 'processing_mode', 'excluded_from_lot_stats',
            'physical_satisfaction', 'contact_satisfaction',
        ]));

        return ['lot' => $lot, 'stats' => $stats, 'rows' => $lot->appointments()
            ->orderBy('row_number')->orderBy('id')->paginate(25, [
                'id', 'customer_name', 'company_name', 'site_name', 'address', 'postal_code', 'city',
                'status', 'processing_mode', 'physical_satisfaction', 'contact_satisfaction',
            ])];
    }
}
