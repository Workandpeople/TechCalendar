export function screenshotError(files) {
    if (files.length > 3) return 'Ajoutez au maximum 3 captures.';
    if (files.some(file => !['image/png', 'image/jpeg', 'image/webp'].includes(file.type))) return 'Utilisez des captures PNG, JPEG ou WebP.';
    if (files.some(file => file.size > 4 * 1024 * 1024)) return 'Chaque capture doit faire 4 Mo maximum.';
    return '';
}

export function newFeedbackId(crypto) {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function initBetaFeedback(root = document, browser = window) {
    root.querySelectorAll('[data-beta-feedback]').forEach(widget => {
        if (widget.dataset.initialized) return;
        widget.dataset.initialized = '1';
        const trigger = widget.querySelector('[data-beta-open]');
        const dialog = widget.querySelector('dialog');
        const form = widget.querySelector('[data-beta-form]');
        const files = widget.querySelector('[data-beta-files]');
        const previews = widget.querySelector('[data-beta-previews]');
        const status = widget.querySelector('[data-beta-status]');
        const submit = widget.querySelector('[data-beta-submit]');
        const fields = widget.querySelector('[data-beta-fields]');
        let urls = [], sending = false, lastPayload = null;
        const message = (text, error = false) => { status.textContent = text; status.dataset.error = String(error); };
        const clearPreviews = () => { urls.forEach(url => browser.URL.revokeObjectURL(url)); urls = []; previews.replaceChildren(); };
        const renderPreviews = () => {
            clearPreviews();
            const selected = Array.from(files.files || []);
            const error = screenshotError(selected);
            files.setCustomValidity(error);
            if (error) { message(error, true); return; }
            for (const [index, file] of selected.entries()) {
                const figure = root.createElement('figure');
                const image = root.createElement('img');
                const caption = root.createElement('figcaption');
                const url = browser.URL.createObjectURL(file);
                urls.push(url);
                image.src = url;
                image.alt = `Capture ${index + 1}`;
                caption.textContent = file.name;
                figure.append(image, caption);
                previews.append(figure);
            }
        };
        trigger.addEventListener('click', () => {
            form.elements.page_path.value = browser.location.pathname;
            form.elements.viewport.value = `${browser.innerWidth}x${browser.innerHeight}`;
            renderPreviews();
            if (!dialog.open) dialog.showModal();
            dialog.querySelector('h2').focus();
        });
        widget.querySelectorAll('[data-beta-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', () => { clearPreviews(); trigger.focus(); });
        files.addEventListener('change', renderPreviews);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (sending) return;
            const selected = Array.from(files.files || []);
            const error = screenshotError(selected);
            files.setCustomValidity(error);
            if (error || !form.reportValidity()) { message(error || 'Vérifiez les champs du formulaire.', true); return; }
            const signature = JSON.stringify([form.elements.kind.value, form.elements.title.value, form.elements.message.value,
                form.elements.page_path.value, selected.map(file => [file.name, file.size, file.lastModified])]);
            if (lastPayload && lastPayload !== signature) form.elements.submission_id.value = newFeedbackId(browser.crypto);
            lastPayload = signature;
            const body = new browser.FormData(form);
            const controller = new browser.AbortController();
            const timer = browser.setTimeout(() => controller.abort(), 180000);
            sending = true;
            fields.disabled = submit.disabled = true;
            submit.textContent = 'Transmission…';
            message('Envoi de votre retour et des captures…');
            try {
                const response = await browser.fetch(form.action, { method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json' }, body, signal: controller.signal });
                const data = await response.json().catch(() => null);
                if (!response.ok || !data) {
                    const explanation = [401, 419].includes(response.status) ? 'Votre session a expiré. Rechargez la page avant de réessayer.'
                        : data?.errors ? Object.values(data.errors).flat()[0]
                            : (response.status >= 500 && !data?.reference ? 'Le service de signalement est momentanément indisponible.' : data?.message);
                    throw new Error((explanation || (response.status === 419 ? 'Votre session a expiré. Rechargez la page avant de réessayer.' : 'La transmission est indisponible. Votre formulaire est conservé.'))
                        + (data?.reference ? ` Référence : ${data.reference}` : ''));
                }
                form.reset();
                form.elements.submission_id.value = newFeedbackId(browser.crypto);
                form.elements.page_path.value = browser.location.pathname;
                form.elements.viewport.value = `${browser.innerWidth}x${browser.innerHeight}`;
                lastPayload = null;
                clearPreviews();
                message(`${data.message} Référence : ${data.reference}`);
            } catch (failure) {
                message(failure.name === 'AbortError' ? 'La transmission prend trop de temps. Vous pouvez réessayer sans créer de doublon.'
                    : failure.name === 'TypeError' ? 'Connexion interrompue. Vos informations sont conservées, vous pouvez réessayer.'
                    : failure.message || 'Connexion interrompue. Vous pouvez réessayer.', true);
            } finally {
                browser.clearTimeout(timer);
                sending = false;
                fields.disabled = submit.disabled = false;
                submit.textContent = 'Envoyer mon retour';
            }
        });
    });
}
