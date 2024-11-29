document.addEventListener('DOMContentLoaded', () => {
    // Filtrage dynamique des prestations par début du mot
    const prestationSearchInput = document.getElementById('prestationSearch');
    if (prestationSearchInput) {
        prestationSearchInput.addEventListener('input', filterPrestations);
    }

    // Mettre à jour les champs 'type' et 'defaultTime' lors de la sélection d'une prestation
    const prestationDropdown = document.getElementById('prestationDropdown');
    if (prestationDropdown) {
        prestationDropdown.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const type = selectedOption.getAttribute('data-type');
            const defaultTime = selectedOption.getAttribute('data-default-time');

            document.getElementById('prestationType').value = type;
            document.getElementById('defaultTime').value = defaultTime;
        });

        // Déclencher l'événement change au chargement pour initialiser les champs
        prestationDropdown.dispatchEvent(new Event('change'));
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const searchField = document.getElementById('searchTechnicians');
    const technicianList = document.querySelectorAll('.tech-item');

    searchField.addEventListener('input', () => {
        const searchValue = searchField.value.toLowerCase().trim();

        technicianList.forEach(item => {
            const name = item.dataset.name.toLowerCase();
            const department = item.dataset.department;

            // Ajouter ou retirer la classe 'hidden' en fonction du nom ou du département
            if (name.startsWith(searchValue) || department.startsWith(searchValue)) {
                item.classList.remove('hidden'); // Afficher l'élément
            } else {
                item.classList.add('hidden'); // Masquer l'élément
            }
        });
    });
});

