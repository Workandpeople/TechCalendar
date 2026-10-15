<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierRequest;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Services\Capcee\CapceeReports;
use App\Services\Crm\CollectedDossierDocuments;
use App\Services\Crm\DossierLinkedDocuments;
use App\Services\Crm\DossierMapPreview;
use App\Services\Crm\DossierPlanningAssignment;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\DossierTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierController extends Controller
{
    public function index(Request $request, DossierRegistry $registry): View|JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'delegataire_id' => ['nullable', 'integer', 'exists:external_delegataires,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
            'planning' => ['nullable', Rule::in(['unlinked', 'upcoming', 'problem'])],
            'status' => ['nullable', Rule::in(array_keys(DossierStatusCatalog::STATUSES))],
            'sort' => ['nullable', Rule::in(array_keys(DossierTable::COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'all_columns' => ['nullable', 'boolean'],
        ]);

        $data = [
            'filters' => $filters,
            'dossiers' => $registry->search($request->user(), $filters),
            'categories' => $registry->categories($request->user(), $filters),
            'table' => app(DossierTable::class),
            'planningUsers' => array_key_exists('planning_user', app(DossierTable::class)->columns($filters))
                ? app(DossierPlanningAssignment::class)->users() : collect(),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'categories' => view('crm.dossiers.categories', $data)->render(),
                'results' => view('crm.dossiers.results', $data)->render(),
                'total' => $data['dossiers']->total(),
            ])->header('Cache-Control', 'private, no-store');
        }

        return view('crm.dossiers.index', [
            ...$registry->references(), ...$data,
            'mapboxToken' => config('services.mapbox.token'),
        ]);
    }

    public function create(DossierRegistry $registry): View
    {
        Gate::authorize('create', Dossier::class);

        return view('crm.dossiers.form', [
            ...$registry->references(),
            'dossier' => new Dossier(['reference' => 'DOS-'.Str::upper((string) Str::ulid())]),
        ]);
    }

    public function mapPreview(Request $request, Dossier $dossier, DossierMapPreview $preview): JsonResponse
    {
        return response()->json($preview->coordinates($request->user(), $dossier))->header('Cache-Control', 'private, no-store');
    }

    public function store(DossierRequest $request, DossierRegistry $registry): RedirectResponse
    {
        $dossier = $registry->create($request->user(), $request->validated());

        return to_route('v2.dossiers.show', $dossier)->with('status', 'Dossier créé. Aucun envoi vers Coffrac ou Global+ n’a été effectué.');
    }

    public function show(Request $request, Dossier $dossier, DossierLinkedDocuments $linkedDocuments, CollectedDossierDocuments $collectedDocuments): View
    {
        Gate::authorize('view', $dossier);
        $dossier->load(['delegataire', 'planningUser', 'operations' => fn ($query) => $query
            ->with(['service', 'reportDocument', 'controlValidator', 'capceeReports.latestDelivery', 'appointments' => fn ($appointments) => $appointments->latest('starts_at')->latest('id')->limit(1)])
            ->withCount('appointments')]);

        return view('crm.dossiers.show', [
            'dossier' => $dossier,
            'visitCandidates' => $dossier->appointments()->where('appointments.status', Appointment::STATUS_SCHEDULED)
                ->where('ends_at', '<=', now())->orderByDesc('starts_at')
                ->get(['appointments.id', 'dossier_operation_id', 'starts_at'])->groupBy('dossier_operation_id'),
            'controlDocuments' => $dossier->documents()->where('mime_type', 'application/pdf')->orderBy('name')->get(['id', 'name']),
            'capceeUnavailable' => app(CapceeReports::class)->availability(),
            'legacyDocuments' => DB::table('legacy_records')->where('dossier_id', $dossier->id)
                ->where('source_table', 'dossier_document')->orderByDesc('source_id')
                ->paginate(20, ['dossier_id', 'source_id', 'payload', 'client_visible_override'], 'legacy_documents_page')->withQueryString()->fragment('documents')
                ->through(fn ($record) => $collectedDocuments->describe($record)),
            'legacyAppointments' => DB::table('legacy_records')->where('dossier_id', $dossier->id)
                ->where('source_table', 'dossier_fiche_rdv')->orderByDesc('source_id')
                ->paginate(20, ['source_id', 'payload'], 'legacy_appointments_page')->withQueryString()->fragment('rendez-vous'),
            'activities' => $dossier->activities()->with('author')->latest('created_at')->latest('id')->paginate(25, ['*'], 'history_page')->withQueryString()->fragment('historique'),
            'documents' => $dossier->documents()->latest('id')->paginate(25, ['*'], 'documents_page')->withQueryString()->fragment('documents'),
            'appointments' => $dossier->appointments()->withTrashed()
                ->with(['technician' => fn ($query) => $query->withTrashed(), 'service', 'lotAppointments.lot'])
                ->orderByDesc('starts_at')->orderByDesc('appointments.id')->paginate(20, ['appointments.*'], 'appointments_page')
                ->withQueryString()->fragment('rendez-vous'),
            'lotDocuments' => $linkedDocuments->query($request->user(), $dossier)->with('lotAppointment.lot')
                ->latest('id')->paginate(25, ['*'], 'lot_documents_page')->withQueryString()->fragment('documents-lots'),
        ]);
    }

    public function edit(Dossier $dossier, DossierRegistry $registry): View
    {
        Gate::authorize('update', $dossier);

        return view('crm.dossiers.form', [...$registry->references(), 'dossier' => $dossier]);
    }

    public function update(DossierRequest $request, Dossier $dossier, DossierRegistry $registry): RedirectResponse
    {
        $registry->update($request->user(), $dossier, $request->validated());

        return to_route('v2.dossiers.show', $dossier)->with('status', 'Informations enregistrées.');
    }

    public function comment(Request $request, Dossier $dossier, DossierRegistry $registry): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'client_visible' => ['sometimes', 'boolean']]);
        $registry->addComment($request->user(), $dossier, $data['body'], $request->boolean('client_visible'));

        return redirect()->to(route('v2.dossiers.show', $dossier).'#historique')->with('status', $request->boolean('client_visible') ? 'Commentaire partagé avec le client.' : 'Commentaire interne ajouté.');
    }

    public function document(Request $request, Dossier $dossier, DossierRegistry $registry): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'client_visible' => ['sometimes', 'boolean'],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,docx,xlsx'],
        ]);
        $registry->addDocument($request->user(), $dossier, $data['document'], $data['name'], $request->boolean('client_visible'));

        return redirect()->to(route('v2.dossiers.show', $dossier).'#documents')->with('status', 'Document enregistré dans le stockage privé.');
    }

    public function download(DossierDocument $document, Request $request, DossierRegistry $registry): StreamedResponse
    {
        return $registry->download($request->user(), $document);
    }

    public function shareDocument(Request $request, DossierDocument $document, DossierRegistry $registry): RedirectResponse
    {
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);
        $registry->shareDocument($request->user(), $document, (bool) $data['client_visible']);

        return back()->with('status', 'Visibilité du document mise à jour.');
    }

    public function shareComment(Request $request, Dossier $dossier, int $comment, DossierRegistry $registry): RedirectResponse
    {
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);
        $registry->shareComment($request->user(), $dossier, $comment, (bool) $data['client_visible']);

        return back()->with('status', 'Visibilité du commentaire mise à jour.');
    }

    public function downloadLotDocument(Request $request, Dossier $dossier, int $document, DossierLinkedDocuments $linkedDocuments): StreamedResponse
    {
        return $linkedDocuments->download($request->user(), $dossier, $document);
    }

    public function shareLotDocument(Request $request, Dossier $dossier, int $document, DossierLinkedDocuments $documents): RedirectResponse
    {
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);
        $documents->share($request->user(), $dossier, $document, (bool) $data['client_visible']);

        return back()->with('status', 'Visibilité du document de lot mise à jour.');
    }

    public function shareCollectedDocument(Request $request, Dossier $dossier, int $document, CollectedDossierDocuments $documents): RedirectResponse
    {
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);
        $documents->share($request->user(), $dossier, $document, (bool) $data['client_visible']);

        return back()->with('status', 'Visibilité du document historique mise à jour.');
    }

    public function downloadCollectedDocument(Request $request, Dossier $dossier, int $document, CollectedDossierDocuments $collectedDocuments): BinaryFileResponse
    {
        return $collectedDocuments->download($request->user(), $dossier, $document);
    }
}
