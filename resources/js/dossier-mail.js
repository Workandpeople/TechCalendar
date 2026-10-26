export function initDossierMail(root = document, browser = window) {
    root.querySelectorAll('[data-dossier-mail]').forEach(form => {
        const templates = JSON.parse(form.dataset.mailTemplates || '[]');
        const preview = form.querySelector('[data-mail-preview]');
        const feedback = form.querySelector('[data-mail-feedback]');
        let controller;
        const invalidate = () => { const previous = controller; controller = null; previous?.abort(); preview.hidden = true; preview.removeAttribute('srcdoc'); };
        form.addEventListener('input', invalidate);
        form.elements.mail_template_id.addEventListener('change', () => {
            invalidate();
            const template = templates.find(t => String(t.id) === form.elements.mail_template_id.value);
            if (!template) return;
            form.elements.subject.value = template.subject;
            form.elements.markdown_body.value = template.markdown_body;
        });
        form.querySelector('[data-mail-preview-button]').addEventListener('click', async () => {
            if (!form.reportValidity()) return;
            invalidate();
            controller = new AbortController();
            const current = controller;
            const timer = browser.setTimeout(() => current.abort(), 20000);
            feedback.textContent = 'Préparation de l’aperçu…';
            try {
                const response = await browser.fetch(form.dataset.previewUrl, { method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json' }, body: new browser.FormData(form), signal: current.signal });
                const data = await response.json();
                if (current !== controller) return;
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Aperçu indisponible.');
                preview.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data: https:;">' + data.html;
                preview.hidden = false;
                feedback.textContent = data.subject;
            } catch (error) { if (current === controller) feedback.textContent = error.name === 'AbortError' ? 'Aperçu annulé ou délai dépassé.' : error.message; }
            finally { browser.clearTimeout(timer); }
        });
        form.addEventListener('submit', () => {
            form.querySelector('[type="submit"]').disabled = true;
            feedback.textContent = 'Enregistrement de la demande…';
        });
    });
}
