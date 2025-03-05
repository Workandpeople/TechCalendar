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
                    <div class="mb-3 position-relative">
                        <label for="tech-search" class="form-label">Rechercher un technicien</label>
                        <input type="text" id="tech-search" class="form-control" placeholder="Rechercher par nom ou prénom...">
                        <!-- Container pour les suggestions -->
                        <div id="reassignTechSuggestions" style="position: absolute; top: 100%; left: 0; right: 0;
                                background: #fff; border: 1px solid #ccc; z-index: 999;"></div>
                    </div>

                    <!-- Nouveau technicien (champ caché) -->
                    <input type="hidden" id="new-tech-id-hidden" name="tech_id">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Réattribuer</button>
                </div>
            </form>
        </div>
    </div>
</div>
