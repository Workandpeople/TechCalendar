@php($marReportState = $incident->sourceRequest?->status ?? 'queued')
<div role="status" class="rounded-xl border p-3 text-sm {{ $marReportState === 'confirmed' ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
    @if ($marReportState === 'confirmed')
        <strong>MYMAR : PB prise de RDV APT confirmé.</strong> Le planning Cofrac est alerté. La photo et le commentaire complet sont conservés dans ce signalement.
    @elseif ($marReportState === 'rejected')
        <strong>Signalement conservé, mais changement d’état MYMAR refusé.</strong> Le planning Cofrac est alerté et doit vérifier le dossier ; ne créez pas un nouveau signalement.
    @elseif ($marReportState === 'queued')
        <strong>Signalement conservé dans Cofrac, pas encore transmis à MYMAR.</strong> L’état du dossier MAR n’est pas confirmé.
    @else
        <strong>Signalement conservé, confirmation MYMAR en attente.</strong> Vérifiez la confirmation sans renvoyer le signalement. La vérification peut prendre au moins 30 secondes.
    @endif
</div>
