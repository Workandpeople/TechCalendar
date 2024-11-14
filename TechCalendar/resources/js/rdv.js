document.addEventListener('DOMContentLoaded', () => {
    // Toggle overlay pour le rendez-vous
    const newRdvBtn = document.getElementById('newRdvBtn');
    if (newRdvBtn) {
        newRdvBtn.addEventListener('click', toggleNewRdvOverlay);
    }

    // Filtrage dynamique des prestations par début du mot
    const prestationSearchInput = document.getElementById('prestationSearch');
    if (prestationSearchInput) {
        prestationSearchInput.addEventListener('input', function () {
            const filter = this.value.toLowerCase();
            const dropdown = document.getElementById('prestationDropdown');
            const options = dropdown.getElementsByTagName('option');

            Array.from(options).forEach(option => {
                const text = option.textContent.toLowerCase();
                option.style.display = text.startsWith(filter) ? '' : 'none';
            });
        });
    }

    // Filtrage dynamique des techniciens par nom ou code postal
    const technicianSearchInput = document.getElementById('technicianSearch');
    if (technicianSearchInput) {
        technicianSearchInput.addEventListener('input', filterTechnicians);
    }
});

function toggleNewRdvOverlay() {
    const overlay = document.getElementById('newRdvOverlay');
    if (overlay) {
        overlay.style.display = overlay.style.display === 'none' ? 'flex' : 'none';
    }
}

function submitRdvForm() {
    const prestation = document.getElementById('prestationDropdown').value;
    const address = document.getElementById('address').value;
    const postalCode = document.getElementById('postalCode').value;
    const city = document.getElementById('city').value;

    // Logique pour soumettre le formulaire

    // Fermer l'overlay après la soumission
    toggleNewRdvOverlay();
}

function filterTechnicians() {
    const searchInput = document.getElementById('technicianSearch');
    const filter = searchInput ? searchInput.value.toLowerCase() : '';

    const techItems = document.querySelectorAll('.tech-item');
    techItems.forEach(item => {
        const techName = item.dataset.name.toLowerCase();
        const techPostalCode = item.dataset.postalCode.slice(0, 2); // Prend les 2 premiers caractères du code postal

        // Affiche l'élément si le prénom/nom ou le début du code postal correspond
        item.style.display = (techName.startsWith(filter) || techPostalCode.startsWith(filter)) ? 'block' : 'none';
    });
}