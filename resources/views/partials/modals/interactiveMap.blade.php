<!-- Modal pour la carte interactive -->
<div class="modal fade" id="mapModal" tabindex="-1" aria-labelledby="mapModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mapModalLabel">Carte Interactive des Trajets</h5>
                <button type="button" class="btn close-btn" data-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times fa-lg"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Filtres alignés en ligne sur PC et empilés sur mobile -->
                {{-- <div class="row mb-3">
                    <div class="col-md-6 col-12">
                        <label for="dateFilter" class="form-label">Date :</label>
                        <input type="date" id="dateFilter" class="form-control">
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="hourFilter" class="form-label">Heure :</label>
                        <input type="range" id="hourFilter" class="form-control" min="0" max="23" step="1">
                        <small id="selectedHour" class="text-muted d-block mt-1">Heure : 8h</small>
                    </div>
                </div> --}}

                <!-- Carte Mapbox -->
                <div id="map" class="map-container"></div>
            </div>
        </div>
    </div>
</div>
