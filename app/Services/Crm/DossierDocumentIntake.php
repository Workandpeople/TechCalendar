<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DossierDocumentIntake
{
    public function store(User $actor, Dossier $dossier, array $data, array $audience): DossierDocument
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        $document = null;
        try {
            return DB::transaction(function () use ($actor, $dossier, $data, $audience, &$document): DossierDocument {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
                $report = ($data['document_type'] ?? 'free') === 'report';
                $operation = $report ? $dossier->operations()->lockForUpdate()->findOrFail($data['operation_id']) : null;
                if ($report && $dossier->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de déposer ce rapport.']);
                }
                $metadata = ['description' => $data['description'] ?? null, 'document_type' => $report ? 'report' : 'free'];
                if ($report) {
                    $metadata += ['dossier_operation_id' => $operation->id, 'control_reference' => $data['control_reference'],
                        'inspected_on' => $data['inspected_on'], 'issued_on' => $data['issued_on'], 'control_outcome' => $data['outcome']];
                }
                $document = app(DossierRegistry::class)->addDocument($actor, $dossier, $data['document'], $data['name'],
                    $audience['client_visible'], $audience['delegataire_visible'], $metadata);
                $action = $data['workflow_action'] ?? 'store';
                if ($report && $action !== 'store') {
                    $workflow = app(PostVisitWorkflow::class);
                    $control = ['version' => $dossier->version, 'action' => $action,
                        'comment' => filled($data['description'] ?? null) ? $data['description'] : 'Rapport '.$data['control_reference'].' déposé.',
                        'outcome' => $data['outcome'], 'inspected_on' => $data['inspected_on'],
                        'report_document_id' => $document->id, ...$audience];
                    if ($action === 'validate_report' && DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'rt_control') {
                        // The uploaded conclusion must be the one validated, not a stale previous draft.
                        $workflow->apply($actor, $dossier, $operation, [...$control, 'action' => 'revise_control']);
                        $control['version'] = $dossier->fresh()->version;
                    }
                    $workflow->apply($actor, $dossier, $operation, $control);
                }

                return $document;
            });
        } catch (Throwable $exception) {
            if ($document) {
                Storage::disk('local')->delete($document->path);
            }
            throw $exception;
        }
    }
}
