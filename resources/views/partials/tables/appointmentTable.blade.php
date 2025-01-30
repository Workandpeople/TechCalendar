<table class="table table-striped table-bordered table-responsive-md">
    <thead class="thead-dark">
        <tr>
            <th>
                <a href="?sort=tech&direction={{ request('sort') === 'tech' && request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Technicien
                    @if(request('sort') === 'tech')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>
                <a href="?sort=service&direction={{ request('sort') === 'service' && request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Service
                    @if(request('sort') === 'service')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>
                <a href="?sort=client&direction={{ request('sort') === 'client' && request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Client
                    @if(request('sort') === 'client')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>
                <a href="?sort=start_at&direction={{ request('sort') === 'start_at' && request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Horaires
                    @if(request('sort') === 'start_at')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($appointments as $appointment)
            <tr class="{{ $appointment->trashed() ? 'table-warning' : '' }}">
                <td>
                    <strong>{{ strtoupper($appointment->tech->user->nom ?? 'Non attribué') }}</strong> {{ ucfirst($appointment->tech->user->prenom ?? '') }}
                    <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#reassignTechModal"
                        data-id="{{ $appointment->id }}"
                        data-tech="{{ $appointment->tech ? $appointment->tech->user->nom . ' ' . $appointment->tech->user->prenom : '' }}">
                        Réattribuer
                    </button>
                    </td>
                <td>
                    {{ $appointment->service->type ?? 'N/A' }} - {{ $appointment->service->name ?? 'Aucun service' }}
                </td>
                <td>
                    <strong>{{ $appointment->client_fname }} {{ $appointment->client_lname }}</strong>
                    <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#viewClientModal" data-id="{{ $appointment->id }}">
                        Voir
                    </button>
                </td>
                <td>
                    Le {{ \Carbon\Carbon::parse($appointment->start_at)->format('d-m-Y') }}
                    du {{ \Carbon\Carbon::parse($appointment->start_at)->format('H\hi') }}
                    au {{ \Carbon\Carbon::parse($appointment->end_at)->format('H\hi') }}
                </td>
                <td>
                    <div class="btn-group">
                        @if (!$appointment->trashed())
                            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#appointmentEditModal" data-id="{{ $appointment->id }}">
                                Modifier
                            </button>
                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#appointmentDeleteModal" data-id="{{ $appointment->id }}">
                                Mettre en attente
                            </button>
                        @else
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#appointmentRestoreModal" data-id="{{ $appointment->id }}">
                                Restaurer
                            </button>
                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#appointmentHardDeleteModal" data-id="{{ $appointment->id }}">
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
<div class="pagination-container d-flex justify-content-center mt-3">
    {{ $appointments->withQueryString()->links() }}
</div>