function searchTechnicians() {
    const postalCode = document.getElementById('postalCode').value.trim();
    const city = document.getElementById('city').value.trim();
    const address = document.getElementById('address').value.trim();
    const prestation = document.getElementById('prestationDropdown').value;

    if (!postalCode || !city || !address) {
        alert('Veuillez remplir tous les champs nécessaires.');
        return;
    }

    const department = postalCode.substring(0, 2);
    const queryURL = `/search-technicians?department=${department}&address=${encodeURIComponent(address)}&city=${encodeURIComponent(city)}&postal_code=${postalCode}&prestation=${prestation}`;

    // Log the parameters sent in the query
    console.log("Requête envoyée :", {
        department,
        address,
        city,
        postal_code: postalCode,
        prestation,
        queryURL,
    });

    fetch(queryURL)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur réseau: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const resultContainer = document.getElementById('technicianResults');
            const agendaComparatifContainer = document.getElementById('agendaComparatifContainer');
            resultContainer.innerHTML = '';
            agendaComparatifContainer.style.display = 'none'; // Masquer le bouton par défaut

            if (data.technicians.length === 0) {
                resultContainer.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center text-warning">Aucun technicien disponible.</td>
                    </tr>
                `;
                return;
            }

            // Stocker les données des techniciens pour l'agenda comparatif
            techniciansData = {};
            data.technicians.forEach(tech => {
                techniciansData[tech.id] = {
                    name: tech.name,
                    appointments: tech.appointments || [],
                };
                technicianAppointments[tech.id] = tech.appointments || [];

                const row = `
                    <tr>
                        <td>${tech.name}</td>
                        <td>${tech.next_availability_date || 'N/A'}</td>
                        <td>${tech.number_of_appointments || 0}</td>
                        <td>${tech.travel || 'N/A'}</td>
                        <td>
                            <button class="btn btn-info btn-sm" onclick="openCalendar('${tech.id}', '${tech.name}')">Agenda</button>
                        </td>
                    </tr>
                `;
                resultContainer.innerHTML += row;
            });

            if (data.technicians.length > 1) { // Afficher le bouton uniquement si plus de 2 techniciens
                agendaComparatifContainer.style.display = 'block';
            }

            console.log("Données des techniciens récupérées :", techniciansData);
        })
        .catch(error => {
            console.error('Erreur lors de la recherche des techniciens:', error);
            alert('Une erreur est survenue lors de la recherche des techniciens.');
        });
}

function showAgendaComparatif() {
    const technicians = Object.entries(techniciansData).map(([id, data]) => ({
        id,
        name: data.name,
        appointments: data.appointments,
    }));

    if (technicians.length === 0) {
        console.error("Aucun technicien trouvé pour afficher l'agenda comparatif.");
        return;
    }

    console.log("Techniciens récupérés pour l'agenda comparatif :", technicians);

    const overlayId = 'comparatifCalendarOverlay';
    let overlay = document.getElementById(overlayId);

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <button class="close-btn" onclick="closeComparatifCalendar()">&times;</button>
                <h3 class="text-center justify-center">Agenda Comparatif</h3>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <button id="prevWeekComparatif" class="btn btn-outline-primary btn-sm" onclick="changeComparatifWeek(-1)">&larr; Semaine précédente</button>
                    <span id="weekLabelComparatif" class="font-weight-bold">Semaine du XX/XX/XXXX</span>
                    <button id="nextWeekComparatif" class="btn btn-outline-primary btn-sm" onclick="changeComparatifWeek(1)">Semaine suivante &rarr;</button>
                </div>
                <div id="ComparatifCalendarContainer" class="calendar-container" style="height: 500px;"></div>
                <div id="legendContainer" class="legend-container mt-3"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    overlay.style.display = 'flex';

    console.log("Affichage du calendrier comparatif dans l'élément :", overlay);

    // Initialisation de la semaine actuelle si non définie
    if (!currentWeekStarts['comparatif']) {
        currentWeekStarts['comparatif'] = getMonday(new Date());
    }

    renderComparatifCalendar(
        technicians,
        document.getElementById('ComparatifCalendarContainer')
    ); // Affiche l'agenda comparatif

    // Mise à jour de l'étiquette de la semaine
    updateComparatifWeekLabel();
}

// Fonction pour mettre à jour l'étiquette de la semaine
function updateComparatifWeekLabel() {
    const weekStart = currentWeekStarts['comparatif'];
    const weekLabel = document.getElementById('weekLabelComparatif');
    if (weekLabel && weekStart) {
        weekLabel.textContent = `Semaine du ${formatDate(weekStart)}`;
    }
}

// Fonction pour changer de semaine
function changeComparatifWeek(offset) {
    if (!currentWeekStarts['comparatif']) {
        currentWeekStarts['comparatif'] = getMonday(new Date());
    }
    currentWeekStarts['comparatif'] = new Date(currentWeekStarts['comparatif'].getTime() + offset * 7 * 86400000);

    console.log(`Semaine mise à jour : ${currentWeekStarts['comparatif']}`);
    
    const technicians = Object.entries(techniciansData).map(([id, data]) => ({
        id,
        name: data.name,
        appointments: data.appointments,
    }));

    renderComparatifCalendar(
        technicians,
        document.getElementById('ComparatifCalendarContainer')
    );

    updateComparatifWeekLabel(); // Mettre à jour l'étiquette de la semaine
}

function closeComparatifCalendar() {
    const overlay = document.getElementById('comparatifCalendarOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

function renderComparatifCalendar(technicians, container) {
    container.innerHTML = '';
    const colors = ['#FF5733', '#33FF57', '#3357FF', '#FF33A8', '#FFBD33'];

    const daysOfWeek = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    const hours = Array.from({ length: 12 }, (_, i) => `${String(i + 8).padStart(2, '0')}:00`);

    const appointmentsByDate = {};

    // Début et fin de la semaine courante
    const weekStart = currentWeekStarts['comparatif'];
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
                    color: techColor,
                    startMinutes,
                    endMinutes,
                    conflictClass: null, // Ajouter la propriété conflictClass
                });
            }
        });
    });

    // Détecter les conflits et attribuer les classes conflict-1 / conflict-2
    Object.keys(appointmentsByDate).forEach(date => {
        const dailyAppointments = appointmentsByDate[date];
        console.log(`Date : ${date}, Nombre de rendez-vous : ${dailyAppointments.length}`);

        if (dailyAppointments.length > 1) {
            for (let i = 0; i < dailyAppointments.length; i++) {
                const appt1 = dailyAppointments[i];

                for (let j = i + 1; j < dailyAppointments.length; j++) {
                    const appt2 = dailyAppointments[j];

                    if (
                        (appt1.startMinutes < appt2.endMinutes && appt1.startMinutes >= appt2.startMinutes) ||
                        (appt2.startMinutes < appt1.endMinutes && appt2.startMinutes >= appt1.startMinutes)
                    ) {
                        console.log(
                            `Conflit détecté entre :\n` +
                            `- RDV 1 : ${appt1.nom} (${appt1.start_at} - ${appt1.duree} min)\n` +
                            `- RDV 2 : ${appt2.nom} (${appt2.start_at} - ${appt2.duree} min)`
                        );

                        if (!appt1.conflictClass) appt1.conflictClass = 'conflict-1';
                        if (!appt2.conflictClass) appt2.conflictClass = 'conflict-2';
                    }
                }
            }
        }
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

            cell.setAttribute('data-date', dayKey);
            cell.setAttribute('data-hour', hour);

            cell.addEventListener('dblclick', () => {
                const cellDate = cell.getAttribute('data-date');
                const cellHour = cell.getAttribute('data-hour');
                openAppointmentOverlay(cellDate, cellHour, technicians);
            });

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

                    if (appointment.conflictClass) {
                        button.classList.add(appointment.conflictClass);
                    }

                    button.textContent = `${appointment.nom}`;
                    button.onclick = () => openAppointmentDetailsOverlay(appointment.id);
                    cell.appendChild(button);
                });
            }

            row.appendChild(cell);
        }
        container.appendChild(row);
    });
}

function openAppointmentOverlay(date, hour, technicians) {
    const overlayId = 'appointmentOverlay';
    let overlay = document.getElementById(overlayId);

    // Récupération des valeurs pré-remplies pour adresse, code postal et ville
    const address = document.getElementById('address').value || '';
    const postalCode = document.getElementById('postalCode').value || '';
    const city = document.getElementById('city').value || '';

    if (!overlay) {
        // Créer l'overlay si inexistant
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'modal-overlay appointment-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <button class="close-btn" onclick="closeAppointmentOverlay()">&times;</button>
                <h3>Créer un rendez-vous</h3>
                <form id="appointmentForm">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="appointmentDate">Date</label>
                            <input type="text" id="appointmentDate" class="form-control" readonly>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="startTime">Heure</label>
                            <input type="time" id="startTime" class="form-control">
                        </div>
                    </div>
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
                    <div class="form-group">
                        <label for="clientPhone">Téléphone</label>
                        <input type="tel" id="clientPhone" class="form-control" placeholder="Téléphone" required>
                    </div>
                    <div class="form-group">
                        <label for="clientAddress">Adresse</label>
                        <input type="text" id="clientAddress" class="form-control">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="clientPostalCode">Code postal</label>
                            <input type="text" id="clientPostalCode" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="clientCity">Ville</label>
                            <input type="text" id="clientCity" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="technician">Technicien</label>
                        <select id="technician" class="form-control">
                            ${technicians.map(tech => `<option value="${tech.id}">${tech.name}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="comment">Commentaire</label>
                        <textarea id="comment" class="form-control"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="submitAppointment()">Valider</button>
                </form>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    // Mettre à jour les champs existants
    overlay.querySelector('#appointmentDate').value = date;
    overlay.querySelector('#startTime').value = hour;
    overlay.querySelector('#clientAddress').value = address;
    overlay.querySelector('#clientPostalCode').value = postalCode;
    overlay.querySelector('#clientCity').value = city;

    overlay.style.display = 'flex';
}

function closeAppointmentOverlay() {
    const overlay = document.getElementById('appointmentOverlay');
    if (overlay) overlay.style.display = 'none';
}

function openCalendar(technicianId, technicianName) {
    const overlayId = `calendarOverlay-${technicianId}`;
    let overlay = document.getElementById(overlayId);

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <button class="close-btn" onclick="closeCalendar('${technicianId}')">&times;</button>
                <h3 class="text-center">Calendrier de ${technicianName}</h3>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <button id="prevWeek-${technicianId}" class="btn btn-outline-primary btn-sm" onclick="changeWeek('${technicianId}', -1)">&larr; Semaine précédente</button>
                    <span id="weekLabel-${technicianId}" class="font-weight-bold">Semaine du XX/XX/XXXX</span>
                    <button id="nextWeek-${technicianId}" class="btn btn-outline-primary btn-sm" onclick="changeWeek('${technicianId}', 1)">Semaine suivante &rarr;</button>
                </div>
                <div id="RdvCalendarContainer-${technicianId}" class="calendar-container" style="height: 500px;"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    overlay.style.display = 'flex';

    // Assurez que la liste des rendez-vous existe même si elle est vide
    technicianAppointments[technicianId] = technicianAppointments[technicianId] || [];

    renderWeekView(technicianId); // Affiche l'agenda, même vide
}

function closeCalendar(technicianId) {
    const overlay = document.getElementById(`calendarOverlay-${technicianId}`);
    if (overlay) overlay.style.display = 'none';
}