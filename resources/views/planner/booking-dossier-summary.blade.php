<section id="booking-dossier-summary" class="gc-card gc-booking-summary" data-booking-summary hidden aria-labelledby="booking-dossier-summary-title">
    <header class="gc-booking-summary-header">
        <div class="gc-booking-summary-heading"><span class="gc-booking-summary-icon"><x-nav-icon name="dossiers" /></span><div><p>Dossier sélectionné</p><h2 id="booking-dossier-summary-title">Informations du dossier</h2></div></div>
        <div class="gc-booking-summary-actions"><span class="gc-status-pill gc-status-pill-neutral" data-booking-summary-field="status" hidden></span><a class="gc-btn-secondary" data-booking-summary-link hidden><x-nav-icon name="eye" />Ouvrir la fiche</a></div>
    </header>
    <dl class="gc-booking-summary-grid">
        <div><dt>Bénéficiaire</dt><dd data-booking-summary-field="beneficiary"></dd></div>
        <div><dt>Téléphone</dt><dd data-booking-summary-phones></dd></div>
        <div><dt>Email</dt><dd data-booking-summary-field="email"></dd></div>
        <div class="gc-booking-summary-address"><dt>Adresse de l’intervention</dt><dd data-booking-summary-field="address"></dd></div>
        <div><dt>Fiche / prestation à planifier</dt><dd data-booking-summary-field="service"></dd></div>
        <div data-booking-summary-optional="site" hidden><dt>Site</dt><dd data-booking-summary-field="site"></dd></div>
        <div data-booking-summary-optional="client" hidden><dt>Client</dt><dd data-booking-summary-field="client"></dd></div>
        <div data-booking-summary-optional="delegataire" hidden><dt>Délégataire</dt><dd data-booking-summary-field="delegataire"></dd></div>
        <div data-booking-summary-optional="installer" hidden><dt>Installateur</dt><dd data-booking-summary-field="installer"></dd></div>
        <div data-booking-summary-optional="lot" hidden><dt>Lot</dt><dd data-booking-summary-field="lot"></dd></div>
    </dl>
    <p class="m-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900" data-booking-summary-optional="location_warning" hidden><span data-booking-summary-field="location_warning"></span></p>
</section>
