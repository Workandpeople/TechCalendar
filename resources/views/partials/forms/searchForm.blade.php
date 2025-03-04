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
            <div class="col-12 mb-3">
                <button type="submit" class="btn btn-primary w-100">Rechercher</button>
            </div>
        </form>
    </div>
</div>
