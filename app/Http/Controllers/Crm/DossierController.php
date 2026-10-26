<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierDocumentRequest;
use App\Http\Requests\Crm\DossierListRequest;
use App\Http\Requests\Crm\DossierRequest;
use App\Models\Appointment;
use App\Models\CapceeAutomaticSubmission;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\DossierSms;
use App\Models\User;
use App\Services\Capcee\CapceeReports;
use App\Services\Crm\CollectedDossierDocuments;
use App\Services\Crm\ContentAudience;
use App\Services\Crm\DossierDocumentIntake;
use App\Services\Crm\DossierLinkedDocuments;
use App\Services\Crm\DossierMail;
use App\Services\Crm\DossierMapPreview;
use App\Services\Crm\DossierPlanningAssignment;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\DossierTable;
use App\Services\Sms\DossierSmsService;
use App\Services\Sms\SmsConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierController extends Controller
{
    public function index(DossierListRequest $request, DossierRegistry $registry): View|JsonResponse
    {
        $filters = $request->validated();

        $data = [
            'filters' => $filters,
            'dossiers' => $registry->search($request->user(), $filters),
            'categories' => $registry->categories($request->user(), $filters),
            'table' => app(DossierTable::class),
            'planningUsers' => app(DossierPlanningAssignment::class)->users(),
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
        if ($dossier->trashed()) {
            return view('crm.dossiers.archived', [
                'dossier' => $dossier->load('operations.service'),
                'services' => app(DossierRegistry::class)->references()['services'],
                'documents' => $dossier->documents()->latest('id')->paginate(25),
                'legacyDocuments' => DB::table('legacy_records')->where('dossier_id', $dossier->id)
                    ->where('source_table', 'dossier_document')->whereNull('hidden_at')->orderByDesc('source_id')
                    ->paginate(20, ['*'], 'legacy_documents_page')->through(fn ($record) => $collectedDocuments->describe($record)),
                'activities' => $dossier->activities()->with('author')->latest('id')->paginate(25, ['*'], 'history_page'),
            ]);
        }
        $dossier->load(['client', 'delegataire', 'planningUser', 'operations' => fn ($query) => $query
            ->with(['service', 'billing.marker', 'reportDocument', 'controlValidator', 'capceeReports.latestDelivery', 'appointments' => fn ($appointments) => $appointments->latest('starts_at')->latest('id')->limit(1)])
            ->withCount('appointments')]);
        $legacyDocuments = DB::table('legacy_records')->where('dossier_id', $dossier->id)
            ->where('source_table', 'dossier_document')->whereNull('hidden_at')->orderByDesc('source_id')
            ->paginate(20, ['*'], 'legacy_documents_page')->withQueryString()->fragment('documents');
        $authors = $collectedDocuments->authors($legacyDocuments->getCollection());
        $legacyDocuments->through(fn ($record) => $collectedDocuments->describe($record, $authors));
        $smsUnavailable = app(SmsConfiguration::class)->unavailable();

        return view('crm.dossiers.show', [
            ...app(DossierRegistry::class)->references(),
            'dossier' => $dossier,
            'mailTemplates' => app(DossierMail::class)->templates(),
            'technicians' => User::query()->where('role', 2)->where('admin', false)->with('services:id')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'smsUnavailable' => $smsUnavailable,
            'smsMode' => app(SmsConfiguration::class)->mode(),
            'smsTemplates' => $smsUnavailable === null ? app(DossierSmsService::class)->templates() : collect(),
            'smsMessages' => $smsUnavailable === null ? DossierSms::where('dossier_id', $dossier->id)->latest('id')->limit(10)->get() : collect(),
            'smsAppointments' => $smsUnavailable === null ? $dossier->appointments()->with(['technician', 'service'])->orderByDesc('starts_at')->limit(100)->get() : collect(),
            'visitCandidates' => $dossier->appointments()->where('appointments.status', Appointment::STATUS_SCHEDULED)
                ->where('ends_at', '<=', now())->orderByDesc('starts_at')
                ->get(['appointments.id', 'dossier_operation_id', 'starts_at'])->groupBy('dossier_operation_id'),
            'controlDocuments' => $dossier->documents()->where('mime_type', 'application/pdf')->orderBy('name')->get(['id', 'name', 'document_type', 'dossier_operation_id']),
            'capceeUnavailable' => app(CapceeReports::class)->availability(),
            'automaticCapcee' => CapceeAutomaticSubmission::query()
                ->whereHas('document', fn ($query) => $query->where('dossier_id', $dossier->id))
                ->with('document', 'delivery')->latest('id')->limit(30)->get(),
            'legacyDocuments' => $legacyDocuments,
            'comments' => $dossier->comments()->with('author')->latest('created_at')->latest('id')
                ->paginate(25, ['*'], 'comments_page')->withQueryString()->fragment('commentaires'),
            'activities' => $dossier->activities()->whereNotIn('kind', DossierActivity::COMMENT_KINDS)
                ->with('author')->latest('created_at')->latest('id')->paginate(25, ['*'], 'history_page')->withQueryString()->fragment('historique'),
            'documents' => $dossier->documents()->with('author')->latest('id')->paginate(25, ['*'], 'documents_page')->withQueryString()->fragment('documents'),
            'appointments' => $dossier->appointments()->withTrashed()
                ->with(['technician' => fn ($query) => $query->withTrashed(), 'service', 'lotAppointments.lot'])
                ->orderByDesc('starts_at')->orderByDesc('appointments.id')->paginate(20, ['appointments.*'], 'appointments_page')
                ->withQueryString()->fragment('rendez-vous'),
            'lotDocuments' => $linkedDocuments->query($request->user(), $dossier)->with(['lotAppointment.lot', 'uploader'])
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
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], ...ContentAudience::rules()]);
        $audience = ContentAudience::fromRequest($request, 'client');
        $registry->addComment($request->user(), $dossier, $data['body'], $audience['client_visible'], $audience['delegataire_visible']);

        return redirect()->to(route('v2.dossiers.show', $dossier).'#commentaires')->with('status', $audience['client_visible'] ? 'Commentaire partagé selon les destinataires choisis.' : 'Commentaire interne ajouté.');
    }

    public function document(DossierDocumentRequest $request, Dossier $dossier, DossierDocumentIntake $intake): RedirectResponse
    {
        $intake->store($request->user(), $dossier, $request->validated(), ContentAudience::fromRequest($request, 'client'));

        return redirect()->to(route('v2.dossiers.show', $dossier).'#documents')->with('status', 'Document enregistré. Pour un délégataire CAPCEE, consulte le suivi de la transmission après validation du rapport.');
    }

    public function download(DossierDocument $document, Request $request, DossierRegistry $registry): StreamedResponse
    {
        return $registry->download($request->user(), $document, $request->boolean('preview'));
    }

    public function shareDocument(Request $request, DossierDocument $document, DossierRegistry $registry): RedirectResponse
    {
        $request->validate(ContentAudience::rules(true));
        $data = ContentAudience::fromRequest($request);
        $registry->shareDocument($request->user(), $document, $data['client_visible'], $data['delegataire_visible']);

        return back()->with('status', 'Visibilité du document mise à jour.');
    }

    public function shareComment(Request $request, Dossier $dossier, int $comment, DossierRegistry $registry): RedirectResponse
    {
        $request->validate(ContentAudience::rules(true));
        $data = ContentAudience::fromRequest($request);
        $registry->shareComment($request->user(), $dossier, $comment, $data['client_visible'], $data['delegataire_visible']);

        return back()->with('status', 'Visibilité du commentaire mise à jour.');
    }

    public function downloadLotDocument(Request $request, Dossier $dossier, int $document, DossierLinkedDocuments $linkedDocuments): StreamedResponse
    {
        return $linkedDocuments->download($request->user(), $dossier, $document, $request->boolean('preview'));
    }

    public function shareLotDocument(Request $request, Dossier $dossier, int $document, DossierLinkedDocuments $documents): RedirectResponse
    {
        $request->validate(ContentAudience::rules(true));
        $data = ContentAudience::fromRequest($request);
        $documents->share($request->user(), $dossier, $document, $data['client_visible'], $data['delegataire_visible']);

        return back()->with('status', 'Visibilité du document de lot mise à jour.');
    }

    public function shareCollectedDocument(Request $request, Dossier $dossier, int $document, CollectedDossierDocuments $documents): RedirectResponse
    {
        $request->validate(ContentAudience::rules(true));
        $data = ContentAudience::fromRequest($request);
        $documents->share($request->user(), $dossier, $document, $data['client_visible'], $data['delegataire_visible']);

        return back()->with('status', 'Visibilité du document historique mise à jour.');
    }

    public function downloadCollectedDocument(Request $request, Dossier $dossier, int $document, CollectedDossierDocuments $collectedDocuments): BinaryFileResponse
    {
        return $collectedDocuments->download($request->user(), $dossier, $document, $request->boolean('preview'));
    }

    public function removeDocument(DossierDocument $document, Request $request, DossierRegistry $registry): RedirectResponse
    {
        $registry->removeDocument($request->user(), $document);

        return back()->with('status', 'Document retiré de la fiche. Trace conservée.');
    }

    public function removeComment(Request $request, Dossier $dossier, int $comment, DossierRegistry $registry): RedirectResponse
    {
        $registry->removeComment($request->user(), $dossier, $comment);

        return back()->with('status', 'Commentaire retiré. Historique conservé.');
    }

    public function removeCollectedDocument(Request $request, Dossier $dossier, int $document, CollectedDossierDocuments $documents): RedirectResponse
    {
        $documents->remove($request->user(), $dossier, $document);

        return back()->with('status', 'Document historique retiré. Le fichier source est conservé.');
    }
}
