document.addEventListener('DOMContentLoaded', () => {
    renderWeekView(); // Affiche la vue semaine par défaut

    // Écoute les clics sur les boutons de navigation
    document.getElementById('prevWeek').addEventListener('click', () => changeWeek(-1));
    document.getElementById('nextWeek').addEventListener('click', () => changeWeek(1));
});

let currentWeekStart = getMonday(new Date());

function getMonday(date) {
    const day = date.getDay() || 7;
    if (day !== 1) date.setHours(-24 * (day - 1));
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function changeWeek(weekOffset) {
    currentWeekStart.setDate(currentWeekStart.getDate() + weekOffset * 7);
    renderWeekView();
}

function renderWeekView() {
    const container = document.getElementById('calendarContainer');
    container.innerHTML = ''; // Efface le contenu précédent

    // Met à jour le label pour la semaine en cours
    const startDateLabel = formatDate(currentWeekStart);
    document.getElementById('weekLabel').textContent = `Semaine du ${startDateLabel}`;

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    const hours = Array.from({ length: 14 }, (_, i) => `${String(i + 7).padStart(2, '0')}:00`);

    // En-tête pour les jours de la semaine
    const headerRow = document.createElement('div');
    headerRow.classList.add('row', 'week-header');
    const emptyCell = document.createElement('div');
    emptyCell.classList.add('cell', 'hour-cell');
    headerRow.appendChild(emptyCell);

    for (let i = 0; i < 7; i++) {
        const day = new Date(currentWeekStart.getTime() + i * 86400000);
        const dayCell = document.createElement('div');
        dayCell.classList.add('cell', 'day-cell');
        dayCell.textContent = `${daysOfWeek[i]} ${day.getDate()}/${day.getMonth() + 1}`;
        headerRow.appendChild(dayCell);
    }
    container.appendChild(headerRow);

    // Lignes pour chaque heure de 7:00 à 20:00
    hours.forEach(hour => {
        const row = document.createElement('div');
        row.classList.add('row', 'hour-row');
        
        const hourCell = document.createElement('div');
        hourCell.classList.add('cell', 'hour-cell');
        hourCell.textContent = hour;
        row.appendChild(hourCell);

        for (let i = 0; i < 7; i++) {
            const dayHourCell = document.createElement('div');
            dayHourCell.classList.add('cell', 'day-hour-cell');
            row.appendChild(dayHourCell);
        }
        container.appendChild(row);
    });
}

function formatDate(date) {
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
}