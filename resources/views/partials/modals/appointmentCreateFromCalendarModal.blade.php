<div class="modal fade" id="appointmentCreateFromCalendarModal" tabindex="-1" aria-labelledby="appointmentCreateFromCalendarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentCreateFromCalendarModalLabel">Créer un rendez-vous</h5>
                <button type="button" class="btn" data-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>

            <form action="{{ route('appointment.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <!-- Liste des techniciens -->
                    <div class="mb-3">
                        <label for="tech_id_modal_calendar" class="form-label">Technicien</label>
                        <select class="form-control" id="tech_id_modal_calendar" name="tech_id" required>
                            @foreach ($selectedTechs as $techData)
                                <option value="{{ $techData['tech']->id }}">
                                    {{ $techData['tech']->user->prenom }} {{ $techData['tech']->user->nom }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Liste des services -->
                    <div class="mb-3">
                        <label for="service_id_calendar" class="form-label">Service</label>
                        <select class="form-control" id="service_id_calendar" name="service_id" required>
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
                            <label for="client_fname_calendar" class="form-label">Prénom</label>
                            <input type="text" class="form-control" id="client_fname_calendar" name="client_fname" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="client_lname_calendar" class="form-label">Nom</label>
                            <input type="text" class="form-control" id="client_lname_calendar" name="client_lname" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="client_adresse_calendar" class="form-label">Adresse</label>
                        <input type="text" class="form-control" id="client_adresse_calendar" name="client_adresse" readonly>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="client_zip_code_calendar" class="form-label">Code Postal</label>
                            <input type="text" class="form-control" id="client_zip_code_calendar" name="client_zip_code" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="client_city_calendar" class="form-label">Ville</label>
                            <input type="text" class="form-control" id="client_city_calendar" name="client_city" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="client_phone_calendar" class="form-label">Téléphone</label>
                        <input type="text" class="form-control" id="client_phone_calendar" name="client_phone" required>
                    </div>

                    <!-- Horaires -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_at_calendar" class="form-label">Débute à</label>
                            <input type="datetime-local" class="form-control" id="start_at_calendar" name="start_at" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="duration_calendar" class="form-label">Durée (en minutes)</label>
                            <input type="number" class="form-control" id="duration_calendar" name="duration" min="1" required>
                        </div>
                    </div>
                    <div class="col-12 mb-3">
                        <label for="end_at_calendar" class="form-label">Se termine à</label>
                        <input type="text" class="form-control" id="end_at_calendar" name="end_at" readonly>
                    </div>

                    <!-- Distance et Temps de Trajet -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="trajet_distance_calendar" class="form-label">Distance (km)</label>
                            <input type="text" class="form-control" id="trajet_distance_calendar" name="trajet_distance" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="trajet_time_calendar" class="form-label">Temps de trajet (min)</label>
                            <input type="text" class="form-control" id="trajet_time_calendar" name="trajet_time" readonly>
                        </div>
                    </div>

                    <!-- Commentaire -->
                    <div class="mb-3">
                        <label for="comment_calendar" class="form-label">Commentaire</label>
                        <textarea class="form-control" id="comment_calendar" name="comment" rows="3"></textarea>
                    </div>

                </div> <!-- modal-body -->

                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>
