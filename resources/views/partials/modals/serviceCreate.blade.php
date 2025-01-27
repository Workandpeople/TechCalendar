<div class="modal fade" id="serviceCreateModal" tabindex="-1" aria-labelledby="serviceCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceCreateModalLabel">Créer un service</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form action="{{ route('manage-providers.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="type" class="form-label">Type</label>
                        <select class="form-control" id="type" name="type" required>
                            <option value="COFRAC">COFRAC</option>
                            <option value="MAR">MAR</option>
                            <option value="AUDIT">AUDIT</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="default_time" class="form-label">Temps par défaut (en minutes)</label>
                        <input type="number" class="form-control" id="default_time" name="default_time" required min="1">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>
