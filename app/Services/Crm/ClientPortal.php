<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\User;
use App\Services\LotAutoCompletionCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientPortal
{
    public function __construct(private readonly ClientAccess $access) {}

    public function dossiers(User $actor, array $filters): array
    {
        $allowed = $this->access->categoryKeys($actor);
        abort_if(filled($filters['status'] ?? null) && ! in_array($filters['status'], $allowed, true), 403);
        $query = $this->access->dossiers($actor);
        if (filled($filters['q'] ?? null)) {
            DossierSearch::apply($query, $filters['q'], ['reference', 'company_name', 'last_name', 'first_name', 'legacy_lot_name', 'address', 'postal_code', 'city']);
        }
        [$categorySql, $bindings] = DossierStatusCatalog::categorySql();
        $counts = DB::query()->fromSub((clone $query)->select('dossiers.id'), 'visible_dossiers')
            ->leftJoin('dossier_operations', 'dossier_operations.dossier_id', '=', 'visible_dossiers.id')
            ->selectRaw($categorySql.' AS category, COUNT(DISTINCT visible_dossiers.id) AS aggregate', $bindings)
            ->groupBy('category')->pluck('aggregate', 'category');
        $categories = ['all' => ['label' => 'Tous mes dossiers', 'tone' => 'slate', 'count' => (clone $query)->count()]];
        foreach ($allowed as $key) {
            $categories[$key] = [...PortalCategoryAccess::catalogue()[$key], 'count' => (int) ($counts[$key] ?? 0)];
        }
        if (filled($filters['status'] ?? null)) {
            $query->where(function ($query) use ($filters): void {
                $query->whereHas('operations', fn ($operations) => DossierStatusCatalog::filter($operations, $filters['status']));
                if ($filters['status'] === 'unknown') {
                    $query->orWhereDoesntHave('operations');
                }
            });
        }

        return ['dossiers' => $query->with('operations.service')->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters, 'categories' => $categories];
    }

    public function dossier(User $actor, Dossier $dossier): array
    {
        Gate::forUser($actor)->authorize('view', $dossier);
        $dossier->load('operations.service');
        $documents = $this->access->allows($actor, 'documents')
            ? $dossier->documents()->where($this->access->audienceColumn($actor), true)->latest('id')->paginate(15, ['*'], 'documents_page')->fragment('client-documents') : null;
        $collected = null;
        $lotDocuments = null;
        if ($this->access->allows($actor, 'documents')) {
            $collected = $this->access->filterLegacyDocuments(DB::table('legacy_records')->where('source_table', 'dossier_document')
                ->where('dossier_id', $dossier->id), $actor)
                ->orderByDesc('source_id')->paginate(15, ['*'], 'archive_page')->withQueryString()->fragment('client-documents');
            $collected->setCollection($collected->getCollection()->filter(fn ($record) => $this->access->canReadLegacyDocument($actor,
                json_decode($record->payload, true) ?: [], isset($record->client_visible_override) ? (bool) $record->client_visible_override : null,
                isset($record->delegataire_visible_override) ? (bool) $record->delegataire_visible_override : null))
                ->map(function ($record) use ($dossier): array {
                    $document = app(CollectedDossierDocuments::class)->describe($record);
                    $document['url'] = $document['url'] ? route('client.dossiers.archive', [$dossier, $record->source_id]) : null;
                    try {
                        $document['display_date'] = filled($document['created_at']) ? Carbon::parse($document['created_at'])->format('d/m/Y') : null;
                    } catch (\Throwable) {
                        $document['display_date'] = null;
                    }

                    return $document;
                }));
            $lotDocuments = app(DossierLinkedDocuments::class)->query($actor, $dossier)
                ->latest('id')->paginate(15, ['*'], 'lot_documents_page')->withQueryString()->fragment('client-documents');
        }

        return [
            'dossier' => $dossier, 'documents' => $documents, 'collected' => $collected, 'lotDocuments' => $lotDocuments,
            'nextAppointment' => $dossier->appointments()->where('appointments.status', Appointment::STATUS_SCHEDULED)
                ->where('ends_at', '>=', now())->orderBy('starts_at')->first(),
            'appointments' => $dossier->appointments()->orderByDesc('starts_at')->paginate(10, ['appointments.*'], 'rdv_page')->fragment('client-appointments'),
            'comments' => $this->access->allows($actor, 'comments')
                ? $this->access->comments($actor, $dossier)->latest('created_at')->latest('id')->paginate(15, ['*'], 'comments_page')->fragment('client-comments') : null,
        ];
    }

    public function lot(User $actor, int $id): array
    {
        $lot = $this->access->lots($actor)->findOrFail($id);
        $rows = $this->access->lotRows($lot->appointments()->getQuery(), $actor);
        $restricted = count($this->access->categoryKeys($actor)) < count(PortalCategoryAccess::catalogue());
        // Reuse the existing sampling/satisfaction rules; no parallel calculation.
        // Global results would reveal excluded dossiers; do not display misleading partial rates.
        $stats = $restricted ? ['physical' => null, 'contact' => null] : app(LotAutoCompletionCalculator::class)->calculate($lot, (clone $rows)->get([
            'id', 'lot_id', 'appointment_id', 'status', 'processing_mode', 'excluded_from_lot_stats',
            'physical_satisfaction', 'contact_satisfaction',
        ]));

        return ['lot' => $lot, 'stats' => $stats, 'restricted' => $restricted, 'rows' => $rows
            ->orderBy('row_number')->orderBy('id')->paginate(25, [
                'id', 'customer_name', 'company_name', 'site_name', 'address', 'postal_code', 'city',
                'status', 'processing_mode', 'physical_satisfaction', 'contact_satisfaction',
            ])];
    }
}
