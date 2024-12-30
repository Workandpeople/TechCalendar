<div class="table-responsive">
    <table class="table table-striped table-hover text-center">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Nom Prénom</th>
                <th>Rôle</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $index => $user)
                <tr>
                    <td>{{ ($users->currentPage() - 1) * $users->perPage() + $index + 1 }}</td>
                    <td>{{ $user->prenom }} {{ $user->nom }}</td>
                    <td>{{ ucfirst($user->role) }}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="showUserDetails({{ json_encode($user) }})">Voir</button>
                        <button class="btn btn-sm btn-warning" onclick="showEditUser({{ json_encode($user) }})">Modifier</button>
                        <button class="btn btn-sm btn-danger" onclick="showDeleteConfirmation('{{ $user->id }}')">Supprimer</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="d-flex justify-content-center mt-3">
        {{ $users->onEachSide(1)->links('pagination::bootstrap-4') }}
    </div>
</div>