<div class="modal fade" id="serviceEditModal" tabindex="-1" aria-labelledby="serviceEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceEditModalLabel">Modifier un service</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form id="editServiceForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit-type" class="form-label">Type</label>
                        <select class="form-control" id="edit-type" name="type" required>
                            <option value="COFRAC">COFRAC</option>
                            <option value="MAR">MAR</option>
                            <option value="AUDIT">AUDIT</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit-name" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="edit-name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-default_time" class="form-label">Temps par défaut (en minutes)</label>
                        <input type="number" class="form-control" id="edit-default_time" name="default_time" required min="1">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Modifier</button>
                </div>
            </form>
        </div>
    </div>
</div>
