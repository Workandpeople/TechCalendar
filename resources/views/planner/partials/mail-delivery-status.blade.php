@if(config('crm.v2_enabled'))
    <section id="{{ $mailStatusId }}" data-mail-delivery-status data-status-url="" data-pending="false"
        data-empty-label="Aucun nouvel envoi suivi pour ce RDV. Les anciens envois ne sont pas vérifiables ici."
        class="mt-4 rounded-xl border bg-white p-4" style="border-color:var(--gc-border);">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold">Suivi des envois</h4>
            <button type="button" data-mail-status-refresh class="gc-btn-soft text-xs" disabled>Actualiser le suivi</button>
        </div>
        <ul data-mail-status-list class="mt-3 divide-y" aria-live="polite"></ul>
        <p data-mail-status-feedback class="mt-2 text-xs" style="color:var(--gc-text-soft);"></p>
    </section>
@endif
