<table class="table table-striped table-bordered table-responsive-md">
    <thead class="thead-dark">
        <tr>
            <th>
                <a href="?sort=type&direction={{ request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Type
                    @if(request('sort') === 'type')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>
                <a href="?sort=name&direction={{ request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Nom
                    @if(request('sort') === 'name')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>
                <a href="?sort=default_time&direction={{ request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Temps par défaut
                    @if(request('sort') === 'default_time')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($services as $service)
            <tr class="{{ $service->trashed() ? 'table-warning' : '' }}">
                <td>{{ ucfirst($service->type) }}</td>
                <td>{{ ucfirst($service->name) }}</td>
                <td>{{ $service->default_time }} min</td>
                <td>
                    <div class="btn-group">
                        @if (!$service->trashed())
                            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#serviceEditModal" data-id="{{ $service->id }}">
                                Modifier
                            </button>
                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#serviceDeleteModal" data-id="{{ $service->id }}">
                                Désactiver
                            </button>
                        @else
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#serviceRestoreModal" data-id="{{ $service->id }}">
                                Restaurer
                            </button>
                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#serviceHardDeleteModal" data-id="{{ $service->id }}">
                                Supprimer définitivement
                            </button>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<!-- Pagination -->
<div class="d-flex justify-content-center">
    {{ $services->withQueryString()->links() }}
</div>
