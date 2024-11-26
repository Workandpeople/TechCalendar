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
        console.warn('Validation échouée: des champs sont manquants');
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
            agendaComparatifContainer.innerHTML = ''; // Réinitialiser l'agenda comparatif

            if (data.technicians.length === 0) {
                console.warn('Aucun technicien trouvé');
                resultContainer.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center text-warning">Aucun technicien disponible.</td>
                    </tr>
                `;
                return;
            }

            data.technicians.forEach(tech => {
                try {

                    // Stockage des rendez-vous pour utilisation ultérieure
                    technicianAppointments[tech.id] = tech.appointments || [];

                    const row = `
                        <tr>
                            <td>${tech.name}</td>
                            <td>${tech.next_availability_date || 'N/A'}</td>
                            <td>${tech.number_of_appointments || 0}</td>
                            <td>${tech.travel || 'N/A'}</td>
                            <td>
                                <div class="d-inline-flex">
                                    <button class="btn btn-info btn-sm mr-2" onclick="openCalendar('${tech.id}', '${tech.name}')">Agenda</button>
                                </div>
                            </td>
                        </tr>
                    `;
                    resultContainer.innerHTML += row;
                } catch (error) {
                    console.error('Erreur lors du traitement d\'un technicien', { tech, error });
                }
            });

            if (data.technicians.length > 1) {
                const button = `
                    <button class="btn btn-primary mt-3" onclick="showAgendaComparatif()">Voir l'agenda comparatif</button>
                `;
                agendaComparatifContainer.innerHTML = button;
            }
        })
        .catch(error => {
            console.error('Erreur lors de la recherche des techniciens:', error);
            alert('Une erreur est survenue lors de la recherche des techniciens.');
        });
}

function showComparativeAgenda() {
    alert('Affichage de l\'agenda comparatif à implémenter.');
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