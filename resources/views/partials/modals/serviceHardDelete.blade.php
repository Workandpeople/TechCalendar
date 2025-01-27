<div class="modal fade" id="serviceHardDeleteModal" tabindex="-1" aria-labelledby="serviceHardDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceHardDeleteModalLabel">Supprimer définitivement</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form id="hardDeleteServiceForm" method="POST">
                @csrf
                @method('DELETE')
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer ce service définitivement ? Cette action est irréversible.</p>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Supprimer définitivement</button>
                </div>
            </form>
        </div>
    </div>
</div>
