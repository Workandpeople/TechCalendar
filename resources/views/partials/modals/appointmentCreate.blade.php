<div class="modal fade" id="appointmentCreateModal" tabindex="-1" aria-labelledby="appointmentCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentCreateModalLabel">Créer un rendez-vous</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form action="{{ route('manage-appointments.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <!-- Recherche Technicien -->
                    <div class="mb-3">
                        <label for="tech_search" class="form-label">Rechercher un technicien</label>
                        <input type="text" class="form-control" id="tech_search" placeholder="Rechercher...">
                        <select class="form-control mt-2" id="tech_id" name="tech_id">
                            <option value="">Non attribué</option>
                            @foreach ($technicians as $tech)
                                <option value="{{ $tech->id }}">{{ $tech->user->nom }} {{ $tech->user->prenom }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Liste des services -->
                    <div class="mb-3">
                        <label for="service_id" class="form-label">Service</label>
                        <select class="form-control" id="service_id" name="service_id" required>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}" data-duration="{{ $service->default_time }}">
                                    {{ $service->type }} - {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Informations Client -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="client_fname" class="form-label">Prénom</label>
                            <input type="text" class="form-control" id="client_fname" name="client_fname" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="client_lname" class="form-label">Nom</label>
                            <input type="text" class="form-control" id="client_lname" name="client_lname" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="client_adresse" class="form-label">Adresse</label>
                        <input type="text" class="form-control" id="client_adresse" name="client_adresse" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="client_zip_code" class="form-label">Code Postal</label>
                            <input type="text" class="form-control" id="client_zip_code" name="client_zip_code" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="client_city" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="client_city" name="client_city" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="client_phone" class="form-label">Téléphone</label>
                        <input type="text" class="form-control" id="client_phone" name="client_phone" required>
                    </div>

                    <!-- Horaires -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_at" class="form-label">Débute à</label>
                            <input type="datetime-local" class="form-control" id="start_at" name="start_at" required>
                        </div>
                        <!-- Durée -->
                        <div class="col-12 col-md-6 mb-3">
                            <label for="duration" class="form-label">Durée (en minutes)</label>
                            <input type="number" class="form-control" id="duration" name="duration" min="1" required>
                        </div>
                    </div>
                    <div class="col-12 mb-3">
                        <label for="end_at" class="form-label">Se termine à</label>
                        <input type="text" class="form-control" id="end_at" name="end_at" readonly>
                    </div>

                    <!-- Commentaire -->
                    <div class="mb-3">
                        <label for="comment" class="form-label">Commentaire</label>
                        <textarea class="form-control" id="comment" name="comment" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>
