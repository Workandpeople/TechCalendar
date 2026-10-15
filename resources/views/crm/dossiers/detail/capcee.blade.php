<section id="capcee" class="gc-card p-5" data-capcee-status-url="{{ route('v2.capcee.status', $dossier) }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-lg font-semibold">Rapports CAPCEE</h2><p class="mt-1 text-sm text-slate-500">Transmission des rapports validés à TotalEnergies, distincte du planning et de Global+.</p></div>
        <button type="button" class="gc-btn-secondary" data-capcee-refresh>Actualiser le suivi</button>
    </div>
    <p data-capcee-feedback role="status" class="mt-2 text-sm text-slate-500"></p>
    <div data-capcee-results>@include('crm.capcee.reports')</div>
</section>
