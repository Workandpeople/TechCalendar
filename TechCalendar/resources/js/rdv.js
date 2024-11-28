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


        https://www.amazon.fr/gp/product/B07GB5JRTZ/ref=ewc_pr_img_11?smid=AUBM4K0YLFI9J&psc=1


        // Déclencher l'événement change au chargement pour initialiser les champs
        prestationDropdown.dispatchEvent(new Event('change'));
    }
});

function filterPrestations() {
    const filter = this.value.toLowerCase();
    const dropdown = document.getElementById('prestationDropdown');
    const options = dropdown.getElementsByTagName('option');

    Array.from(options).forEach(option => {
        const text = option.textContent.toLowerCase();
        option.style.display = text.startsWith(filter) ? '' : 'none';
    });
}

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
    const queryURL = `/search-technicians?department=${department}&address=${encodeURIComponent(address)}&city=${encodeURIComponent(city)}&prestation=${prestation}`;

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
                <h3>Agenda Comparatif</h3>
                <div id="ComparatifCalendarContainer" class="calendar-container" style="height: 500px;"></div>
                <div id="legendContainer" class="legend-container mt-3"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    overlay.style.display = 'flex';

    console.log("Affichage du calendrier comparatif dans l'élément :", overlay);

    renderComparatifCalendar(
        technicians,
        document.getElementById('ComparatifCalendarContainer')
    ); // Affiche l'agenda comparatif
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

    // Initialiser currentWeekStarts pour tous les techniciens
    technicians.forEach(tech => {
        if (!currentWeekStarts[tech.id]) {
            currentWeekStarts[tech.id] = getMonday(new Date());
        }
    });

    if (!technicians || technicians.length === 0) {
        console.error("Aucun technicien disponible pour afficher le calendrier.");
        return;
    }

    // Organiser les rendez-vous par date
    technicians.forEach((tech, index) => {
        if (!tech || !tech.appointments) return;

        const techColor = colors[index % colors.length];
        const appointments = tech.appointments;

        appointments.forEach(appointment => {
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

                        // Attribuer conflict-1 et conflict-2
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

    const firstTech = technicians[0];
    const firstTechWeekStart = currentWeekStarts[firstTech.id];
    if (!firstTechWeekStart) {
        console.error("Impossible de récupérer la date de début pour le premier technicien.");
        return;
    }

    for (let i = 0; i < 5; i++) {
        const day = new Date(firstTechWeekStart.getTime() + i * 86400000);
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
            const day = new Date(firstTechWeekStart.getTime() + i * 86400000);
            const dayKey = day.toISOString().split('T')[0];
            const key = `${dayKey}-${hour}`;

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

                    // Ajouter la classe de conflit si définie
                    if (appointment.conflictClass) {
                        button.classList.add(appointment.conflictClass);
                        console.log(`Classe appliquée : ${appointment.conflictClass} pour le RDV "${appointment.nom}"`);
                    }

                    button.textContent = `${appointment.nom}`;
                    button.onclick = () =>
                        alert(`Rendez-vous avec ${appointment.nom} à ${appointment.start_at}`);
                    cell.appendChild(button);
                });
            }

            row.appendChild(cell);
        }
        container.appendChild(row);
    });
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
                <h3>Calendrier de ${technicianName}</h3>
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

    if (!technicianAppointments[technicianId] || technicianAppointments[technicianId].length === 0) {
        console.warn(`Aucun rendez-vous trouvé pour le technicien ${technicianId}`);
        alert("Aucun rendez-vous pour ce technicien.");
        return;
    }

    renderWeekView(technicianId); // Affiche l'agenda
}

function closeCalendar(technicianId) {
    const overlay = document.getElementById(`calendarOverlay-${technicianId}`);
    if (overlay) overlay.style.display = 'none';
}

function chooseTechnician(technicianId) {
    // Simulation de l'action de choix d'un technicien
    alert(`Technicien avec ID ${technicianId} sélectionné. Overlay à implémenter.`);
}

function filterTechnicians() {
    const searchInput = document.getElementById('technicianSearch');
    const filter = searchInput ? searchInput.value.toLowerCase() : '';

    const techItems = document.querySelectorAll('.technician-item');
    techItems.forEach(item => {
        const techName = item.textContent.toLowerCase().trim();

        if (techName.startsWith(filter)) {
            item.classList.remove('hidden');
            item.style.display = 'table-row'; // Affiche l'élément sous forme de ligne de tableau
        } else {
            item.classList.add('hidden');
            item.style.display = 'none'; // Masque l'élément
        }
    });
}