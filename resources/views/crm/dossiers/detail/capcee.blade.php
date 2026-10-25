<section id="capcee" class="gc-card p-5" data-capcee-status-url="{{ route('v2.capcee.status', $dossier) }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-lg font-semibold">Rapports CAPCEE</h2><p class="mt-1 text-sm text-slate-500">Transmission des rapports validés à TotalEnergies, distincte du planning et de Global+.</p></div>
        <button type="button" class="gc-btn-secondary" data-capcee-refresh>Actualiser le suivi</button>
    </div>
    <p data-capcee-feedback role="status" class="mt-2 text-sm text-slate-500"></p>
    @if ($automaticCapcee->isNotEmpty())
        <div class="my-3 space-y-2">
            <h3 class="font-semibold text-sm">Rapports préparés automatiquement</h3>
            @foreach ($automaticCapcee as $submission)
                <div class="rounded-lg border border-slate-200 p-3 text-sm">
                    <p class="font-semibold">{{ $submission->document->name }} · {{ $submission->environment }}</p>
                    <p>{{ $submission->delivery ? (\App\Models\CapceeDelivery::LABELS[$submission->delivery->status] ?? 'Suivi à vérifier') : (\App\Models\CapceeAutomaticSubmission::BLOCKS[$submission->block_reason] ?? 'Préparation en attente') }}</p>
                    @if (! $submission->capcee_delivery_id)
                        @can('transmitReport', $dossier)
                            <details class="mt-2"><summary class="cursor-pointer gc-link">Compléter les identifiants du rapport, sans redéposer le PDF</summary>
                                <form method="POST" action="{{ route('v2.capcee.automatic.update', [$dossier, $submission]) }}" class="mt-3 space-y-2">
                                    @csrf @method('PATCH')<input type="hidden" name="version" value="{{ $submission->version }}">
                                    <label class="gc-label">Référence EMMY<input class="gc-input" name="capcee_emmy_reference" maxlength="255" required value="{{ $submission->document->capcee_emmy_reference }}"></label>
                                    <label class="gc-label">Objet<select class="gc-input" name="capcee_object_type" required><option value="">Choisir</option>@foreach (['Operation__c' => 'Opération', 'Action__c' => 'Action'] as $key => $label)<option value="{{ $key }}" @selected($submission->document->capcee_object_type === $key)>{{ $label }}</option>@endforeach</select></label>
                                    <label class="gc-label">Inspection<select class="gc-input" name="capcee_inspection_type" required><option value="">Choisir</option>@foreach (['site' => 'Sur site', 'contact' => 'Par contact'] as $key => $label)<option value="{{ $key }}" @selected($submission->document->capcee_inspection_type === $key)>{{ $label }}</option>@endforeach</select></label>
                                    <button type="submit" class="gc-btn-secondary">Enregistrer les identifiants</button>
                                </form>
                            </details>
                        @endcan
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    <div data-capcee-results>@include('crm.capcee.reports')</div>
</section>
