<div class="modal fade" id="editServiceModal" tabindex="-1" aria-labelledby="editServiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editServiceModalLabel">Modifier la prestation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form id="editServiceForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="editServiceId" name="service_id">
                    <div class="mb-3">
                        <label for="editType" class="form-label">Type de prestation</label>
                        <select class="form-select" id="editType" name="type" required>
                            <option value="MAR">MAR</option>
                            <option value="AUDIT">AUDIT</option>
                            <option value="COFRAC">COFRAC</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="editName" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="editName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editDefaultTime" class="form-label">Durée par défaut (minutes)</label>
                        <input type="number" class="form-control" id="editDefaultTime" name="default_time" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveServiceChanges()">Enregistrer</button>
            </div>
        </div>
    </div>
</div>