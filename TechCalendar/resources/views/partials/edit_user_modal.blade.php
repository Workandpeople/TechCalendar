<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">Modifier l'utilisateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form id="editUserForm" method="POST">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" id="editUserId" name="user_id" value="">
                    <div class="mb-3">
                        <label for="editPrenom" class="form-label">Prénom</label>
                        <input type="text" class="form-control" id="editPrenom" name="prenom" value="" required>
                    </div>
                    <div class="mb-3">
                        <label for="editNom" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="editNom" name="nom" value="" required>
                    </div>
                    <div class="mb-3">
                        <label for="editEmail" class="form-label">Email</label>
                        <input type="email" class="form-control" id="editEmail" name="email" value="" required>
                    </div>
                    <div class="mb-3">
                        <label for="editRole" class="form-label">Rôle</label>
                        <select class="form-select" id="editRole" name="role" required>
                            <option value="admin">Administrateur</option>
                            <option value="assistante">Assistante</option>
                            <option value="tech">Technicien</option>
                        </select>
                    </div>
                    <div id="editTechFields" style="display: none;">
                        <div class="mb-3">
                            <label for="editPhone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="editPhone" name="phone" value="">
                        </div>
                        <div class="mb-3">
                            <label for="editAdresse" class="form-label">Adresse</label>
                            <input type="text" class="form-control" id="editAdresse" name="adresse" value="">
                        </div>
                        <div class="mb-3">
                            <label for="editZipCode" class="form-label">Code Postal</label>
                            <input type="text" class="form-control" id="editZipCode" name="zip_code" value="">
                        </div>
                        <div class="mb-3">
                            <label for="editCity" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="editCity" name="city" value="">
                        </div>
                        <div class="row">
                            <div class="col">
                                <label for="editDefaultStartAt" class="form-label">Début par défaut</label>
                                <input type="time" class="form-control" id="editDefaultStartAt" name="default_start_at" value="">
                            </div>
                            <div class="col">
                                <label for="editDefaultEndAt" class="form-label">Fin par défaut</label>
                                <input type="time" class="form-control" id="editDefaultEndAt" name="default_end_at" value="">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editDefaultRestTime" class="form-label">Temps de repos (min)</label>
                            <input type="number" class="form-control" id="editDefaultRestTime" name="default_rest_time" value="">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveUserChanges()">Enregistrer</button>
            </div>
        </div>
    </div>
</div>