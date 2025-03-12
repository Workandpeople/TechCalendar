function showLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    overlay.classList.remove('d-none');
    console.log('Loading overlay shown');
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    overlay.classList.add('d-none');
    console.log('Loading overlay hidden');
}

function showCalendarLoading() {
    const calendarOverlay = document.getElementById('calendarLoadingOverlay');
    calendarOverlay.classList.remove('d-none');
    console.log('Calendar loading overlay shown');
}

function hideCalendarLoading() {
    const calendarOverlay = document.getElementById('calendarLoadingOverlay');
    calendarOverlay.classList.add('d-none');
    console.log('Calendar loading overlay hidden');
}
