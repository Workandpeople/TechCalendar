let onlyTechWeekStart = new Date();

function onlyTechChangeWeek(offset) {
    onlyTechWeekStart.setDate(onlyTechWeekStart.getDate() + offset * 7);

    const weekLabel = document.getElementById('onlyTechWeekLabelTech');
    if (weekLabel) {
        const weekEnd = new Date(onlyTechWeekStart.getTime() + 4 * 86400000);
        weekLabel.textContent = `Semaine du ${onlyTechFormatDate(onlyTechWeekStart)} au ${onlyTechFormatDate(weekEnd)}`;
    }

    onlyTechUpdateCalendar();
}

async function onlyTechUpdateCalendar() {
    try {
        const response = await fetch('/get-user-appointments', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
            body: JSON.stringify({
                week_start: onlyTechWeekStart.toISOString(),
            }),
        });

        if (!response.ok) {
            throw new Error(`Erreur réseau : ${response.status}`);
        }

        const data = await response.json();

        console.log('Rendez-vous récupérés :', data.appointments);

        onlyTechRenderTechCalendar(data.appointments, document.getElementById('techCalendarContainer'));
    } catch (error) {
        console.error('Erreur lors de la récupération des rendez-vous :', error);
    }
}

function onlyTechFormatDate(date) {
    const options = { day: '2-digit', month: '2-digit', year: 'numeric' };
    return date.toLocaleDateString('fr-FR', options);
}

function onlyTechRenderTechCalendar(appointments, container) {
    container.innerHTML = '';
    const colors = ['#FF5733', '#33FF57', '#3357FF', '#FF33A8', '#FFBD33'];

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    const hours = Array.from({ length: 12 }, (_, i) => `${String(i + 8).padStart(2, '0')}:00`);

    const appointmentsByDate = {};

    const weekStart = onlyTechWeekStart;
    const weekEnd = new Date(weekStart.getTime() + 4 * 86400000);

    appointments.forEach(appointment => {
        const dateKey = appointment.date;
        if (!appointmentsByDate[dateKey]) {
            appointmentsByDate[dateKey] = [];
        }
        appointmentsByDate[dateKey].push(appointment);
    });

    const headerRow = document.createElement('div');
    headerRow.classList.add('row', 'week-header');
    const emptyCell = document.createElement('div');
    emptyCell.classList.add('cell', 'hour-cell');
    headerRow.appendChild(emptyCell);

    for (let i = 0; i < 5; i++) {
        const day = new Date(weekStart.getTime() + i * 86400000);
        const dayCell = document.createElement('div');
        dayCell.classList.add('cell', 'day-cell');
        dayCell.textContent = `${daysOfWeek[i]} ${day.getDate()}/${day.getMonth() + 1}`;
        headerRow.appendChild(dayCell);
    }
    container.appendChild(headerRow);

    hours.forEach(hour => {
        const row = document.createElement('div');
        row.classList.add('row', 'hour-row');
    
        const hourCell = document.createElement('div');
        hourCell.classList.add('cell', 'hour-cell');
        hourCell.textContent = hour;
        row.appendChild(hourCell);
    
        for (let i = 0; i < 5; i++) {
            const day = new Date(weekStart.getTime() + i * 86400000);
            const dayKey = day.toISOString().split('T')[0];
    
            const cell = document.createElement('div');
            cell.classList.add('cell', 'day-hour-cell');
            cell.style.position = 'relative';
    
            const dayAppointments = appointmentsByDate[dayKey]?.filter(appt => {
                const startMinutes = onlyTechTimeStringToMinutes(appt.start_at);
                const startHour = Math.floor(startMinutes / 60);
                return `${String(startHour).padStart(2, '0')}:00` === hour;
            });
    
            if (dayAppointments && dayAppointments.length) {
                dayAppointments.forEach(appointment => {
                    const startMinutes = onlyTechTimeStringToMinutes(appointment.start_at);
                    const minutesPastHour = startMinutes % 60;
                    const durationPercentage = (appointment.duree * 100) / 60;
    
                    const button = document.createElement('button');
                    button.classList.add('btn', 'btn-sm', 'appointment-btn');
                    button.style.backgroundColor = colors[0]; // Ajustez pour assigner des couleurs dynamiques
                    button.style.height = `${durationPercentage}%`;
                    button.style.marginTop = `${(minutesPastHour * 100) / 60}%`;
    
                    button.textContent = appointment.nom;
                    button.onclick = () => openAppointmentDetailsOverlay(appointment.id);
    
                    cell.appendChild(button);
                });
            }
    
            row.appendChild(cell);
        }
        container.appendChild(row);
    });
}

function onlyTechTimeStringToMinutes(timeString) {
    const [hours, minutes] = timeString.split(':').map(Number);
    return hours * 60 + minutes;
}

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', () => {
    // Vérifier si le conteneur spécifique à la page Dashboard existe
    const dashboardContainer = document.getElementById('dashboardCalendarContainer');
    if (dashboardContainer) {
        // Définir la semaine en cours
        const weekLabel = document.getElementById('onlyTechWeekLabelTech');
        if (weekLabel) {
            const weekEnd = new Date(onlyTechWeekStart.getTime() + 4 * 86400000);
            weekLabel.textContent = `Semaine du ${onlyTechFormatDate(onlyTechWeekStart)} au ${onlyTechFormatDate(weekEnd)}`;
        }

        // Mettre à jour le calendrier pour afficher la semaine en cours
        onlyTechUpdateCalendar();
    }
});