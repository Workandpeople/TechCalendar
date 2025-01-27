<div class="modal fade" id="userCreateModal" tabindex="-1" aria-labelledby="userCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userCreateModalLabel">Créer un utilisateur</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form action="{{ route('manage-users.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="nom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="prenom" class="form-label">Prénom</label>
                        <input type="text" class="form-control" id="prenom" name="prenom" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Mot de passe</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">Rôle</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="admin">Admin</option>
                            <option value="assistante">Assistante</option>
                            <option value="tech">Technicien</option>
                        </select>
                    </div>
                    <div id="tech-fields" class="d-none">
                        <div class="mb-3">
                            <label for="phone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="phone" name="phone">
                        </div>
                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse</label>
                            <input type="text" class="form-control" id="adresse" name="adresse">
                        </div>
                        <div class="mb-3">
                            <label for="zip_code" class="form-label">Code postal</label>
                            <input type="text" class="form-control" id="zip_code" name="zip_code">
                        </div>
                        <div class="mb-3">
                            <label for="city" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="city" name="city">
                        </div>
                        <div class="mb-3">
                            <label for="default_start_at" class="form-label">Début de journée</label>
                            <input type="time" class="form-control" id="default_start_at" name="default_start_at" value="08:30">
                        </div>
                        <div class="mb-3">
                            <label for="default_end_at" class="form-label">Fin de journée</label>
                            <input type="time" class="form-control" id="default_end_at" name="default_end_at" value="17:30">
                        </div>
                        <div class="mb-3">
                            <label for="default_rest_time" class="form-label">Durée de pause (minutes)</label>
                            <input type="number" class="form-control" id="default_rest_time" name="default_rest_time" value="60">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>
