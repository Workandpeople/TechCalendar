<div class="modal fade" id="serviceDeleteModal" tabindex="-1" aria-labelledby="serviceDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceDeleteModalLabel">Supprimer un service</h5>
                <button type="button" class="btn btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="deleteServiceForm" method="POST">
                @csrf
                @method('DELETE')
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir désactiver ce service ? Cette action sera réversible.</p>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                </div>
            </form>
        </div>
    </div>
</div>
