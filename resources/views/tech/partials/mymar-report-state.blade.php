@php($marStatusReview = $incident->statusRequest)
@php($marReportState = $marStatusReview?->status ?? (($incident->source_context['status_decision_pending'] ?? false) ? 'admin_pending' : ($incident->sourceRequest?->status ?? 'queued')))
<div role="status" class="rounded-xl border p-3 text-sm {{ $marReportState === 'confirmed' ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
    @if ($marStatusReview)
        @if ($marStatusReview->status === 'confirmed')
            <strong>
                @can('changeStatus', $incident)
                    Décision administrative confirmée dans MYMAR : {{ \App\Services\Mymar\MymarBookingCommand::REVIEW_STATUSES[$marStatusReview->result['status_id']] ?? 'statut mis à jour' }}.
                @else
                    Décision administrative confirmée dans MYMAR.
                @endcan
            </strong> Les preuves sont conservées.
        @else
            <strong>Décision administrative MAR : {{ $marStatusReview->status === 'rejected' ? 'refusée, à vérifier' : 'confirmation en attente' }}.</strong> L’équipe administrative suit cette demande ; n’envoyez pas un nouveau signalement.
        @endif
    @elseif ($marReportState === 'admin_pending')
        <strong>Signalement enregistré, statut MYMAR conservé.</strong> L’équipe administrative est alertée et décidera du statut depuis les signalements. La photo et le commentaire sont conservés dans Cofrac.
    @elseif ($marReportState === 'confirmed')
        <strong>MYMAR : {{ ($incident->sourceRequest?->result['status_id'] ?? null) === 14 ? 'retour en Prise de RDV après travaux confirmé.' : 'PB prise de RDV APT confirmé (ancien signalement).' }}</strong> Le planning Cofrac est alerté. La photo et le commentaire complet sont conservés dans ce signalement.
    @elseif ($marReportState === 'rejected')
        <strong>Signalement conservé, mais changement d’état MYMAR refusé.</strong> Le planning Cofrac est alerté et doit vérifier le dossier ; ne créez pas un nouveau signalement.
    @elseif ($marReportState === 'queued')
        <strong>Signalement conservé dans Cofrac, pas encore transmis à MYMAR.</strong> L’état du dossier MAR n’est pas confirmé.
    @else
        <strong>Signalement conservé, confirmation MYMAR en attente.</strong> Vérifiez la confirmation sans renvoyer le signalement. La vérification peut prendre au moins 30 secondes.
    @endif
</div>
