export function initDossierService(root = document, browser = window) {
    root.querySelectorAll('[data-operation-service]').forEach(form => {
        const select = form.querySelector('select');
        const feedback = form.querySelector('[data-service-feedback]');
        let previous = select.value;
        form.addEventListener('submit', event => event.preventDefault());
        select.addEventListener('change', async () => {
            if (!select.value) { select.value = previous; return; }
            const payload = Object.fromEntries(new browser.FormData(form));
            select.disabled = true;
            feedback.textContent = 'Enregistrement…';
            try {
                const response = await browser.fetch(form.action, { method: 'PATCH', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': payload._token },
                    body: JSON.stringify(payload), signal: browser.AbortSignal.timeout(20000) });
                const data = await response.json();
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Modification impossible.');
                previous = String(data.service_id);
                root.querySelectorAll('[name="version"]').forEach(input => { input.value = data.version; });
                root.querySelectorAll(`[data-operation-label="${form.dataset.operation}"]`).forEach(label => { label.textContent = data.name; });
                root.querySelectorAll('[data-report-operation] option').forEach(option => {
                    if (option.value === form.dataset.operation) option.textContent = data.name;
                });
                root.querySelectorAll(`[data-appointment-editor][data-operation="${form.dataset.operation}"]`).forEach(editor => {
                    const appointment = data.appointments.find(item => String(item.id) === editor.dataset.appointmentEditor);
                    if (!appointment) return;
                    editor.elements.updated_at.value = appointment.updated_at;
                    const technicians = editor.elements.technician_id;
                    const current = technicians.value;
                    technicians.replaceChildren(...data.technicians.map(item => {
                        const option = root.createElement('option');
                        option.value = item.id; option.textContent = item.label; option.selected = String(item.id) === current;
                        return option;
                    }));
                });
                feedback.textContent = 'Fiche enregistrée. Horaires conservés.';
            } catch (error) {
                select.value = previous;
                feedback.textContent = error.name === 'TimeoutError' ? 'Délai dépassé. Rechargez la fiche pour vérifier la modification.' : error.message;
            } finally { select.disabled = false; }
        });
    });
}
