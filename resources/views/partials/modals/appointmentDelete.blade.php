<div class="modal fade" id="appointmentDeleteModal" tabindex="-1" aria-labelledby="appointmentDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentDeleteModalLabel">Supprimer un rendez-vous</h5>
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <form id="deleteAppointmentForm" method="POST">
                @csrf
                @method('DELETE')
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir mettre en attente ce rendez-vous ?</p>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                </div>
            </form>
        </div>
    </div>
</div>
