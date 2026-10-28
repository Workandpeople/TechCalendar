@if ($capceeUnavailable)
    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $capceeUnavailable }} Les rapports déjà enregistrés restent consultables. Un administrateur doit configurer le connecteur avant l’envoi.</p>
@else
    <p class="mt-3 text-sm font-medium">Destination : {{ config('capcee.environment') === 'production' ? 'PRODUCTION CAPCEE' : 'UAT2 · environnement de test' }}.</p>
@endif
<div class="mt-4 divide-y divide-slate-200">
    @foreach ($dossier->operations as $operation)
        <article class="py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold">{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation #'.$operation->id }}</h3>
                @can('transmitReport', $dossier)
                    <a class="gc-btn-secondary" href="{{ route('v2.capcee.create', [$dossier, $operation]) }}">{{ $capceeUnavailable ? 'Préparer un rapport' : 'Transmettre un rapport' }}</a>
                @endcan
            </div>
            @forelse ($operation->capceeReports as $report)
                @php($delivery = $report->latestDelivery)
                @if ($delivery)
                    <div class="mt-3 min-w-0 rounded-xl border border-slate-200 p-4" @if ($delivery->active()) data-capcee-active @endif>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="break-all text-sm font-medium">GC_{{ $report->reference }} · V{{ $delivery->revision }}</p>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $delivery->status === 'sent' ? 'bg-emerald-50 text-emerald-800' : (in_array($delivery->status, ['failed', 'review']) ? 'bg-rose-50 text-rose-800' : 'bg-sky-50 text-sky-800') }}">{{ \App\Models\CapceeDelivery::LABELS[$delivery->status] ?? $delivery->status }}</span>
                        </div>
                        <p class="mt-2 break-words text-sm">{{ $report->inspection_type === 'site' ? 'Inspection sur site' : 'Inspection par contact' }} · EMMY {{ $report->emmy_reference }} · {{ $report->environment === 'production' ? 'Production' : 'Test UAT2' }}</p>
                        <p class="mt-1 text-xs text-slate-500">Envoi #{{ $delivery->id }} · {{ $delivery->attempts }} tentative(s) · {{ $delivery->sent_at ? 'Reçu le '.$delivery->sent_at->format('d/m/Y H:i') : 'Demandé le '.$delivery->created_at->format('d/m/Y H:i') }} · Email bénéficiaire : {{ $delivery->metadata['send_email'] ? 'autorisé' : 'non demandé' }}</p>
                        @if ($delivery->last_error)<p class="mt-3 break-words text-sm text-rose-800">{{ $delivery->last_error }}</p>@endif
                        @if ($delivery->status === 'review')<p class="mt-2 text-sm text-slate-600">Ne crée pas un autre rapport pour contourner cet état. Faire vérifier la référence ci-dessus par CAPCEE : un fichier peut avoir été reçu malgré l’interruption.</p>@endif
                        @if ($delivery->status === 'queued')<p class="mt-2 text-xs text-slate-500">Suivi automatique. Si l’attente se prolonge, vérifier le worker et le planificateur.</p>@endif
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <a class="text-sm underline" href="{{ route('v2.capcee.download', [$dossier, $delivery]) }}">Voir le PDF envoyé / à envoyer</a>
                            @can('transmitReport', $dossier)
                                @if (! $capceeUnavailable && $report->environment === config('capcee.environment'))
                                    @if ($delivery->status === 'sent')
                                        <a class="gc-btn-secondary" href="{{ route('v2.capcee.revision', [$dossier, $operation, $report]) }}">Nouvelle version</a>
                                    @elseif ($delivery->status === 'failed')
                                        <form method="POST" action="{{ route('v2.capcee.retry', [$dossier, $delivery]) }}">@csrf<button class="gc-btn-secondary" type="submit">Reprendre l’envoi</button></form>
                                    @endif
                                @endif
                            @endcan
                        </div>
                    </div>
                @endif
            @empty
                <p class="mt-3 text-sm text-slate-500">Aucun rapport transmis. Les pièces internes et les anciens documents Cofrac ne sont jamais envoyés automatiquement.</p>
            @endforelse
        </article>
    @endforeach
</div>
