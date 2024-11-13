function showUserDetails(user) {
    document.getElementById('userName').innerText = user.prenom + ' ' + user.nom;
    document.getElementById('userEmail').innerText = user.email || 'N/A';
    document.getElementById('userPhone').innerText = user.telephone || 'N/A';
    document.getElementById('userAddress').innerText = user.adresse || 'N/A';
    document.getElementById('userRole').innerText = user.role?.role || 'N/A';
    document.getElementById('userStatus').innerText = user.is_active ? 'Active' : 'Inactive';

    document.getElementById('userModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeUserDetails() {
    document.getElementById('userModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function showEditUser(user) {
    document.getElementById('editUserName').value = user.prenom + ' ' + user.nom;
    document.getElementById('editUserEmail').value = user.email;
    document.getElementById('editUserRole').value = user.role?.role || 'technicien';

    document.getElementById('editUserModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeEditUser() {
    document.getElementById('editUserModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function saveUserChanges() {
    // Implémentez la logique de sauvegarde ici.
    closeEditUser();
}