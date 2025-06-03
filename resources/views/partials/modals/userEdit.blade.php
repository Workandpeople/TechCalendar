<div class="modal fade" id="userEditModal" tabindex="-1" aria-labelledby="userEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userEditModalLabel">Modifier un utilisateur</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form action="{{ route('manage-users.update', ':id') }}" method="POST" id="edit-user-form">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <input type="hidden" id="edit-id" name="id">
                    <div class="mb-3">
                        <label for="edit-nom" class="form-label">Nom</label>
                        <input type="text" class="form-control" id="edit-nom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-prenom" class="form-label">Prénom</label>
                        <input type="text" class="form-control" id="edit-prenom" name="prenom" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="edit-email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-role" class="form-label">Rôle</label>
                        <select class="form-control" id="edit-role" name="role" required>
                            <option value="admin">Admin</option>
                            <option value="assistante">Assistante</option>
                            <option value="tech">Technicien</option>
                        </select>
                    </div>
                    <!-- Champs spécifiques pour le technicien -->
                    <div id="edit-tech-fields" class="d-none">
                        <div class="mb-3">
                            <label for="edit-phone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="edit-phone" name="phone">
                        </div>
                        <div class="mb-3">
                            <label for="edit-adresse" class="form-label">Adresse</label>
                            <input type="text" class="form-control" id="edit-adresse" name="adresse">
                        </div>
                        <div class="mb-3">
                            <label for="edit-zip_code" class="form-label">Code postal</label>
                            <input type="text" class="form-control" id="edit-zip_code" name="zip_code">
                        </div>
                        <div class="mb-3">
                            <label for="edit-city" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="edit-city" name="city">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Début de journée</label>
                            <div class="d-flex" style="gap: 2rem;">
                                <select id="edit_start_hour" class="form-control">
                                    @for ($h = 7; $h <= 22; $h++)
                                        <option value="{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}h</option>
                                    @endfor
                                </select>
                                <select id="edit_start_minute" class="form-control">
                                    <option value="00">00</option>
                                    <option value="30">30</option>
                                </select>
                                <input type="hidden" id="edit_default_start_at" name="default_start_at" value="08:30">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Fin de journée</label>
                            <div class="d-flex" style="gap: 2rem;">
                                <select id="edit_end_hour" class="form-control">
                                    @for ($h = 7; $h <= 22; $h++)
                                        <option value="{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}h</option>
                                    @endfor
                                </select>
                                <select id="edit_end_minute" class="form-control">
                                    <option value="00">00</option>
                                    <option value="30">30</option>
                                </select>
                                <input type="hidden" id="edit_default_end_at" name="default_end_at" value="17:30">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit-default_rest_time" class="form-label">Durée de pause (minutes)</label>
                            <input type="number" class="form-control" id="edit-default_rest_time" name="default_rest_time">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>
