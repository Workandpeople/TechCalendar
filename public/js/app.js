function showLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    overlay.classList.remove('d-none');
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    overlay.classList.add('d-none');
}

function showCalendarLoading() {
    const calendarOverlay = document.getElementById('calendarLoadingOverlay');
    calendarOverlay.classList.remove('d-none');
}

function hideCalendarLoading() {
    const calendarOverlay = document.getElementById('calendarLoadingOverlay');
    calendarOverlay.classList.add('d-none');
}
