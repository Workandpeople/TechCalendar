<!-- Formulaire de recherche / filtrage (HTML seulement, pas de JS inline) -->
<div class="card mb-4">
    <div class="card-body">
        <form id="searchForm" method="GET" action="{{ route('appointments.search') }}">
            <!-- Ligne 1 : Adresse, Code Postal, Ville -->
            <div class="row align-items-end">
                <div class="col-md-4 mb-3">
                    <label for="client_adresse" class="form-label">Adresse</label>
                    <input type="text" class="form-control" id="client_adresse" name="client_adresse">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="client_zip_code" class="form-label">Code Postal</label>
                    <input type="text" class="form-control" id="client_zip_code" name="client_zip_code">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="client_city" class="form-label">Ville</label>
                    <input type="text" class="form-control" id="client_city" name="client_city">
                </div>
            </div>

            <!-- Ligne 2 : Service, Durée -->
            <div class="row align-items-end">
                <div class="col-md-6 mb-3">
                    <label for="service_id" class="form-label">Service</label>
                    <select class="form-control" id="service_id2" name="service_id">
                        <option value="">-- Choisir un service --</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" data-duration="{{ $service->default_time }}">
                                {{ $service->type }} - {{ $service->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="duration" class="form-label">Durée (min)</label>
                    <input type="number" class="form-control" id="duration2" name="duration" placeholder="0">
                </div>
            </div>
            <div class="col-12 mb-3">
                <button type="submit" class="btn btn-primary w-100">Rechercher</button>
            </div>
        </form>
    </div>
</div>
