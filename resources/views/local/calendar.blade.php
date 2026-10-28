<x-layouts.app workspace-route="planner.book">
    @vite('resources/js/calendar-lab.js')
    <div class="calendar-local-preview space-y-6">
        <x-workspace-heading title="Prise de RDV" eyebrow="Planning" icon="book" description="Sélectionnez un dossier à placer pour trouver le technicien et le créneau adaptés.">
            <span class="cl-local">Essai local · calendrier sur mesure</span>
        </x-workspace-heading>
        <div class="cl-safety"><strong>Données fictives.</strong> L’interface habituelle est conservée ; seul le calendrier est en essai. Aucun email, SMS ou changement en base.</div>
        <nav class="cl-mode" aria-label="Mode de la démo"><button type="button" data-lab-mode="booking" aria-pressed="true">Prendre un RDV</button><button type="button" data-lab-mode="tracking" aria-pressed="false">Les RDV</button></nav>
        <ol class="gc-workflow-guide" id="lab-workflow" aria-label="Parcours de prise de rendez-vous">
            <li><span class="gc-workspace-step" aria-hidden="true">1</span><div><strong>Choisir un dossier</strong><p>Une demande à placer ou un dossier issu d’un lot.</p></div></li>
            <li><span class="gc-workspace-step" aria-hidden="true">2</span><div><strong>Trouver un créneau</strong><p>Comparez les techniciens, disponibilités et trajets.</p></div></li>
            <li><span class="gc-workspace-step" aria-hidden="true">3</span><div><strong>Confirmer le RDV</strong><p>Vérifiez les informations avant de valider.</p></div></li>
        </ol>
        <section class="gc-card p-5" id="lab-booking-panel">
            <div class="cl-panel-title"><h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="book" />RDV à placer</h2><span class="cl-count">3 dossiers</span></div>
            <p class="cl-helper">Choisissez un dossier fictif pour essayer les propositions du calendrier.</p>
            <div id="lab-requests"></div>
        </section>
        <section class="gc-card p-5">
            <h2 class="gc-workspace-section-title mb-4 text-lg font-semibold"><x-nav-icon name="search" />Trouver un créneau</h2>
            <div class="cl-analysis">
                <div class="cl-technicians-panel">
                    <div class="cl-panel-title"><h3 class="font-semibold">Techniciens éligibles</h3><button class="gc-link text-sm" id="lab-techs-all" type="button">Tout cocher</button></div>
                    <div id="lab-technicians"></div>
                    <label class="cl-toggle"><input type="checkbox" id="lab-suggestions" checked>Afficher les propositions</label>
                    <div class="cl-legend"><span><i></i>RDV placé</span><span><i></i>Créneau proposé</span></div>
                </div>
                <div class="cl-map-panel">
                    <h3 class="font-semibold">Carte · Trajets domicile vers RDV</h3>
                    <div class="cl-map-placeholder"><x-nav-icon name="location" /><strong>Carte conservée dans la vraie prise de RDV</strong><p>Cette démo n’appelle pas Mapbox. Dans la page locale existante, la carte, les trajets et les contrôles de placement restent inchangés.</p></div>
                </div>
            </div>
        </section>
        <section class="gc-card p-5">
            <div class="cl-panel-title mb-4"><div><h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="planning" />Disponibilités visuelles</h2><p class="cl-helper">Même repérage par jour et par heure, avec des cartes plus lisibles.</p></div><button class="gc-btn-soft" type="button" id="lab-reset">Réinitialiser l’essai</button></div>
            <div class="cl-selected" id="lab-selected"></div>
            <div id="calendar-lab"></div>
        </section>
        <details class="cl-real-links gc-card p-4"><summary>Tester ce calendrier dans les pages locales existantes</summary><a href="/planner/book?calendar=native">Prendre un RDV (base locale)</a><a href="/planner/tracking?calendar=native">Les RDV (base locale)</a><p>Connexion locale requise. Les actions utilisent alors la base locale et les validations habituelles. Le serveur de démonstration isolé ne sert que cette page fictive.</p></details>
        <p class="cl-toast" id="lab-toast" role="status" hidden></p>
    </div>
    <dialog class="nc-slot-dialog cl-detail" id="lab-detail" aria-labelledby="lab-detail-title">
        <div class="cl-detail-heading"><p class="cl-eyebrow">RENDEZ-VOUS FICTIF</p><button type="button" class="gc-btn-soft" id="lab-detail-close">Fermer</button></div>
        <h2 id="lab-detail-title"></h2>
        <p id="lab-detail-subtitle"></p>
        <form id="lab-detail-form">
            <label>Technicien<select id="lab-detail-tech" required></select></label>
            <div class="cl-form-row"><label>Date<input type="date" id="lab-detail-date" required></label><label>Heure<input type="time" id="lab-detail-time" step="900" required></label></div>
            <label>Durée en minutes<input type="number" id="lab-detail-duration" min="15" max="480" step="5" required></label>
            <div class="cl-conflict" id="lab-conflict" role="alert" hidden></div>
            <label class="cl-force" id="lab-force-label" hidden><input type="checkbox" id="lab-force">Je confirme le placement malgré ce conflit (démo uniquement).</label>
            <div class="nc-dialog-actions"><button type="button" class="gc-btn-soft" id="lab-cancel-appointment">Remettre à placer</button><button type="submit" class="gc-btn-primary" id="lab-detail-save">Placer ce RDV fictif</button></div>
        </form>
        <p class="cl-helper">Simulation locale uniquement. Les règles serveur de production ne sont pas modifiées.</p>
    </dialog>
</x-layouts.app>
