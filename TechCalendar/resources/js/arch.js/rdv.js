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
        console.warn('Tous les champs requis ne sont pas remplis.');
        alert('Veuillez remplir tous les champs nécessaires.');
        return;
    }

    const department = postalCode.substring(0, 2);
    const queryURL = `/search-technicians?department=${department}&address=${encodeURIComponent(address)}&city=${encodeURIComponent(city)}&postal_code=${postalCode}&prestation=${prestation}`;

    console.log("Requête envoyée pour rechercher les techniciens :", {
        department,
        address,
        city,
        postal_code: postalCode,
        prestation,
        queryURL,
    });

    showLoadingOverlay(); // Afficher l'overlay de chargement

    fetch(queryURL)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur réseau: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log("Réponse reçue pour les techniciens :", data);

            const resultContainer = document.getElementById('technicianResults');
            const agendaComparatifContainer = document.getElementById('agendaComparatifContainer');
            const technicianSelect = document.getElementById('technician');

            // Réinitialisation des champs
            resultContainer.innerHTML = '';
            agendaComparatifContainer.style.display = 'none'; // Masquer le bouton par défaut

            if (technicianSelect) {
                technicianSelect.innerHTML = ''; // Réinitialiser la liste déroulante
            }

            if (data.technicians.length === 0) {
                console.warn('Aucun technicien disponible trouvé.');
                resultContainer.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center text-warning">Aucun technicien disponible.</td>
                    </tr>
                `;
                return;
            }

            // Stocker et afficher les techniciens
            techniciansData = {};
            data.technicians.forEach(tech => {
                techniciansData[tech.id] = {
                    name: tech.name,
                    appointments: tech.appointments || [],
                    travel: tech.travel || '',
                };
                technicianAppointments[tech.id] = tech.appointments || [];

                const row = `
                    <tr>
                        <td>${tech.name}</td>
                        <td>${tech.next_availability_date || 'N/A'}</td>
                        <td>${tech.number_of_appointments || 0}</td>
                        <td>${tech.travel || 'N/A'}</td>
                        <td>
                            <button class="btn btn-info btn-sm" onclick="openCalendar('${tech.id}', '${tech.name}', '${tech.travel}')">Agenda</button>
                        </td>
                    </tr>
                `;
                resultContainer.innerHTML += row;

                // Ajouter au dropdown
                if (technicianSelect) {
                    const option = document.createElement('option');
                    option.value = tech.id;
                    option.textContent = tech.name;
                    technicianSelect.appendChild(option);
                }
            });

            if (data.technicians.length > 1) {
                agendaComparatifContainer.style.display = 'block'; // Afficher le bouton comparatif
            }

            console.log("Données des techniciens stockées :", techniciansData);
        })
        .catch(error => {
            console.error('Erreur lors de la recherche des techniciens:', error);
            alert('Une erreur est survenue lors de la recherche des techniciens.');
        })
        .finally(() => {
            hideLoadingOverlay(); // Cacher l'overlay de chargement
        });
}

function showAgendaComparatif() {
    const technicians = Object.entries(techniciansData).map(([id, data]) => ({
        id,
        name: data.name,
        appointments: data.appointments,
        travel: data.travel,
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
                    <span id="weekLabelComparatif" class="font-weight-bold">Semaine en cours</span>
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

    renderComparatifCalendar(technicians, document.getElementById('ComparatifCalendarContainer'));

    // Mise à jour de l'étiquette de la semaine
    updateComparatifWeekLabel();
}

function parseTravel(travel) {
    if (!travel) return [0, 0];
    const [distance, hours, minutes] = travel.match(/\d+/g).map(Number);
    return [distance || 0, (hours || 0) * 60 + (minutes || 0)];
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
    technicians.forEach(tech => {
        const [distance, time] = parseTravel(tech.travel);
        console.log(`Technicien: ${tech.name}, Distance: ${distance} km, Temps: ${time} minutes`);
        // Utilisez distance et time comme nécessaire
    });
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
            
                // Chercher un rendez-vous correspondant à cette date et cette heure
                const appointment = appointmentsByDate[cellDate]?.find(appt => {
                    const startHour = Math.floor(appt.startMinutes / 60);
                    return `${String(startHour).padStart(2, '0')}:00` === cellHour;
                });
            
                // Identifier le technicien associé, ou sélectionner un technicien par défaut
                const selectedTechnician = appointment 
                    ? technicians.find(t => t.id === appointment.technician_id)
                    : (technicians.length > 0 ? technicians[0] : null);
            
                if (selectedTechnician) {
                    // Extraire les informations de distance et de temps du technicien
                    const [distance, time] = parseTravel(selectedTechnician.travel);
            
                    // Ouvrir l'overlay pour créer un rendez-vous
                    openAppointmentOverlay(cellDate, cellHour, technicians, distance, time);
                } else {
                    console.warn('Aucun technicien disponible pour créer un rendez-vous.');
                    alert('Aucun technicien disponible pour créer un rendez-vous.');
                }
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

function openAppointmentOverlay(date, hour, technicians, distance = 0, time = 0) {
    console.log("Ouverture de l'overlay avec les techniciens :", technicians);

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
        document.body.appendChild(overlay);
    }

    // Générer les options pour les techniciens
    const technicianOptions = technicians.map(tech => {
        const isValidUUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(tech.id);
        if (!isValidUUID) {
            console.warn(`Technician ID invalide détecté : ${tech.id}`);
            return ''; // Ignorer les techniciens avec un ID invalide
        }
        return `<option value="${tech.id}" data-travel="${tech.travel}">${tech.name}</option>`;
    }).join('');

    // Mettre à jour le contenu HTML de l'overlay
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
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="trajectTime">Temps de trajet (minutes)</label>
                        <input type="number" id="trajectTime" class="form-control" value="${time}" placeholder="Temps de trajet" readonly>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="trajectDistance">Distance de trajet (km)</label>
                        <input type="number" step="0.01" id="trajectDistance" class="form-control" value="${distance}" placeholder="Distance de trajet" readonly>
                    </div>
                </div>
                <div class="form-group">
                    <label for="technician">Technicien</label>
                    <select id="technician" class="form-control">
                        ${technicianOptions}
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

    // Pré-remplir les champs avec les données fournies
    overlay.querySelector('#appointmentDate').value = date;
    overlay.querySelector('#startTime').value = hour;
    overlay.querySelector('#clientAddress').value = address;
    overlay.querySelector('#clientPostalCode').value = postalCode;
    overlay.querySelector('#clientCity').value = city;

    // Sélectionner le technicien actuellement affiché dans le calendrier
    const technicianSelect = overlay.querySelector('#technician');
    technicianSelect.value = technicians[0]?.id; // Sélectionne automatiquement le technicien courant

    technicianSelect.addEventListener('change', function () {
        const selectedOption = this.selectedOptions[0];
        const travel = selectedOption.getAttribute('data-travel');
        if (travel) {
            const [distance, hours, minutes] = travel.match(/\d+/g) || [];
            document.getElementById('trajectDistance').value = parseFloat(distance) || 0;
            document.getElementById('trajectTime').value = (parseInt(hours, 10) || 0) * 60 + (parseInt(minutes, 10) || 0);
        }
    });

    overlay.style.display = 'flex';
}

function closeAppointmentOverlay() {
    const overlay = document.getElementById('appointmentOverlay');
    if (overlay) overlay.style.display = 'none';
}

function openCalendar(technicianId, technicianName, travel) {
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
                    <span id="weekLabel-${technicianId}" class="font-weight-bold">Semaine en cours</span>
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

    const [distance, time] = parseTravel(travel); // Parse travel data
    renderWeekView(technicianId, distance, time);
}

function closeCalendar(technicianId) {
    const overlay = document.getElementById(`calendarOverlay-${technicianId}`);
    if (overlay) overlay.style.display = 'none';
}