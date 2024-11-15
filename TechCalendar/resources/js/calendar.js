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
    const day = date.getDay() || 7;
    if (day !== 1) date.setHours(-24 * (day - 1));
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function changeWeek(technicianId, weekOffset) {
    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
    }

    currentWeekStarts[technicianId].setDate(currentWeekStarts[technicianId].getDate() + weekOffset * 7);
    renderWeekView(technicianId);
}

function renderWeekView(technicianId, travelTime = 0, defaultStartTime = '07:00', defaultEndTime = '20:00') {
    const container = document.getElementById(`RdvCalendarContainer-${technicianId}`);
    container.innerHTML = ''; // Efface le contenu précédent

    if (!currentWeekStarts[technicianId]) {
        currentWeekStarts[technicianId] = getMonday(new Date());
    }

    const startDateLabel = formatDate(currentWeekStarts[technicianId]);
    document.getElementById(`weekLabel-${technicianId}`).textContent = `Semaine du ${startDateLabel}`;

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    const hours = Array.from({ length: 14 }, (_, i) => `${String(i + 7).padStart(2, '0')}:00`);

    // Convertir les heures par défaut en minutes pour comparaison
    const startMinutes = timeStringToMinutes(defaultStartTime);
    const endMinutes = timeStringToMinutes(defaultEndTime);

    // En-tête pour les jours de la semaine
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

    // Lignes pour chaque heure de 7:00 à 20:00
    hours.forEach(hour => {
        const row = document.createElement('div');
        row.classList.add('row', 'hour-row');

        const hourCell = document.createElement('div');
        hourCell.classList.add('cell', 'hour-cell');
        hourCell.textContent = hour;
        row.appendChild(hourCell);

        const hourMinutes = timeStringToMinutes(hour); // Convertir l'heure en minutes

        for (let i = 0; i < 7; i++) {
            const dayHourCell = document.createElement('div');
            dayHourCell.classList.add('cell', 'day-hour-cell');

            // Vérifier si le temps est dans la plage de disponibilité
            if (hourMinutes >= startMinutes && hourMinutes < endMinutes) {
                const arrivalTime = startMinutes + travelTime; // Calculer l'heure d'arrivée
                const roundedArrivalHour = Math.ceil(arrivalTime / 60) * 60; // Arrondir à l'heure suivante en minutes

                if (hourMinutes === roundedArrivalHour) {
                    const btn = document.createElement('button');
                    btn.classList.add('btn', 'btn-success', 'btn-sm');
                    btn.textContent = 'DISPO';
                    btn.onclick = () => placeAppointment(technicianId, hour, i);
                    dayHourCell.appendChild(btn);
                }
            }

            row.appendChild(dayHourCell);
        }
        container.appendChild(row);
    });
}

function placeAppointment(technicianId, time, dayIndex) {
    if (!currentWeekStarts[technicianId]) {
        console.error(`currentWeekStarts is undefined for technicianId: ${technicianId}`);
        return;
    }

    const prestationDropdown = document.getElementById('prestationDropdown');
    const selectedPrestationId = prestationDropdown.value;
    const selectedPrestationName = prestationDropdown.options[prestationDropdown.selectedIndex].text;
    const selectedPrestationTime = prestationDropdown.options[prestationDropdown.selectedIndex].getAttribute('data-default-time');

    const startDate = new Date(currentWeekStarts[technicianId].getTime() + dayIndex * 86400000);
    if (isNaN(startDate.getTime())) {
        console.error('Invalid date calculated for overlay:', startDate);
        return;
    }
    const formattedDate = startDate.toISOString().split('T')[0];

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
                    <div class="form-group">
                        <label for="clientLastName">Nom du client</label>
                        <input type="text" id="clientLastName" class="form-control" placeholder="Nom du client" required>
                    </div>
                    <div class="form-group">
                        <label for="clientFirstName">Prénom du client</label>
                        <input type="text" id="clientFirstName" class="form-control" placeholder="Prénom du client" required>
                    </div>
                    <div class="form-group">
                        <label for="clientAddress">Adresse</label>
                        <input type="text" id="clientAddress" class="form-control" placeholder="Adresse" value="${document.getElementById('address').value}">
                    </div>
                    <div class="form-group">
                        <label for="clientPostalCode">Code postal</label>
                        <input type="text" id="clientPostalCode" class="form-control" placeholder="Code postal" value="${document.getElementById('postalCode').value}">
                    </div>
                    <div class="form-group">
                        <label for="clientCity">Ville</label>
                        <input type="text" id="clientCity" class="form-control" placeholder="Ville" value="${document.getElementById('city').value}">
                    </div>
                    <div class="form-group">
                        <label for="clientPhone">Téléphone</label>
                        <input type="tel" id="clientPhone" class="form-control" placeholder="Téléphone" required>
                    </div>
                    <div class="form-group">
                        <label for="appointmentDate">Date</label>
                        <input type="text" id="appointmentDate" class="form-control" value="${formattedDate}" readonly>
                    </div>
                    <div class="form-group">
                        <label for="startTime">Débute à</label>
                        <input type="time" id="startTime" class="form-control" value="${time}">
                    </div>
                    <div class="form-group">
                        <label for="prestation">Prestation</label>
                        <select id="prestation" class="form-control">
                            ${Array.from(prestationDropdown.options)
                                .map(option => `<option value="${option.value}" ${option.value === selectedPrestationId ? 'selected' : ''}>
                                    ${option.text}
                                </option>`)
                                .join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="duration">Durée</label>
                        <input type="text" id="duration" class="form-control" value="${selectedPrestationTime} minutes">
                    </div>
                    <div class="form-group">
                        <label for="comment">Commentaire</label>
                        <textarea id="comment" class="form-control" placeholder="Ajouter un commentaire"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="submitAppointment('${technicianId}', '${time}', '${formattedDate}')">Valider le rendez-vous</button>
                </form>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    const prestationSelect = overlay.querySelector('#prestation');
    prestationSelect.addEventListener('change', function () {
        const selectedOption = prestationSelect.options[prestationSelect.selectedIndex];
        const newDefaultTime = selectedOption.getAttribute('data-default-time');
        overlay.querySelector('#duration').value = `${newDefaultTime} minutes`;
    });

    overlay.style.display = 'flex';
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