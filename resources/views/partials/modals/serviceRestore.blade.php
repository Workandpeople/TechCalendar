<div class="modal fade" id="serviceRestoreModal" tabindex="-1" aria-labelledby="serviceRestoreModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceRestoreModalLabel">Restaurer un service</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form id="restoreServiceForm" method="POST">
                @csrf
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir restaurer ce service ?</p>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Restaurer</button>
                </div>
            </form>
        </div>
    </div>
</div>
