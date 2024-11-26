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
    const day = date.getDay() || 7; // Si dimanche (0), on met à 7
    if (day !== 1) date.setDate(date.getDate() - (day - 1));
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function changeWeek(technicianId, weekOffset) {
    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
    }

    currentWeekStarts[technicianId].setDate(currentWeekStarts[technicianId].getDate() + weekOffset * 7);
    renderWeekView(technicianId);
}

let technicianAppointments = {};

function renderWeekView(technicianId) {
    const container = document.getElementById(`RdvCalendarContainer-${technicianId}`);
    container.innerHTML = '';

    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
    }

    const startDateLabel = formatDate(currentWeekStarts[technicianId]);
    document.getElementById(`weekLabel-${technicianId}`).textContent = `Semaine du ${startDateLabel}`;

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    const hours = Array.from({ length: 14 }, (_, i) => `${String(i + 7).padStart(2, '0')}:00`);

    const appointments = technicianAppointments[technicianId] || [];

    const headerRow = document.createElement('div');
    headerRow.classList.add('row', 'week-header');
    const emptyCell = document.createElement('div');
    emptyCell.classList.add('cell', 'hour-cell');
    headerRow.appendChild(emptyCell);

    for (let i = 0; i < 7; i++) {
        const day = new Date(currentWeekStarts[technicianId].getTime() + i * 86400000);
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

        for (let i = 0; i < 7; i++) {
            const dayHourCell = document.createElement('div');
            dayHourCell.classList.add('cell', 'day-hour-cell');

            const day = new Date(currentWeekStarts[technicianId].getTime() + i * 86400000).toISOString().split('T')[0];
            const hourMinutes = timeStringToMinutes(hour);

            appointments.forEach(appointment => {
                const appointmentDate = appointment.date;
                const appointmentStartMinutes = timeStringToMinutes(appointment.start_at);
                const appointmentEndMinutes = appointmentStartMinutes + appointment.duree;

                if (appointmentDate === day && hourMinutes === Math.floor(appointmentStartMinutes / 60) * 60) {
                    // Calculer la hauteur en fonction de la durée
                    const durationHours = Math.ceil(appointmentEndMinutes / 60) - Math.floor(appointmentStartMinutes / 60);
                    const button = document.createElement('button');
                    button.classList.add('btn', 'btn-primary', 'btn-sm', 'appointment-btn');
                    button.textContent = `${appointment.nom} ${appointment.prenom}`;
                    button.style.height = `${durationHours * 100}%`; // Ajuster selon la hauteur de chaque cellule
                    button.onclick = () => alert(`Rendez-vous avec ${appointment.nom} ${appointment.prenom}`);

                    dayHourCell.appendChild(button);
                    dayHourCell.style.gridRow = `span ${durationHours}`; // Étendre sur plusieurs lignes
                }
            });

            row.appendChild(dayHourCell);
        }
        container.appendChild(row);
    });
}

