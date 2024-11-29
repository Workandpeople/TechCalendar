document.addEventListener('DOMContentLoaded', () => {
    // Assurez-vous que l'événement est bien ajouté après la création du DOM
    document.addEventListener('change', (event) => {
        if (event.target && event.target.id === 'prestation') {
            const selectedOption = event.target.options[event.target.selectedIndex];
            const newDefaultTime = selectedOption.getAttribute('data-default-time');
            document.getElementById('duration').value = newDefaultTime
                ? `${newDefaultTime} minutes`
                : 'Durée non définie';
        }
    });
});

let currentWeekStarts = {};

function getMonday(date) {
    const day = date.getDay(); // 0 (dimanche) à 6 (samedi)
    const diff = (day === 0 ? -6 : 1) - day; // Ajuste pour que lundi soit 0
    return new Date(date.setDate(date.getDate() + diff));
}

function changeWeek(technicianId, weekOffset) {
    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
    }

    currentWeekStarts[technicianId].setDate(currentWeekStarts[technicianId].getDate() + weekOffset * 7);
    renderWeekView(technicianId);
}

let technicianAppointments = {};

function renderWeekView(technicianId, distance, time) {
    console.log(`Distance: ${distance} km, Temps: ${time} minutes`);
    const container = document.getElementById(`RdvCalendarContainer-${technicianId}`);
    container.innerHTML = '';

    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
    }

    const startDateLabel = formatDate(currentWeekStarts[technicianId]);
    document.getElementById(`weekLabel-${technicianId}`).textContent = `Semaine du ${startDateLabel}`;

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    const hours = Array.from({ length: 12 }, (_, i) => `${String(i + 8).padStart(2, '0')}:00`);

    const appointments = technicianAppointments[technicianId];

    // Création de l'en-tête pour les jours
    const headerRow = document.createElement('div');
    headerRow.classList.add('row', 'week-header');
    const emptyCell = document.createElement('div');
    emptyCell.classList.add('cell', 'hour-cell');
    headerRow.appendChild(emptyCell);

    for (let i = 0; i < 5; i++) {
        const day = new Date(currentWeekStarts[technicianId].getTime() + i * 86400000);
        const dayCell = document.createElement('div');
        dayCell.classList.add('cell', 'day-cell');
        dayCell.textContent = `${daysOfWeek[i]} ${day.getDate()}/${day.getMonth() + 1}`;
        dayCell.dataset.date = day.toISOString().split('T')[0];
        headerRow.appendChild(dayCell);
    }
    container.appendChild(headerRow);

    // Création des lignes horaires
    hours.forEach(hour => {
        const row = document.createElement('div');
        row.classList.add('row', 'hour-row');

        const hourCell = document.createElement('div');
        hourCell.classList.add('cell', 'hour-cell');
        hourCell.textContent = hour;
        row.appendChild(hourCell);

        for (let i = 0; i < 5; i++) {
            const day = new Date(currentWeekStarts[technicianId].getTime() + i * 86400000);

            const dayHourCell = document.createElement('div');
            dayHourCell.classList.add('cell', 'day-hour-cell');
            dayHourCell.dataset.date = day.toISOString().split('T')[0];
            dayHourCell.dataset.hour = hour;

            const currentHourMinutes = timeStringToMinutes(hour);

            // Gérer les rendez-vous existants
            appointments.forEach(appointment => {
                const appointmentDate = appointment.date; // Format YYYY-MM-DD
                const cellDate = dayHourCell.dataset.date; // Format YYYY-MM-DD
                const appointmentStartMinutes = timeStringToMinutes(appointment.start_at);
                const appointmentEndMinutes = appointmentStartMinutes + appointment.duree;

                if (
                    appointmentDate === cellDate &&
                    currentHourMinutes === Math.floor(appointmentStartMinutes / 60) * 60
                ) {
                    const minutesPastHour = appointmentStartMinutes % 60;
                    const durationPercentage = (appointment.duree * 100) / 60;
                    const marginTopPercentage = ((((minutesPastHour * 100) / 60) * 37.5) / 100) / 16;

                    const button = document.createElement('button');
                    button.classList.add('btn', 'btn-primary', 'btn-sm', 'appointment-btn');
                    button.textContent = `${appointment.nom} ${appointment.prenom}`;
                    button.style.height = `${durationPercentage}%`;
                    button.style.marginTop = `${marginTopPercentage}rem`;

                    button.onclick = () => openAppointmentDetailsOverlay(appointment.id);

                    dayHourCell.appendChild(button);
                }
            });

            // Ajouter un écouteur de double-clic pour ouvrir l'overlay
            dayHourCell.addEventListener('dblclick', () => {
                const date = dayHourCell.dataset.date;
                const hour = dayHourCell.dataset.hour;
            
                // Récupérez les informations pour le technicien actuel
                const selectedTechnician = { id: technicianId, name: techniciansData[technicianId]?.name || "Technicien inconnu" };
                
                openAppointmentOverlay(date, hour, [selectedTechnician], distance, time);
            });

            row.appendChild(dayHourCell);
        }
        container.appendChild(row);
    });
}

