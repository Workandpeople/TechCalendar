<div class="modal fade" id="createServiceModal" tabindex="-1" aria-labelledby="createServiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createServiceModalLabel">Créer une prestation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form id="createServiceForm">
                    @csrf
                    <div class="mb-3">
                        <label for="type" class="form-label">Type de prestation</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="MAR">MAR</option>
                            <option value="AUDIT">AUDIT</option>
                            <option value="COFRAC">COFRAC</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="default_time" class="form-label">Durée par défaut (minutes)</label>
                        <input type="number" class="form-control" id="default_time" name="default_time" min="0" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveNewService()">Créer</button>
            </div>
        </div>
    </div>
</div>