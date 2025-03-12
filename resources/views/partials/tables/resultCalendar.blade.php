<div class="card mb-4">
    <div class="card-header">
        Calendrier des Rendez-vous
        <!-- Bouton "Voir la carte interactive" -->
        <button class="mx-5 btn btn-info btn-sm shadow-sm" data-toggle="modal" data-target="#mapModal">
            <i class="fas fa-map"></i> Voir la carte interactive
        </button>
    </div>
    <div class="card-body">

        <!-- Légende pour les 3 techniciens -->
        <div class="mb-3 d-flex" style="gap: 2rem;">
            @php
                $colors = ['#ff9999', '#99ff99', '#9999ff', '#ffcc99', '#99ccff'];
            @endphp

            @foreach(($selectedTechs ?? []) as $index => $techData)
            @php
                $tech       = $techData['tech'];
                $user       = $tech->user;
                $name       = ($user->prenom ?? '').' '.($user->nom ?? '');
                $color      = $colors[$index] ?? '#dddddd';
                $distance   = round($techData['distance'], 1) . ' km';
                $department = $tech->department; // via accessor
            @endphp

            <div class="d-flex align-items-center">

                {{-- Cocher seulement si $index < 3 --}}
                <input
                    type="checkbox"
                    class="tech-visibility"
                    data-tech-id="{{ $tech->id }}"
                    @if ($index < 3) checked @endif
                    style="margin-right:5px;"
                />

                {{-- Pastille de couleur --}}
                <span style="width:20px; height:20px; background-color:{{ $color }}; margin-right:5px;"></span>

                <span>
                    {{ trim($name) ?: 'Technicien #'.($index+1) }} ({{ $department }}) ({{ $distance }})
                </span>
            </div>
            @endforeach
        </div>

        <div id="calendarLoadingOverlay" class="calendar-loading-overlay d-none">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
        </div>

        <!-- Le calendrier -->
        <div id="calendar"></div>
    </div>
</div>
