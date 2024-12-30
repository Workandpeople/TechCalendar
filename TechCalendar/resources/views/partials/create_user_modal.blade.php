<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createUserModalLabel">Créer un nouvel utilisateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form id="createUserForm">
                    @csrf
                    <div class="mb-3">
                        <label for="prenom" class="form-label">Prénom</label>
                        <input type="text" class="form-control" id="prenom" name="prenom" required>
                    </div>
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="nom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">Rôle</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="admin">Administrateur</option>
                            <option value="assistante">Assistante</option>
                            <option value="tech">Technicien</option>
                        </select>
                    </div>
                    <div id="techFields" style="display: none;">
                        <div class="mb-3">
                            <label for="phone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="phone" name="phone">
                        </div>
                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse</label>
                            <input type="text" class="form-control" id="adresse" name="adresse">
                        </div>
                        <div class="mb-3">
                            <label for="zip_code" class="form-label">Code Postal</label>
                            <input type="text" class="form-control" id="zip_code" name="zip_code">
                        </div>
                        <div class="mb-3">
                            <label for="city" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="city" name="city">
                        </div>
                        <div class="row">
                            <div class="col">
                                <label for="default_start_at" class="form-label">Début par défaut</label>
                                <input type="time" class="form-control" id="default_start_at" name="default_start_at">
                            </div>
                            <div class="col">
                                <label for="default_end_at" class="form-label">Fin par défaut</label>
                                <input type="time" class="form-control" id="default_end_at" name="default_end_at">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="default_rest_time" class="form-label">Temps de repos (min)</label>
                            <input type="number" class="form-control" id="default_rest_time" name="default_rest_time">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Mot de passe</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirmer le mot de passe</label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveNewUser()">Créer</button>
            </div>
        </div>
    </div>
</div>