<div class="card mb-4">
    <div class="card-header">
        Calendrier des Rendez-vous
    </div>
    <div class="card-body">

        <!-- Légende pour les 3 techniciens -->
        <div class="mb-3 d-flex" style="gap: 2rem;">
            @php
                $colors = ['#ff9999', '#99ff99', '#9999ff', '#ffcc99', '#99ccff'];
            @endphp

            @foreach(($selectedTechs ?? []) as $index => $techData)
                @php
                    $tech    = $techData['tech'];
                    $user    = $tech->user;
                    $name    = ($user->prenom ?? '').' '.($user->nom ?? '');
                    $color   = $colors[$index] ?? '#dddddd';
                    $distance = round($techData['distance'], 1) . ' km'; // Afficher la distance
                @endphp

                <div class="d-flex align-items-center">
                    <span style="width:20px; height:20px; background-color:{{ $color }}; margin-right:5px;"></span>
                    <span>{{ trim($name) ?: 'Technicien #'.($index+1) }} ({{ $distance }})</span>
                </div>
            @endforeach
        </div>

        <!-- Le calendrier -->
        <div id="calendar"></div>
    </div>
</div>
