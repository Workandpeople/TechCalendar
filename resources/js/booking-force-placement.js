export function confirmForcedPlacement(details, root = document) {
    const dialog = root.querySelector('#booking-force-confirmation');
    if (!dialog || dialog.open || typeof dialog.showModal !== 'function') return Promise.resolve(false);
    const accepted = dialog.querySelector('[data-force-accepted]');
    accepted.checked = false;
    dialog.returnValue = '';
    for (const field of ['technician', 'date', 'customer', 'address']) {
        dialog.querySelector(`[data-force-${field}]`).textContent = String(details[field] || 'Non renseigné');
    }
    const previousFocus = root.activeElement;
    return new Promise(resolve => {
        dialog.addEventListener('close', () => {
            const confirmed = dialog.returnValue === 'confirm' && accepted.checked;
            accepted.checked = false;
            previousFocus?.focus?.();
            resolve(confirmed);
        }, { once: true });
        dialog.showModal();
        dialog.querySelector('[value="cancel"]').focus();
    });
}
