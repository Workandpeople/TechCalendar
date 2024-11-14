function showEditPresta(presta) {
    document.getElementById('editPrestaId').value = presta.id;
    document.getElementById('editPrestaName').value = presta.name;
    document.getElementById('editPrestaDefaultTime').value = presta.default_time;

    // Sélectionner la bonne option pour le type
    const typeSelect = document.getElementById('editPrestaType');
    if (typeSelect) {
        Array.from(typeSelect.options).forEach(option => {
            option.selected = option.value === presta.type;
        });
    } else {
        console.error("L'élément avec l'id 'editPrestaType' est introuvable.");
    }

    document.getElementById('editPrestaModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function savePrestaChanges() {
    const prestaId = document.getElementById('editPrestaId').value;
    const data = {
        type: document.getElementById('editPrestaType').value,
        name: document.getElementById('editPrestaName').value,
        default_time: document.getElementById('editPrestaDefaultTime').value
    };

    fetch(`/admin/manage-presta/update/${prestaId}`, {
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
        closeEditPresta();
        location.reload();
    })
    .catch(error => console.error('Erreur:', error));
}

let prestaIdToDelete = null; // Variable temporaire pour stocker l'ID

function deletePresta(prestaId) {
    prestaIdToDelete = prestaId; // Stocker l'ID de la prestation
    document.getElementById('deleteConfirmationModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeDeleteConfirmation() {
    document.getElementById('deleteConfirmationModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function confirmDeletePresta() {
    if (prestaIdToDelete === null) return;

    fetch(`/admin/manage-presta/delete/${prestaIdToDelete}`, {
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

function closeEditPresta() {
    document.getElementById('editPrestaModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function showCreatePresta() {
    document.getElementById('createPrestaModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeCreatePresta() {
    document.getElementById('createPrestaModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function saveNewPresta() {
    const data = {
        type: document.getElementById('createPrestaType').value,
        name: document.getElementById('createPrestaName').value,
        default_time: document.getElementById('createPrestaDefaultTime').value
    };

    fetch(`/admin/manage-presta/create`, {
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
        closeCreatePresta();
        location.reload();
    })
    .catch(error => console.error('Erreur:', error));
}