<div class="modal fade" id="reassignTechModal" tabindex="-1" aria-labelledby="reassignTechModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reassignTechModalLabel">Réattribuer un technicien</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form id="reassignTechForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <input type="hidden" id="appointment-id" name="appointment_id">

                    <!-- Affichage du technicien actuel -->
                    <div class="mb-3">
                        <label class="form-label">Technicien actuel :</label>
                        <p id="current-tech" class="font-weight-bold">Non attribué</p>
                    </div>

                    <!-- Barre de recherche -->
                    <div class="mb-3">
                        <label for="tech-search" class="form-label">Rechercher un technicien</label>
                        <input type="text" id="tech-search" class="form-control" placeholder="Rechercher par nom ou prénom...">
                    </div>

                    <!-- Liste des techniciens -->
                    <div class="mb-3">
                        <label for="new-tech-id" class="form-label">Nouveau technicien</label>
                        <select class="form-control" id="new-tech-id" name="tech_id" required>
                            <option value="">Sélectionnez un technicien</option>
                            @foreach ($technicians as $tech)
                                <option value="{{ $tech->id }}">
                                    {{ $tech->user->nom }} {{ $tech->user->prenom }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Réattribuer</button>
                </div>
            </form>
        </div>
    </div>
</div>
