<div id="tracking-edit-modal" class="gc-modal hidden" role="dialog" aria-modal="true" aria-labelledby="tracking-edit-title">
    <div class="gc-modal-panel gc-modal-panel-xl gc-form-modal">
        <h2 id="tracking-edit-title" class="text-lg font-semibold">Modifier le rendez-vous</h2>
        <form id="tracking-details-form" data-validate-form>
            <div class="gc-form-modal-content space-y-4">
                <p class="text-sm text-slate-500">Ce formulaire modifie les informations de cette visite. Les coordonnées de référence du bénéficiaire et du site se gèrent dans la fiche dossier.</p>
                <input id="tracking_edit_updated_at" type="hidden">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div><label class="gc-label" for="tracking_edit_customer_first_name">Prénom / bénéficiaire</label><input id="tracking_edit_customer_first_name" class="gc-input" maxlength="120"></div>
                    <div><label class="gc-label" for="tracking_edit_customer_last_name">Nom / raison sociale</label><input id="tracking_edit_customer_last_name" class="gc-input" maxlength="120"></div>
                    <div><label class="gc-label" for="tracking_edit_customer_phone">Téléphone</label><input id="tracking_edit_customer_phone" class="gc-input" maxlength="255"></div>
                    <div>
                        <label class="gc-label" for="tracking_edit_service_id">Prestation</label>
                        <select id="tracking_edit_service_id" class="gc-input" required>
                            @foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="gc-label" for="tracking_edit_technician_id">Technicien</label>
                        <select id="tracking_edit_technician_id" class="gc-input" required>
                            <option value="">Choisir un technicien</option>
                            @foreach($technicians->whereNull('deleted_at') as $technician)
                                <option value="{{ $technician->id }}" data-service-ids="{{ $technician->services->pluck('id')->implode(',') }}">{{ $technician->full_name_with_departments }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Les contraintes de planning sont vérifiées à l’enregistrement.</p>
                    </div>
                    <div><label class="gc-label" for="tracking_detail_starts_at">Date et heure</label><input id="tracking_detail_starts_at" type="datetime-local" class="gc-input" required></div>
                    <div><label class="gc-label" for="tracking_detail_duration_minutes">Durée (minutes)</label><input id="tracking_detail_duration_minutes" type="number" min="5" max="1440" class="gc-input" required></div>
                    <div class="relative md:col-span-2">
                        <label class="gc-label" for="tracking_detail_address_input">Adresse de l’intervention</label>
                        <input id="tracking_detail_address_input" class="gc-input" maxlength="255" required autocomplete="off">
                        <input id="tracking_detail_latitude" type="hidden"><input id="tracking_detail_longitude" type="hidden">
                        <p class="mt-1 text-xs text-slate-500">Sélectionne une suggestion Mapbox pour actualiser les coordonnées.</p>
                    </div>
                </div>
                <div><label class="gc-label" for="tracking_edit_comment">Commentaire</label><textarea id="tracking_edit_comment" class="gc-input" rows="4" maxlength="5000"></textarea></div>
                <p id="tracking_details_status" role="status" class="hidden text-sm"></p>
            </div>
            <div class="gc-modal-actions">
                <button type="button" data-tracking-edit-close class="gc-btn-soft">Annuler</button>
                <button id="tracking-save-details-btn" type="submit" class="gc-btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
