<dialog class="gc-dossier-dialog gc-quick-reschedule" data-quick-reschedule-dialog aria-labelledby="quick-reschedule-title">
    <header>
        <h2 id="quick-reschedule-title">Replacer rapidement le RDV</h2>
        <button type="button" class="gc-btn-secondary" data-reschedule-close>Fermer</button>
    </header>
    <div class="gc-dossier-dialog-body">
        <p data-reschedule-summary class="mb-3 text-sm font-semibold"></p>
        <p data-reschedule-address class="mb-4 text-sm text-slate-600"></p>
        <p data-reschedule-feedback role="status" aria-live="polite" class="mb-3 text-sm"></p>
        <form data-reschedule-form hidden>
            <fieldset class="space-y-4" data-reschedule-fields>
                <div class="grid grid-cols-2 gap-3">
                    <label class="gc-label">Date<input class="gc-input mt-1" type="date" name="date" required></label>
                    <label class="gc-label">Heure (Paris)<input class="gc-input mt-1" type="time" name="time" required></label>
                </div>
                <label class="gc-label block">Technicien<select name="technician_id" class="gc-input mt-1" required></select></label>
                <label class="gc-label block">Durée (minutes)<input class="gc-input mt-1" type="number" name="duration_minutes" min="5" max="1440" required></label>
                <footer class="gc-dialog-actions">
                    <button type="button" class="gc-btn-secondary" data-reschedule-close>Annuler</button>
                    <button type="submit" class="gc-btn-primary">Replacer le RDV</button>
                </footer>
            </fieldset>
        </form>
        <a data-reschedule-result class="gc-link text-sm" hidden>Voir le RDV dans le planning</a>
    </div>
</dialog>
