<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ClientCorrectionRequest;
use App\Http\Requests\Crm\ClientDossierRequest;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\DossierOperation;
use App\Models\Service;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\ClientPortal;
use App\Services\Crm\CollectedDossierDocuments;
use App\Services\Crm\DossierLinkedDocuments;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\PostVisitWorkflow;
use App\Services\DocumentUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientPortalController extends Controller
{
    public function index(Request $request, ClientPortal $portal)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255'],
            'lot' => ['nullable', 'string', 'max:256'],
            'status' => ['nullable', Rule::in(array_keys(DossierStatusCatalog::STATUSES))]]);

        return view('client.dossiers', $portal->dossiers($request->user(), $filters));
    }

    public function show(Request $request, Dossier $dossier, ClientPortal $portal)
    {
        return view('client.dossier', $portal->dossier($request->user(), $dossier));
    }

    public function create()
    {
        Gate::authorize('createFromPortal', Dossier::class);

        return view('client.create-dossier', [
            'dossier' => new Dossier,
            'submissionId' => (string) Str::uuid(),
            'services' => Service::query()->catalog()->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function store(ClientDossierRequest $request, DossierRegistry $registry)
    {
        $dossier = $registry->createForClient($request->user(), $request->validated());

        return redirect()->route('client.dossiers.show', $dossier)
            ->with('status', 'Dossier créé. Notre équipe le retrouve dans « Prise de RDV » pour organiser la visite.');
    }

    public function lots(Request $request, ClientAccess $access)
    {
        return view('client.lots', ['lots' => $access->lots($request->user())
            ->withCount(['appointments' => fn ($rows) => $access->lotRows($rows, $request->user())])->latest('id')->paginate(12)]);
    }

    public function lot(Request $request, int $lot, ClientPortal $portal)
    {
        return view('client.lot', $portal->lot($request->user(), $lot));
    }

    public function comment(Request $request, Dossier $dossier, DossierRegistry $registry)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $registry->addComment($request->user(), $dossier, $data['body']);

        return redirect()->route('client.dossiers.show', $dossier)->with('status', 'Commentaire transmis à notre équipe.');
    }

    public function upload(Request $request, Dossier $dossier, DossierRegistry $registry)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:190'],
            'document' => ['bail', 'required', 'file', DocumentUpload::SIZE_RULE, 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,docx,xlsx']],
            ['document.max' => DocumentUpload::TOO_LARGE]);
        $registry->addDocument($request->user(), $dossier, $data['document'], $data['name']);

        return redirect()->route('client.dossiers.show', $dossier)->with('status', 'Document transmis à notre équipe.');
    }

    public function download(Request $request, DossierDocument $document, DossierRegistry $registry)
    {
        return $registry->download($request->user(), $document, $request->boolean('preview'));
    }

    public function respondToNonConformity(ClientCorrectionRequest $request, Dossier $dossier, DossierOperation $operation, PostVisitWorkflow $workflow)
    {
        $workflow->respond($request->user(), $dossier, $operation, $request->validated());

        $visible = app(ClientAccess::class)->viewDossier($request->user(), $dossier);

        return redirect()->route($visible ? 'client.dossiers.show' : 'client.dossiers', $visible ? [$dossier] : [])
            ->with('status', 'Votre réponse a été transmise. La prestation est maintenant en recontrôle auprès de notre équipe.');
    }

    public function archive(Request $request, Dossier $dossier, int $document, CollectedDossierDocuments $documents)
    {
        return $documents->download($request->user(), $dossier, $document, $request->boolean('preview'));
    }

    public function lotDocument(Request $request, Dossier $dossier, int $document, DossierLinkedDocuments $documents)
    {
        return $documents->download($request->user(), $dossier, $document, $request->boolean('preview'));
    }
}
