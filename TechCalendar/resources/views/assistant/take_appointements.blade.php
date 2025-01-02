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
    
        const resultsList = document.getElementById('resultsList');
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
    });
    
    function closeModal() {
        const modal = document.getElementById('resultModal');
        if (modal) {
            $(modal).modal('hide'); // Utilise Bootstrap pour fermer le modal
        } else {
            console.error('Le modal resultModal est introuvable.');
        }
    }
    
    // Définir globalement la fonction searchTechnicians
    function searchTechnicians() {
        const formData = new FormData(document.getElementById('searchForm'));
        const resultsList = document.getElementById('resultsList');
    
        if (!resultsList) {
            console.error('Element resultsList introuvable.');
            alert('Une erreur est survenue.');
            return;
        }
    
        // Affiche l'overlay de chargement
        showLoadingOverlay();
    
        fetch("{{ route('assistant.submit_appointment') }}", {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                resultsList.innerHTML = '';
    
                if (data.technicians && data.technicians.length > 0) {
                    data.technicians.forEach(tech => {
                        const listItem = document.createElement('li');
                        listItem.className = 'list-group-item';
                        listItem.innerHTML = `
                            Adresse: ${tech.adresse}, ${tech.zip_code} ${tech.city}<br>
                            Distance : ${tech.distance_km} km<br>
                            Temps estimé : ${tech.duration_minutes} minutes
                        `;
                        resultsList.appendChild(listItem);
                    });
                } else {
                    resultsList.innerHTML = '<p>Aucun technicien disponible.</p>';
                }
    
                // Affiche le modal
                $('#resultModal').modal('show');
            })
            .catch(error => {
                console.error('Erreur lors de la recherche:', error);
                alert('Une erreur est survenue.');
            })
            .finally(() => {
                // Cache l'overlay de chargement
                hideLoadingOverlay();
            });
    }
</script>
@endsection