<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Rechercher une disponibilité</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('assistant.submit_appointment') }}" id="searchForm">
            @csrf
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
            <div class="form-row">
                <div class="form-group col-md-6 col-12">
                    <label for="searchServiceId">Prestation</label>
                    <select id="searchServiceId" name="serviceId" class="form-control" required>
                        <option value="" disabled selected>Choisissez une prestation</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" data-duration="{{ $service->default_time }}">{{ $service->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-6 col-12">
                    <label for="searchDuration">Durée (minutes)</label>
                    <input type="number" id="searchDuration" name="duration" class="form-control" min="1" required>
                </div>
            </div>
            <button type="button" class="btn btn-primary" onclick="searchTechnicians()">Rechercher</button>
        </form>
    </div>
</div>