function placeAppointment(technicianId, travelTime, defaultStartAt) {
    // Initialiser currentWeekStarts pour ce technicien s'il n'existe pas
    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
        console.log(`currentWeekStarts initialized for technicianId: ${technicianId}`, currentWeekStarts[technicianId]);
    }

    // Récupérer la date de début de semaine
    const startDate = currentWeekStarts[technicianId];
    if (!startDate) {
        console.error(`currentWeekStarts is undefined for technicianId: ${technicianId}`);
        return;
    }

    // Le reste de la fonction reste identique
    const prestationDropdown = document.getElementById('prestationDropdown');
    const selectedPrestationId = prestationDropdown.value;
    const selectedPrestationTime = prestationDropdown.options[prestationDropdown.selectedIndex].getAttribute('data-default-time');

    const formattedDate = startDate.toISOString().split('T')[0];

    // Calculer l'heure d'arrivée
    const travelMinutes = parseInt(travelTime, 10);
    const [startHour, startMinute] = defaultStartAt.split(':').map(Number);
    const startMinutes = startHour * 60 + startMinute;
    const arrivalTimeMinutes = startMinutes + travelMinutes;

    const formattedStartTime = `${Math.floor(arrivalTimeMinutes / 60).toString().padStart(2, '0')}:${(arrivalTimeMinutes % 60).toString().padStart(2, '0')}`;


    // Création ou affichage de l'overlay
    const overlayId = `appointmentOverlay-${technicianId}`;
    let overlay = document.getElementById(overlayId);

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <button class="close-btn" onclick="closeAppointmentOverlay('${overlayId}')">&times;</button>
                <h3>Créer un rendez-vous</h3>
                <form id="appointmentForm-${technicianId}">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="clientLastName">Nom du client</label>
                            <input type="text" id="clientLastName" class="form-control" placeholder="Nom du client" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="clientFirstName">Prénom du client</label>
                            <input type="text" id="clientFirstName" class="form-control" placeholder="Prénom du client" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="clientAddress">Adresse</label>
                            <input type="text" id="clientAddress" class="form-control" placeholder="Adresse" value="${document.getElementById('address').value}">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="clientPostalCode">Code postal</label>
                            <input type="text" id="clientPostalCode" class="form-control" placeholder="Code postal" value="${document.getElementById('postalCode').value}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="clientCity">Ville</label>
                        <input type="text" id="clientCity" class="form-control" placeholder="Ville" value="${document.getElementById('city').value}">
                    </div>
                    <div class="form-group">
                        <label for="clientPhone">Téléphone</label>
                        <input type="tel" id="clientPhone" class="form-control" placeholder="Téléphone" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="appointmentDate">Date</label>
                            <input type="text" id="appointmentDate" class="form-control" value="${formattedDate}" readonly>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="startTime">Débute à</label>
                            <input type="time" id="startTime" class="form-control" value="${formattedStartTime}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="prestation">Prestation</label>
                            <select id="prestation" class="form-control">
                                ${Array.from(prestationDropdown.options)
                                    .map(option => `<option value="${option.value}" ${option.value === selectedPrestationId ? 'selected' : ''}>
                                        ${option.text}
                                    </option>`)
                                    .join('')}
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="duration">Durée</label>
                            <input type="text" id="duration" class="form-control" value="${selectedPrestationTime} minutes">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="comment">Commentaire</label>
                        <textarea id="comment" class="form-control" placeholder="Ajouter un commentaire"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary justify-center" onclick="submitAppointment('${technicianId}', '${formattedStartTime}', '${formattedDate}')">Valider le rendez-vous</button>
                </form>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    // Ajouter un écouteur pour mettre à jour la durée lors du changement de prestation
    const prestationSelect = overlay.querySelector('#prestation');
    prestationSelect.addEventListener('change', function () {
        const selectedOption = prestationSelect.options[prestationSelect.selectedIndex];
        const newDefaultTime = selectedOption.getAttribute('data-default-time');
        overlay.querySelector('#duration').value = `${newDefaultTime} minutes`;
    });

    overlay.style.display = 'flex';
}

// Fonction utilitaire pour ajouter des minutes à une heure au format HH:MM
function calculateAdjustedStartTime(baseTime, additionalMinutes) {
    const [hours, minutes] = baseTime.split(':').map(Number);
    const totalMinutes = hours * 60 + minutes + additionalMinutes;
    const adjustedHours = Math.floor(totalMinutes / 60) % 24;
    const adjustedMinutes = totalMinutes % 60;

    return `${String(adjustedHours).padStart(2, '0')}:${String(adjustedMinutes).padStart(2, '0')}`;
}

function closeAppointmentOverlay(overlayId) {
    const overlay = document.getElementById(overlayId);
    if (overlay) overlay.style.display = 'none';
}

function submitAppointment(technicianId, time, date) {
    const form = document.getElementById(`appointmentForm-${technicianId}`);
    if (!form) {
        console.error(`Le formulaire avec l'ID appointmentForm-${technicianId} n'existe pas.`);
        alert("Une erreur est survenue : formulaire introuvable.");
        return;
    }

    // Récupération sécurisée des valeurs
    const getFieldValue = (selector) => {
        const field = form.querySelector(selector);
        if (!field) {
            console.error(`Le champ ${selector} est introuvable dans le formulaire.`);
            alert(`Une erreur est survenue : le champ ${selector} est introuvable.`);
            throw new Error(`Champ introuvable : ${selector}`);
        }
        return field.value;
    };

    let appointmentData;
    try {
        appointmentData = {
            technician_id: technicianId,
            nom: getFieldValue('#clientLastName'),
            prenom: getFieldValue('#clientFirstName'),
            adresse: getFieldValue('#clientAddress'),
            code_postal: getFieldValue('#clientPostalCode'),
            ville: getFieldValue('#clientCity'),
            tel: getFieldValue('#clientPhone'),
            date: date, // La date est passée comme paramètre déjà formatée
            start_at: getFieldValue('#startTime'),
            prestation: getFieldValue('#prestation'),
            duree: parseInt(getFieldValue('#duration')),
            commentaire: getFieldValue('#comment'),
        };
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
                    console.error('Réponse HTML reçue :', text);
                    throw new Error('Erreur lors de la création du rendez-vous.');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Réponse JSON du serveur :', data);
            alert('Rendez-vous enregistré avec succès.');
            closeAppointmentOverlay(`appointmentOverlay-${technicianId}`);
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