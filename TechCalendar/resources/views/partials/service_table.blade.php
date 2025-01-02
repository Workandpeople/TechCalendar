<div class="table-responsive">
    <table class="table table-striped table-hover text-center">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Type</th>
                <th>Nom</th>
                <th>Durée par défaut (minutes)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($services as $index => $service)
                <tr>
                    <td>{{ ($services->currentPage() - 1) * $services->perPage() + $index + 1 }}</td>
                    <td>{{ ucfirst($service->type) }}</td>
                    <td>{{ $service->name }}</td>
                    <td>{{ $service->default_time }}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="showServiceDetails({{ json_encode($service) }})">Voir</button>
                        <button class="btn btn-sm btn-warning" onclick="showEditService({{ json_encode($service) }})">Modifier</button>
                        <button class="btn btn-sm btn-danger" onclick="showDeleteConfirmation('{{ $service->id }}')">Supprimer</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="d-flex justify-content-center mt-3">
        {{ $services->onEachSide(1)->links('pagination::bootstrap-4') }}
    </div>
</div>