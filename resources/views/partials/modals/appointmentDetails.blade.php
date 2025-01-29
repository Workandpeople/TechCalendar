<!-- Modal d'affichage des détails du rendez-vous -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentModalLabel">Détails du Rendez-vous</h5>
                <button type="button" class="btn" data-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                    <i class="fas fa-times fa-lg text-dark"></i>
                </button>
            </div>
            <div class="modal-body">
                <p><strong>Client :</strong> <span id="modalClientName"></span></p>
                <p><strong>Adresse du Client :</strong> <span id="modalClientAddress"></span></p>
                <p><strong>Technicien :</strong> <span id="modalTechName"></span></p>
                <p><strong>Service :</strong> <span id="modalService"></span></p>
                <p><strong>Date :</strong> <span id="modalDate"></span></p>
                <p><strong>Heure :</strong> <span id="modalTime"></span></p>
                <p><strong>Commentaire :</strong> <span id="modalComment"></span></p>
            </div>
        </div>
    </div>
</div>
