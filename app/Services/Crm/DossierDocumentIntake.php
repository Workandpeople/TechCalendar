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
                $type = $data['document_type'] ?? 'free';
                $report = $type === 'report';
                $operation = $type !== 'free' ? $dossier->operations()->lockForUpdate()->findOrFail($data['operation_id']) : null;
                if ($operation && $dossier->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de déposer ce rapport.']);
                }
                if ($report && ! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], ['visited', 'rt_control', 'recontrol'], true)) {
                    throw ValidationException::withMessages(['operation_id' => 'Le rapport peut être ajouté en RDV visité, contrôle RT ou recontrôle.']);
                }
                $metadata = ['description' => $data['description'] ?? null, 'document_type' => $type,
                    'dossier_operation_id' => $operation?->id];
                if ($report) {
                    $metadata += ['dossier_operation_id' => $operation->id, 'control_reference' => $data['control_reference'],
                        'inspected_on' => $data['inspected_on'], 'issued_on' => $data['issued_on'], 'control_outcome' => $data['outcome'],
                        'capcee_emmy_reference' => $data['capcee_emmy_reference'] ?? null,
                        'capcee_object_type' => $data['capcee_object_type'] ?? null,
                        'capcee_inspection_type' => $data['capcee_inspection_type'] ?? null];
                }
                $document = app(DossierRegistry::class)->addDocument($actor, $dossier, $data['document'], $data['name'],
                    $audience['client_visible'], $audience['delegataire_visible'], $metadata);
                if ($operation) {
                    $workflow = app(PostVisitWorkflow::class);
                    $control = ['version' => $dossier->version,
                        'comment' => filled($data['description'] ?? null) ? $data['description'] : 'Document « '.$data['name'].' » déposé.', ...$audience];
                    if ($report) {
                        $workflow->recordReport($actor, $dossier, $operation, $document, $control);
                    } else {
                        $workflow->apply($actor, $dossier, $operation, [...$control, 'action' => 'record_visit',
                            'appointment_id' => $data['appointment_id'], 'visit_document_id' => $document->id]);
                    }
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
