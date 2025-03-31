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
                <!-- Légende et filtres -->
                <div class="row mb-3">
                    <!-- Colonne 1 : Légende tech avec checkboxes -->
                    <div class="col-md-12" id="mapLegend">
                        @php
                            $colors = ['#ff9999', '#99ff99', '#9999ff', '#ffcc99', '#99ccff'];
                        @endphp
                        <div class="d-flex flex-wrap">
                            @foreach(($selectedTechs ?? []) as $index => $techData)
                                @php
                                    $tech = $techData['tech'];
                                    $user = $tech->user;
                                    $name = trim($user->prenom.' '.$user->nom);
                                    $color = $colors[$index] ?? '#dddddd';
                                    $department = $tech->department;
                                @endphp
                                <div class="d-flex align-items-center mb-2 mr-3">
                                    <input type="checkbox" class="map-tech-visibility" data-tech-id="{{ $tech->id }}"
                                           @if($index < 3) checked @endif style="margin-right:5px;">
                                    <span style="width:20px; height:20px; background-color:{{ $color }}; margin-right:5px;"></span>
                                    <span>{{ $name }} ({{ $department }})</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <!-- Boutons pour sélectionner un jour (en col 6) -->
                    <div class="col-md-6" id="mapDayButtons">
                        <button type="button" class="btn btn-outline-primary map-day-btn" data-day="Monday">Lun</button>
                        <button type="button" class="btn btn-outline-primary map-day-btn" data-day="Tuesday">Mar</button>
                        <button type="button" class="btn btn-outline-primary map-day-btn" data-day="Wednesday">Mer</button>
                        <button type="button" class="btn btn-outline-primary map-day-btn" data-day="Thursday">Jeu</button>
                        <button type="button" class="btn btn-outline-primary map-day-btn" data-day="Friday">Ven</button>
                    </div>
                    <!-- Curseur pour l'heure (en col 6) -->
                    <div class="col-md-6" id="mapTimeSliderContainer">
                        <label for="mapTimeSlider">Heure de départ (entre 8h et 20h) : <span id="mapTimeLabel">8:00</span></label>
                        <input type="range" id="mapTimeSlider" min="8" max="20" step="0.5" value="8" class="form-range">
                    </div>
                </div>
                <!-- Conteneur de la carte -->
                <div id="map" class="map-container" style="height: 500px;"></div>
            </div>
        </div>
    </div>
</div>
