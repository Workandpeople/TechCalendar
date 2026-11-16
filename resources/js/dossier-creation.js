export function initDossierCreation(root = document, browser = window) {
    root.querySelectorAll('[data-dossier-creation]').forEach(form => {
        const override = form.querySelector('[name="force_duplicate"]');
        if (!override || form.dataset.duplicateConfirmationReady) return;
        form.dataset.duplicateConfirmationReady = 'true';
        form.addEventListener('submit', event => {
            if (event.defaultPrevented || !override.checked || !form.reportValidity()) return;
            if (!browser.confirm('Confirmer la création même si un dossier existe déjà pour cette fiche CEE avec le même téléphone ou la même adresse ? Cette dérogation admin sera enregistrée dans l’historique.')) {
                event.preventDefault();
            }
        });
    });
}
