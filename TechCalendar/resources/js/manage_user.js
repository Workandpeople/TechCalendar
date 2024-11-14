function showUserDetails(user) {
    document.getElementById('userName').innerText = user.prenom + ' ' + user.nom;
    document.getElementById('userEmail').innerText = user.email || 'N/A';
    document.getElementById('userPhone').innerText = user.telephone || 'N/A';
    document.getElementById('userAddress').innerText = user.adresse || 'N/A';
    document.getElementById('userPostalCode').innerText = user.code_postal || 'N/A';
    document.getElementById('userCity').innerText = user.ville || 'N/A';
    document.getElementById('userRole').innerText = user.role?.role || 'N/A';
    document.getElementById('userDefaultStartAt').innerText = user.default_start_at || 'N/A';
    document.getElementById('userDefaultEndAt').innerText = user.default_end_at || 'N/A';
    document.getElementById('userTrajectTime').innerText = user.default_traject_time || 'N/A';
    document.getElementById('userRestTime').innerText = user.default_rest_time || 'N/A';
    //document.getElementById('userStatus').innerText = user.is_active ? 'Active' : 'Inactive';

    document.getElementById('userModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeUserDetails() {
    document.getElementById('userModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function showEditUser(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUserPrenom').value = user.prenom || '';
    document.getElementById('editUserNom').value = user.nom || '';
    document.getElementById('editUserEmail').value = user.email || '';
    document.getElementById('editUserPhone').value = user.telephone || '';
    document.getElementById('editUserAddress').value = user.adresse || '';
    document.getElementById('editUserPostalCode').value = user.code_postal || '';
    document.getElementById('editUserCity').value = user.ville || '';
    document.getElementById('editUserRole').value = user.role?.role || 'technicien';
    document.getElementById('editUserDefaultStartAt').value = user.default_start_at || '';
    document.getElementById('editUserDefaultEndAt').value = user.default_end_at || '';
    document.getElementById('editUserTrajectTime').value = user.default_traject_time || '';
    document.getElementById('editUserRestTime').value = user.default_rest_time || '';

    document.getElementById('editUserModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeEditUser() {
    document.getElementById('editUserModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function saveUserChanges() {
    console.log(document.getElementById('editUserId')); // Debugging
    
    const userId = document.getElementById('editUserId').value;
    const data = {
        nom: document.getElementById('editUserNom')?.value,
        prenom: document.getElementById('editUserPrenom')?.value,
        email: document.getElementById('editUserEmail')?.value,
        role: document.getElementById('editUserRole')?.value,
        telephone: document.getElementById('editUserPhone')?.value,
        adresse: document.getElementById('editUserAddress')?.value,
        code_postal: document.getElementById('editUserPostalCode')?.value,
        ville: document.getElementById('editUserCity')?.value,
        default_start_at: document.getElementById('editUserDefaultStartAt')?.value,
        default_end_at: document.getElementById('editUserDefaultEndAt')?.value,
        default_traject_time: document.getElementById('editUserTrajectTime')?.value,
        default_rest_time: document.getElementById('editUserRestTime')?.value
    };

    console.log(data); // Verify collected data

    fetch(`/admin/manage-user/update/${userId}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        closeEditUser();
        location.reload();
    })
    .catch(error => console.error('Erreur:', error));
}

let userIdToDelete = null; // Variable temporaire pour stocker l'ID

function deleteUser(userId) {
    userIdToDelete = userId; // Stocker l'ID du user
    document.getElementById('deleteConfirmationModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeDeleteConfirmation() {
    document.getElementById('deleteConfirmationModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function confirmDeleteUser() {
    if (userIdToDelete === null) return;

    fetch(`/admin/manage-user/delete/${userIdToDelete}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        closeDeleteConfirmation();
        location.reload();
    })
    .catch(error => console.error('Erreur:', error));
}

function toggleFieldsBasedOnRole() {
    const role = document.getElementById('createUserRole').value;
    const techFields = document.getElementById('techFields');
    
    if (role === 'technicien') {
        techFields.style.display = 'block';
    } else {
        techFields.style.display = 'none';
    }
}

function showCreateUser() {
    document.getElementById('createUserModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeCreateUser() {
    document.getElementById('createUserModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function saveNewUser() {
    const data = {
        prenom: document.getElementById('createUserPrenom').value,
        nom: document.getElementById('createUserNom').value,
        email: document.getElementById('createUserEmail').value,
        role: document.getElementById('createUserRole').value,
        password: document.getElementById('createUserPassword').value,
        password_confirmation: document.getElementById('createUserPasswordConfirm').value
    };

    // Log to check the role value
    console.log("Role selected:", data.role);

    // Ajouter les champs techniques si le rôle est technicien
    if (data.role === 'technicien') {
        data.telephone = document.getElementById('createUserPhone').value;
        data.adresse = document.getElementById('createUserAddress').value;
        data.code_postal = document.getElementById('createUserPostalCode').value;
        data.ville = document.getElementById('createUserCity').value;
        data.default_start_at = document.getElementById('createUserDefaultStartAt').value;
        data.default_end_at = document.getElementById('createUserDefaultEndAt').value;
        data.default_traject_time = document.getElementById('createUserTrajectTime').value;
        data.default_rest_time = document.getElementById('createUserRestTime').value;
    }

    fetch(`/admin/manage-user/create`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        closeCreateUser();
        location.reload();
    })
    .catch(error => console.error('Erreur:', error));
}