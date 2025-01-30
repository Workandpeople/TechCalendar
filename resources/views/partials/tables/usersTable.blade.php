<table class="table table-striped table-bordered table-responsive-md">
    <thead class="thead-dark">
        <tr>
            <th>
                <a href="?sort=nom&direction={{ request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Nom Prénom
                    @if(request('sort') === 'nom')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th class="d-none d-md-table-cell">
                <a href="?sort=email&direction={{ request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Email
                    @if(request('sort') === 'email')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>Password</th>
            <th class="d-none d-md-table-cell">
                <a href="?sort=role&direction={{ request('direction') === 'asc' ? 'desc' : 'asc' }}">
                    Rôle
                    @if(request('sort') === 'role')
                        <i class="fas fa-arrow-{{ request('direction') === 'asc' ? 'up' : 'down' }}"></i>
                    @endif
                </a>
            </th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($users as $user)
            <tr class="{{ $user->trashed() ? 'table-warning' : '' }}">
                <td>
                    <strong>{{ strtoupper($user->nom) }}</strong> {{ ucfirst($user->prenom) }}
                </td>
                <td class="d-none d-md-table-cell">{{ $user->email }}</td>
                <td>
                    @if (!$user->trashed())
                        <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#userEditPasswordModal" data-id="{{ $user->id }}">
                            Modifier
                        </button>
                    @endif
                </td>
                <td class="d-none d-md-table-cell">{{ ucfirst($user->role) }}</td>
                <td>
                    <div class="btn-group">
                        @if (!$user->trashed())
                            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#userEditModal" data-id="{{ $user->id }}">
                                Modifier
                            </button>
                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#userDeleteModal" data-id="{{ $user->id }}">
                                Désactiver
                            </button>
                        @else
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#userRestoreModal" data-id="{{ $user->id }}">
                                Restaurer
                            </button>
                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#userHardDeleteModal" data-id="{{ $user->id }}">
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
    {{ $users->withQueryString()->links() }}
</div>
