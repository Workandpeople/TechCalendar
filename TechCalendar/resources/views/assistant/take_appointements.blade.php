@extends('layouts.app')

@section('content')
<!-- Content Wrapper -->
<div id="content-wrapper" class="d-flex flex-column">

    <!-- Main Content -->
    <div id="content">

        <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
            @include('partials/simpleTopbar')
        </nav>

        <div class="container-fluid">
            <div class="row">
                <div class="col-xl-12 col-lg-12">
                    @include('partials/form_manual')
                    @include('partials/form_search')
                </div>
            </div>
        </div>

    </div>
    <!-- End of Main Content -->

</div>
<!-- End of Content Wrapper -->

</div>
<!-- End of Page Wrapper -->

@include('partials/result_modal')

@endsection

@section('head-js')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Formulaire manuel
    const manualServiceSelect = document.getElementById('manualServiceId');
    const manualDurationInput = document.getElementById('manualDuration');
    const manualStartTimeInput = document.getElementById('manualStartTime');
    const manualEndTimeInput = document.getElementById('manualEndTime');

    // Formulaire de recherche
    const searchServiceSelect = document.getElementById('searchServiceId');
    const searchDurationInput = document.getElementById('searchDuration');
    const searchForm = document.getElementById('searchForm');
    const resultsList = document.getElementById('resultsList');
    const agendaComparatifLink = document.getElementById('agendaComparatifLink');

    if (!resultsList) {
        console.error('Element resultsList introuvable. Vérifiez le DOM et le moment où le script s\'exécute.');
    }

    /**
     * Met à jour la durée et l'heure de fin pour le formulaire manuel
     */
    manualServiceSelect?.addEventListener('change', () => {
        const selectedOption = manualServiceSelect.options[manualServiceSelect.selectedIndex];
        const defaultTime = selectedOption.getAttribute('data-duration');
        if (defaultTime) {
            manualDurationInput.value = defaultTime;
            calculateManualEndTime(); // Recalcule l'heure de fin
        }
    });

    manualStartTimeInput?.addEventListener('input', calculateManualEndTime);
    manualDurationInput?.addEventListener('input', calculateManualEndTime);

    function calculateManualEndTime() {
        const startTime = manualStartTimeInput.value;
        const duration = parseInt(manualDurationInput.value, 10);

        if (startTime && !isNaN(duration)) {
            const [hours, minutes] = startTime.split(':').map(Number);
            const endTime = new Date();
            endTime.setHours(hours);
            endTime.setMinutes(minutes + duration);

            const endHours = endTime.getHours().toString().padStart(2, '0');
            const endMinutes = endTime.getMinutes().toString().padStart(2, '0');
            manualEndTimeInput.value = `${endHours}:${endMinutes}`;
        } else {
            manualEndTimeInput.value = ''; // Vide l'heure de fin si les données sont invalides
        }
    }

    /**
     * Met à jour la durée pour le formulaire de recherche
     */
    searchServiceSelect?.addEventListener('change', () => {
        const selectedOption = searchServiceSelect.options[searchServiceSelect.selectedIndex];
        const defaultTime = selectedOption.getAttribute('data-duration');
        if (defaultTime) {
            searchDurationInput.value = defaultTime;
        }
    });

    /**
     * Recherche des techniciens disponibles
     */
     function searchTechnicians() {
        const formData = new FormData(searchForm);

        if (!resultsList) {
            console.error('Le conteneur de résultats est introuvable.');
            alert('Une erreur est survenue.');
            return;
        }

        showLoadingOverlay(); // Afficher un overlay de chargement si nécessaire

        fetch("{{ route('assistant.submit_appointment') }}", {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur HTTP : ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            resultsList.innerHTML = '';
            if (data.technicians && data.technicians.length > 0) {
                const availableTechIds = [];

                data.technicians.forEach(tech => {
                    availableTechIds.push(tech.id);

                    const listItem = document.createElement('li');
                    listItem.className = 'list-group-item';
                    listItem.innerHTML = `
                        <strong>${tech.user.prenom} ${tech.user.nom}</strong><br>
                        Adresse: ${tech.adresse}, ${tech.zip_code} ${tech.city}<br>
                        Distance : ${tech.distance_km} km<br>
                        Temps estimé : ${tech.duration_minutes} minutes<br>
                    `;
                    resultsList.appendChild(listItem);
                });

                // Corrigez la génération de l'URL pour l'Agenda Comparatif
                const agendaComparatifLink = document.getElementById('agendaComparatifLink');
                if (agendaComparatifLink) {
                    const encodedTechIds = availableTechIds.map(id => `tech_ids[]=${encodeURIComponent(id)}`).join('&');
                    agendaComparatifLink.href = `/assistant/tech-calendar?${encodedTechIds}`;
                }
            } else {
                resultsList.innerHTML = '<p>Aucun technicien disponible.</p>';
            }

            $('#resultModal').modal('show'); // Afficher le modal des résultats
        })
        .catch(error => {
            console.error('Erreur lors de la recherche de techniciens :', error);
            alert('Une erreur est survenue.');
        })
        .finally(() => {
            hideLoadingOverlay(); // Masquer l'overlay de chargement si nécessaire
        });
    }

    // Ajouter l'événement de recherche au formulaire
    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        searchTechnicians();
    });

    /**
     * Fermer le modal des résultats
     */
    function closeModal() {
        $('#resultModal').modal('hide');
    }

    // Rendre les fonctions disponibles globalement
    window.searchTechnicians = searchTechnicians;
    window.closeModal = closeModal;
});
</script>
@endsection