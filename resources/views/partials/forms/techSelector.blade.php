<div class="card">
    <div class="card-header">
        <h5 class="card-title">Sélectionner les techniciens</h5>
    </div>
    <div class="card-body">
        <input type="text" class="form-control mb-2" id="search_tech" placeholder="Rechercher un technicien...">

        <form id="tech-selection-form">
            @foreach ($technicians as $tech)
                <div class="form-check">
                    <input type="checkbox" class="form-check-input tech-checkbox"
                           id="tech_{{ $tech->id }}" name="techs[]" value="{{ $tech->id }}"
                           {{ in_array($tech->id, $selectedTechs) ? 'checked' : '' }}>
                    <label class="form-check-label tech-checkbox-label" for="tech_{{ $tech->id }}">
                        {{ $tech->user->prenom }} {{ $tech->user->nom }}
                    </label>
                </div>
            @endforeach
        </form>
    </div>
</div>
