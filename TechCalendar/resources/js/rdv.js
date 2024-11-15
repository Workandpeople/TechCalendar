document.addEventListener('DOMContentLoaded', () => {
    // Filtrage dynamique des prestations par début du mot
    const prestationSearchInput = document.getElementById('prestationSearch');
    if (prestationSearchInput) {
        prestationSearchInput.addEventListener('input', filterPrestations);
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

    // Validation des champs obligatoires
    if (!postalCode || !city || !address) {
        alert('Veuillez remplir tous les champs nécessaires.');
        return;
    }

    // Extraire le département à partir du code postal
    const department = postalCode.substring(0, 2);

    // Construire l'URL de la requête
    const queryURL = `/search-technicians?department=${department}&address=${encodeURIComponent(address)}&city=${encodeURIComponent(city)}&prestation=${prestation}`;

    // Effectuer la requête fetch
    fetch(queryURL)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur réseau: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const resultContainer = document.getElementById('technicianResults');
            resultContainer.innerHTML = ''; // Nettoyer les anciens résultats

            if (data.error) {
                // Afficher un message d'erreur si l'API retourne une erreur
                resultContainer.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-danger">Erreur: ${data.error}</td>
                    </tr>
                `;
            } else if (data.technicians.length === 0) {
                // Afficher un message si aucun technicien n'est trouvé
                resultContainer.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-warning">Aucun technicien disponible ne correspond aux critères spécifiés.</td>
                    </tr>
                `;
            } else {
                // Parcourir et afficher les techniciens dans le tableau
                data.technicians.forEach(tech => {
                    console.log('Technician data:', tech); // Log des données pour vérification

                    const row = `
                        <tr>
                            <td>${tech.name}</td>
                            <td>${tech.distance.toFixed(2)} km</td>
                            <td>${tech.duration.toFixed(2)} min</td>
                            <td>
                                <button class="btn btn-success btn-sm" 
                                    onclick="openCalendar('${tech.id}', '${tech.name}', ${tech.duration})">
                                    Choisir ce tech
                                </button>
                            </td>
                        </tr>
                    `;
                    resultContainer.innerHTML += row;
                });
            }
        })
        .catch(error => {
            console.error('Erreur lors de la recherche des techniciens:', error);
            alert('Une erreur est survenue lors de la recherche des techniciens.');
        });
}

function openCalendar(technicianId, technicianName, travelTime) {
    console.log('Ouverture du calendrier pour le technicien', technicianId, technicianName, travelTime);
    const overlayId = `calendarOverlay-${technicianId}`;
    let overlay = document.getElementById(overlayId);

    if (!overlay) {
        // Crée un nouvel overlay pour le technicien si non existant
        overlay = document.createElement('div');
        overlay.id = overlayId;
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-content">
                <button class="close-btn" onclick="closeCalendar('${technicianId}')">&times;</button>
                <h3>Calendrier de ${technicianName}</h3>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <button id="prevWeek-${technicianId}" class="btn btn-outline-primary btn-sm" onclick="changeWeek('${technicianId}', -1)">&larr;</button>
                    <span id="weekLabel-${technicianId}" class="font-weight-bold">Semaine du XX/XX/XXXX</span>
                    <button id="nextWeek-${technicianId}" class="btn btn-outline-primary btn-sm" onclick="changeWeek('${technicianId}', 1)">&rarr;</button>
                </div>
                <div id="RdvCalendarContainer-${technicianId}" class="calendar-container" style="height: 500px;"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    overlay.style.display = 'flex';
    renderWeekView(technicianId, travelTime); // Affiche le calendrier pour le technicien choisi
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