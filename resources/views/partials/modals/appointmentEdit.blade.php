<div class="modal fade" id="appointmentEditModal" tabindex="-1" aria-labelledby="appointmentEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentEditModalLabel">Modifier un rendez-vous</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form id="editAppointmentForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body">

                    <!-- Champ Service -->
                    <div class="mb-3">
                        <label for="edit-service_id" class="form-label">Service</label>
                        <select class="form-control" id="edit-service_id" name="service_id" required>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}" data-duration="{{ $service->default_time }}">
                                    {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Champs Client -->
                    <div class="mb-3">
                        <label for="edit-client_fname" class="form-label">Prénom du client</label>
                        <input type="text" class="form-control" id="edit-client_fname" name="client_fname" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-client_lname" class="form-label">Nom du client</label>
                        <input type="text" class="form-control" id="edit-client_lname" name="client_lname" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-client_adresse" class="form-label">Adresse</label>
                        <input type="text" class="form-control" id="edit-client_adresse" name="client_adresse" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit-client_zip_code" class="form-label">Code postal</label>
                            <input type="text" class="form-control" id="edit-client_zip_code" name="client_zip_code" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit-client_city" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="edit-client_city" name="client_city" required>
                        </div>
                    </div>

                    <!-- Horaires -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit-start_at" class="form-label">Débute à</label>
                            <input type="datetime-local" class="form-control" id="edit-start_at" name="start_at" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit-duration" class="form-label">Durée (en minutes)</label>
                            <input type="number" class="form-control" id="edit-duration" name="duration" min="1" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit-end_at" class="form-label">Se termine à</label>
                        <input type="text" class="form-control" id="edit-end_at" name="end_at" readonly>
                    </div>

                    <!-- Commentaire -->
                    <div class="mb-3">
                        <label for="edit-comment" class="form-label">Commentaire</label>
                        <textarea class="form-control" id="edit-comment" name="comment" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>
