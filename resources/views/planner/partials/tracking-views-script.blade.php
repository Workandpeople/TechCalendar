const trackingListPanel = document.getElementById('tracking-list-panel');
if (trackingListPanel) {
    const calendarPanel = document.getElementById('tracking-calendar-panel');
    const viewKey = 'techcalendar.rdv.view.{{ auth()->id() }}';
    const setView = (view) => {
        const list = view === 'list';
        trackingListPanel.classList.toggle('hidden', !list);
        calendarPanel.classList.toggle('hidden', list);
        ['calendar', 'list'].forEach((name) => {
            const button = document.getElementById(`tracking-view-${name}`);
            button.setAttribute('aria-pressed', String(name === view));
            button.className = name === view ? 'gc-btn-primary' : 'gc-btn-soft';
        });
        try { localStorage.setItem(viewKey, view); } catch {}
        if (list) runTrackingAppointmentSearch();
        else requestAnimationFrame(() => trackingCalendar?.updateSize());
    };
    document.getElementById('tracking-view-calendar').addEventListener('click', () => setView('calendar'));
    document.getElementById('tracking-view-list').addEventListener('click', () => setView('list'));
    document.getElementById('tracking-list-previous').addEventListener('click', () => runTrackingAppointmentSearch(Math.max(1, trackingSearchPage - 1)));
    document.getElementById('tracking-list-next').addEventListener('click', () => runTrackingAppointmentSearch(trackingSearchPage + 1));
    [trackingServiceFilter, trackingStatusFilter, ...technicianCheckboxes].forEach((field) => field?.addEventListener('change', () => {
        if (!trackingListPanel.classList.contains('hidden')) runTrackingAppointmentSearch();
    }));
    trackingSearchReset?.addEventListener('click', () => runTrackingAppointmentSearch());
    trackingResetFilters?.addEventListener('click', () => { if (!trackingListPanel.classList.contains('hidden')) runTrackingAppointmentSearch(); });
    let view = 'calendar';
    try { view = localStorage.getItem(viewKey) || view; } catch {}
    setView(view === 'list' ? 'list' : 'calendar');
}
const trackingEditModal = document.getElementById('tracking-edit-modal');
const refreshTrackingEditTechnicians = () => {
    const serviceSelect = document.getElementById('tracking_edit_service_id');
    const technicianSelect = document.getElementById('tracking_edit_technician_id');
    if (!serviceSelect || !technicianSelect) return;
    const current = trackingCurrentDetailEvent?.extendedProps || {};
    Array.from(technicianSelect.options).forEach((option) => {
        const keepCurrent = option.value === String(current.technician_id) && serviceSelect.value === String(current.service_id);
        option.disabled = !!option.value && !keepCurrent && !option.dataset.serviceIds?.split(',').includes(serviceSelect.value);
    });
    if (technicianSelect.selectedOptions[0]?.disabled) technicianSelect.value = '';
    window.TechCalendarForms?.refresh(trackingDetailsForm);
};
document.getElementById('tracking_edit_service_id')?.addEventListener('change', refreshTrackingEditTechnicians);
const closeTrackingEdit = () => {
    if (!trackingEditModal || trackingEditModal.classList.contains('hidden')) return;
    trackingEditModal.classList.add('hidden');
    trackingAppointmentModal.classList.remove('hidden');
    document.getElementById('tracking-open-edit')?.focus();
};
document.getElementById('tracking-open-edit')?.addEventListener('click', () => {
    if (!trackingCurrentDetailEvent || trackingCurrentDetailEvent.extendedProps.can_edit === false) return;
    trackingAppointmentModal.classList.add('hidden');
    trackingEditModal.classList.remove('hidden');
    refreshTrackingEditTechnicians();
    document.getElementById('tracking_edit_customer_first_name').focus();
});
document.querySelectorAll('[data-tracking-edit-close]').forEach((button) => button.addEventListener('click', closeTrackingEdit));
trackingEditModal?.addEventListener('click', (event) => { if (event.target === trackingEditModal) closeTrackingEdit(); });
trackingEditModal?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { event.stopPropagation(); closeTrackingEdit(); }
});