function openAppointmentDetailsOverlay(appointmentId) {
    const overlayId = 'appointmentDetailsOverlay';
    let overlay = document.getElementById(overlayId);

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <button class="close-btn" onclick="closeAppointmentDetailsOverlay()">&times;</button>
                <h3>Détails du rendez-vous</h3>
                <div id="appointmentDetailsContainer">
                    <p><strong>Nom du client :</strong> <span id="clientName"></span></p>
                    <p><strong>Téléphone :</strong> <span id="clientPhone"></span></p>
                    <p><strong>Adresse :</strong> <span id="clientAddress"></span></p>
                    <p><strong>Date :</strong> <span id="appointmentDate"></span></p>
                    <p><strong>Heure de début :</strong> <span id="startTime"></span></p>
                    <p><strong>Durée :</strong> <span id="duration"></span> minutes</p>
                    <p><strong>Commentaire :</strong> <span id="comment"></span></p>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    // Appeler le backend pour récupérer les détails du rendez-vous
    fetch(`/rendezvous/${appointmentId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur lors de la récupération des détails du rendez-vous.');
            }
            return response.json();
        })
        .then(data => {
            document.getElementById('clientName').textContent = `${data.nom} ${data.prenom}`;
            document.getElementById('clientPhone').textContent = data.tel || 'Non renseigné';
            document.getElementById('clientAddress').textContent = `${data.adresse}, ${data.code_postal} ${data.ville}`;
            document.getElementById('appointmentDate').textContent = data.date || 'Non renseignée';
            document.getElementById('startTime').textContent = data.start_at || 'Non renseignée';
            document.getElementById('duration').textContent = data.duree || 'Non renseignée';
            document.getElementById('comment').textContent = data.commentaire || 'Non renseigné';
            overlay.style.display = 'flex';
        })
        .catch(error => {
            console.error(error);
            alert('Impossible de charger les détails du rendez-vous.');
        });
}

// Fonction pour fermer l'overlay
function closeAppointmentDetailsOverlay() {
    const overlay = document.getElementById('appointmentDetailsOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

// Fonction utilitaire pour ajouter des minutes à une heure au format HH:MM
function calculateAdjustedStartTime(baseTime, additionalMinutes) {
    const [hours, minutes] = baseTime.split(':').map(Number);
    const totalMinutes = hours * 60 + minutes + additionalMinutes;
    const adjustedHours = Math.floor(totalMinutes / 60) % 24;
    const adjustedMinutes = totalMinutes % 60;

    return `${String(adjustedHours).padStart(2, '0')}:${String(adjustedMinutes).padStart(2, '0')}`;
}

function isValidUUID(uuid) {
    const regex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    return regex.test(uuid);
}

function submitAppointment() {
    const form = document.getElementById('appointmentForm');
    if (!form) {
        console.error("Le formulaire de rendez-vous est introuvable.");
        alert("Une erreur est survenue : formulaire introuvable.");
        return;
    }

    const getFieldValue = (selector) => {
        const field = form.querySelector(selector);
        if (!field) {
            console.error(`Le champ ${selector} est introuvable.`);
            alert(`Une erreur est survenue : le champ ${selector} est introuvable.`);
            throw new Error(`Champ introuvable : ${selector}`);
        }
        return field.value.trim();
    };

    let appointmentData;
    try {
        const technicianSelect = document.getElementById('technician');
        if (!technicianSelect || !technicianSelect.value) {
            console.error("Aucun technicien sélectionné ou élément introuvable.");
            alert("Veuillez sélectionner un technicien.");
            return;
        }
        const technicianId = technicianSelect.value;
        console.log("Technician ID récupéré :", technicianId);

        const prestationDropdown = document.getElementById('prestationDropdown');
        const prestationDefaultTime = prestationDropdown.selectedOptions[0]?.getAttribute('data-default-time');
        console.log("Durée par défaut récupérée :", prestationDefaultTime);

        if (!prestationDefaultTime) {
            console.error("La durée associée à la prestation est introuvable.");
            alert("Une erreur est survenue : durée de la prestation introuvable.");
            return;
        }

        appointmentData = {
            technician_id: technicianId,
            nom: getFieldValue('#clientLastName'),
            prenom: getFieldValue('#clientFirstName'),
            adresse: getFieldValue('#clientAddress'),
            code_postal: getFieldValue('#clientPostalCode'),
            ville: getFieldValue('#clientCity'),
            tel: getFieldValue('#clientPhone'),
            date: getFieldValue('#appointmentDate'),
            start_at: getFieldValue('#startTime'),
            prestation: prestationDropdown.value,
            duree: parseInt(prestationDefaultTime, 10),
            commentaire: getFieldValue('#comment'),
            traject_time: parseInt(getFieldValue('#trajectTime'), 10), // Temps de trajet
            traject_distance: parseFloat(getFieldValue('#trajectDistance')) // Distance de trajet
        };

        console.log('Données du rendez-vous :', appointmentData);
    } catch (error) {
        console.error("Erreur lors de la récupération des données du formulaire :", error);
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    fetch('/appointments', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(appointmentData),
    })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    console.error('Réponse du serveur :', text);
                    throw new Error('Erreur lors de la création du rendez-vous.');
                });
            }
            return response.json();
        })
        .then(data => {
            alert('Rendez-vous enregistré avec succès.');
            console.log('Rendez-vous créé :', data);
            closeAppointmentOverlay();
        })
        .catch(error => {
            console.error('Erreur détectée :', error);
            alert('Une erreur est survenue lors de la création du rendez-vous.');
        });
}

function timeStringToMinutes(timeString) {
    const [hours, minutes] = timeString.split(':').map(Number);
    return hours * 60 + minutes;
}

function formatDate(date) {
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
}

let currentTechWeekStart = getMonday(new Date()); // Initialisation à la semaine courante

function changeTechWeek(offset) {
    currentTechWeekStart.setDate(currentTechWeekStart.getDate() + offset * 7);

    // Mise à jour de l'étiquette de la semaine
    const weekLabelTech = document.getElementById('weekLabelTech');
    if (weekLabelTech) {
        const weekEnd = new Date(currentTechWeekStart.getTime() + 4 * 86400000); // Lundi à Vendredi
        weekLabelTech.textContent = `Semaine du ${formatDate(currentTechWeekStart)} au ${formatDate(weekEnd)}`;
    }

    // Mettre à jour le calendrier avec les techniciens cochés
    updateTechCalendar();
}

async function updateTechCalendar() {
    const checkedTechnicians = Array.from(document.querySelectorAll('.tech-checkbox:checked'))
        .map(checkbox => checkbox.closest('.tech-item').dataset.id);

    console.log('Techniciens cochés :', checkedTechnicians);

    if (checkedTechnicians.length === 0) {
        document.getElementById('techCalendarContainer').innerHTML = '<p>Aucun technicien sélectionné.</p>';
        return;
    }

    try {
        const response = await fetch('/get-technician-appointments', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
            body: JSON.stringify({
                technician_ids: checkedTechnicians,
                week_start: currentTechWeekStart.toISOString(),
            }),
        });

        if (!response.ok) {
            throw new Error(`Erreur réseau : ${response.status}`);
        }

        const data = await response.json();

        console.log('Rendez-vous récupérés :', data.appointments);

        const technicians = data.appointments.reduce((result, appointment) => {
            const techId = appointment.technician_id;
        
            if (!result[techId]) {
                result[techId] = {
                    id: techId,
                    name: `${appointment.technician.prenom} ${appointment.technician.nom}`,
                    appointments: [],
                };
            }
        
            result[techId].appointments.push({
                id: appointment.id, // Ajoutez explicitement l'ID ici
                date: appointment.date,
                start_at: appointment.start_at,
                duree: appointment.duree,
                nom: appointment.nom,
            });
        
            return result;
        }, {});

        renderTechCalendar(Object.values(technicians), document.getElementById('techCalendarContainer'));
    } catch (error) {
        console.error('Erreur lors de la récupération des rendez-vous :', error);
        alert('Impossible de charger les rendez-vous.');
    }
}

function renderTechCalendar(technicians, container) {
    container.innerHTML = '';
    const colors = ['#FF5733', '#33FF57', '#3357FF', '#FF33A8', '#FFBD33'];

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    const hours = Array.from({ length: 12 }, (_, i) => `${String(i + 8).padStart(2, '0')}:00`);

    const appointmentsByDate = {};

    // Début et fin de la semaine courante
    const weekStart = currentTechWeekStart;
    const weekEnd = new Date(weekStart.getTime() + 4 * 86400000); // 5 jours de lundi à vendredi

    // Ajouter une légende pour les techniciens
    const legendContainer = document.createElement('div');
    legendContainer.classList.add('legend-container', 'd-flex', 'align-items-center', 'mb-3');
    legendContainer.style.flexWrap = 'wrap';
    legendContainer.style.gap = '10px';

    technicians.forEach((tech, index) => {
        const techColor = colors[index % colors.length];

        const legendItem = document.createElement('div');
        legendItem.classList.add('legend-item', 'd-flex', 'align-items-center');

        const colorBox = document.createElement('div');
        colorBox.style.backgroundColor = techColor;
        colorBox.style.width = '20px';
        colorBox.style.height = '20px';
        colorBox.style.borderRadius = '3px';
        colorBox.style.marginRight = '8px';

        const techName = document.createElement('span');
        techName.textContent = tech.name;

        legendItem.appendChild(colorBox);
        legendItem.appendChild(techName);
        legendContainer.appendChild(legendItem);
    });

    container.appendChild(legendContainer);

    // Filtrer et organiser les rendez-vous par date dans la semaine actuelle
    technicians.forEach((tech, index) => {
        if (!tech || !tech.appointments) return;

        const techColor = colors[index % colors.length];

        tech.appointments.forEach(appointment => {
            const appointmentDate = new Date(appointment.date);

            if (appointmentDate >= weekStart && appointmentDate <= weekEnd) {
                const startMinutes = timeStringToMinutes(appointment.start_at);
                const endMinutes = startMinutes + appointment.duree;
                const dateKey = appointment.date;

                if (!appointmentsByDate[dateKey]) {
                    appointmentsByDate[dateKey] = [];
                }
                appointmentsByDate[dateKey].push({
                    ...appointment,
                    id: appointment.id, // Assurez-vous que l'ID est inclus
                    color: techColor,
                    startMinutes,
                    endMinutes,
                });
            }
        });
    });

    // Création de l'en-tête
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

    // Générer les lignes horaires
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

            const appointments = appointmentsByDate[dayKey]?.filter(appt => {
                const startHour = Math.floor(appt.startMinutes / 60);
                return `${String(startHour).padStart(2, '0')}:00` === hour;
            });

            if (appointments && appointments.length) {
                appointments.forEach(appointment => {
                    const minutesPastHour = timeStringToMinutes(appointment.start_at) % 60;
                    const durationPercentage = (appointment.duree * 100) / 60;
                    const marginTopPercentage = ((((minutesPastHour * 100) / 60) * 37.5) / 100) / 16;

                    const button = document.createElement('button');
                    button.classList.add('btn', 'btn-sm', 'appointment-btn');
                    button.style.backgroundColor = appointment.color;
                    button.style.height = `${durationPercentage}%`;
                    button.style.marginTop = `${marginTopPercentage}rem`;

                    button.textContent = `${appointment.nom}`;
                    console.log('Appointment Data:', appointment);
                    button.onclick = () => openAppointmentDetailsOverlay(appointment.id); // Utilise appointment.id

                    cell.appendChild(button);
                });
            }

            row.appendChild(cell);
        }
        container.appendChild(row);
    });
}