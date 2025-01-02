<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Placer un rendez-vous manuellement</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('assistant.manual_appointment') }}">
            @csrf
            <!-- Ligne pour Prénom, Nom, Téléphone -->
            <div class="form-row">
                <div class="form-group col-md-4 col-6">
                    <label for="clientFirstName">Prénom du client</label>
                    <input type="text" id="clientFirstName" name="clientFirstName" class="form-control" required>
                </div>
                <div class="form-group col-md-4 col-6">
                    <label for="clientLastName">Nom du client</label>
                    <input type="text" id="clientLastName" name="clientLastName" class="form-control" required>
                </div>
                <div class="form-group col-md-4 col-12">
                    <label for="clientPhone">Téléphone du client</label>
                    <input type="text" id="clientPhone" name="clientPhone" class="form-control" required>
                </div>
            </div>

            <!-- Ligne pour Adresse, Code Postal, Ville -->
            <div class="form-row">
                <div class="form-group col-md-6 col-12">
                    <label for="clientAddressStreet">Adresse (Rue)</label>
                    <input type="text" id="clientAddressStreet" name="clientAddressStreet" class="form-control" required>
                </div>
                <div class="form-group col-md-3 col-6">
                    <label for="clientAddressPostalCode">Code postal</label>
                    <input type="text" id="clientAddressPostalCode" name="clientAddressPostalCode" class="form-control" required>
                </div>
                <div class="form-group col-md-3 col-6">
                    <label for="clientAddressCity">Ville</label>
                    <input type="text" id="clientAddressCity" name="clientAddressCity" class="form-control" required>
                </div>
            </div>

            <!-- Ligne pour le technicien et la prestation -->
            <div class="form-row">
                <div class="form-group col-md-4 col-12">
                    <label for="techId">Technicien</label>
                    <select id="techId" name="techId" class="form-control" required>
                        <option value="" disabled selected>Choisissez un technicien</option>
                        @foreach ($technicians as $tech)
                            <option value="{{ $tech->id }}">{{ $tech->user->prenom }} {{ $tech->user->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-4 col-12">
                    <label for="manualServiceId">Prestation</label>
                    <select id="manualServiceId" name="serviceId" class="form-control" required>
                        <option value="" disabled selected>Choisissez une prestation</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" data-duration="{{ $service->default_time }}">{{ $service->name }} ({{ $service->type }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-4 col-12">
                    <label for="manualDuration">Durée (minutes)</label>
                    <input type="number" id="manualDuration" name="duration" class="form-control" min="1" required>
                </div>
            </div>

            <!-- Ligne pour la date, heure de début et heure de fin -->
            <div class="form-row">
                <div class="form-group col-md-4 col-12">
                    <label for="appointmentDate">Date</label>
                    <input type="date" id="appointmentDate" name="appointmentDate" class="form-control" required>
                </div>
                <div class="form-group col-md-4 col-12">
                    <label for="manualStartTime">Heure de début</label>
                    <input type="time" id="manualStartTime" name="startTime" class="form-control" required>
                </div>
                <div class="form-group col-md-4 col-12">
                    <label for="manualEndTime">Heure de fin</label>
                    <input type="time" id="manualEndTime" name="endTime" class="form-control" readonly>
                </div>
            </div>

            <!-- Ligne pour la durée et le commentaire -->
            <div class="form-row">
                <div class="form-group col-md-12 col-12">
                    <label for="comments">Commentaires</label>
                    <textarea id="comments" name="comments" class="form-control" rows="3"></textarea>
                </div>
            </div>

            <!-- Bouton de soumission -->
            <button type="submit" class="btn btn-success">Placer le rendez-vous</button>
        </form>
    </div>
</div>