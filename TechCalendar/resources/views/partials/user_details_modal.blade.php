<div class="modal fade" id="userDetailsModal" tabindex="-1" aria-labelledby="userDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userDetailsModalLabel">Détails de l'utilisateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <ul id="userDetailsList" class="list-group">
                    <!-- Les détails de l'utilisateur seront insérés dynamiquement ici -->
                </ul>
                <h6 id="techDetailsTitle" class="mt-4" style="display: none;">Détails du technicien</h6>
                <ul id="techDetailsList" class="list-group" style="display: none;">
                    <!-- Les détails du technicien seront insérés dynamiquement ici -->
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>