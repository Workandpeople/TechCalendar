<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Gérant</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Gestion des prestations</h1>
        </div>

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#f5c2c7;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        <section class="gc-card p-4">
            <form id="service-filters-form" method="GET" action="{{ route('manager.services') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" class="gc-input" placeholder="Nom de la prestation" />
                </div>

                <div>
                    <label class="gc-label" for="type">Type</label>
                    <select id="type" name="type" class="gc-input">
                        <option value="">Tous</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2 flex items-center justify-between">
                    <button type="button" class="gc-btn-primary" data-modal-open="create-service-modal">Créer une prestation</button>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('manager.services') }}" class="gc-link">Réinitialiser les filtres</a>
                    </div>
                </div>
            </form>
        </section>

        <section class="gc-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b" style="border-color:var(--gc-border);background:#f8f8f8;">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Type</th>
                            <th class="px-4 py-3 font-semibold">Nom de la prestation</th>
                            <th class="px-4 py-3 font-semibold">Temps moyen</th>
                            <th class="px-4 py-3 font-semibold">Alias Coffrac</th>
                            <th class="px-4 py-3 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($services as $service)
                            <tr class="border-b last:border-b-0" style="border-color:var(--gc-border);">
                                <td class="px-4 py-3">{{ $service->type }}</td>
                                <td class="px-4 py-3">{{ $service->name }}</td>
                                <td class="px-4 py-3">{{ $service->average_duration_minutes }} min</td>
                                <td class="px-4 py-3">
                                    @if ($service->externalAliases->isNotEmpty())
                                        <div class="flex max-w-lg flex-wrap gap-1">
                                            @foreach ($service->externalAliases->take(3) as $alias)
                                                <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">{{ $alias->external_name }}</span>
                                            @endforeach
                                            @if ($service->externalAliases->count() > 3)
                                                <span class="rounded-lg px-2 py-1 text-xs" style="background:#e0f2fe;color:#0369a1;">+{{ $service->externalAliases->count() - 3 }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-sm" style="color:var(--gc-text-soft);">Aucun alias</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="gc-btn-soft"
                                            data-modal-open="edit-service-modal"
                                            data-service-id="{{ $service->id }}"
                                            data-service-type="{{ $service->type }}"
                                            data-service-name="{{ $service->name }}"
                                            data-service-duration="{{ $service->average_duration_minutes }}"
                                            data-service-aliases='@json($service->externalAliases->map(fn ($alias) => $alias->external_type && $alias->external_type !== \App\Models\Service::TYPE_COFFRAC ? $alias->external_type." | ".$alias->external_name : $alias->external_name)->values())'
                                        >Modifier</button>

                                        <button
                                            type="button"
                                            class="gc-btn-danger"
                                            data-modal-open="delete-service-modal"
                                            data-delete-url="{{ route('manager.services.destroy', $service->id) }}"
                                            data-service-name="{{ $service->name }}"
                                        >Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucune prestation.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t px-4 py-3" style="border-color:var(--gc-border);">
                {{ $services->links() }}
            </div>
        </section>
    </div>

    <div id="create-service-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Créer une prestation</h2>
            <form method="POST" action="{{ route('manager.services.store') }}" class="mt-4 space-y-4" data-validate-form>
                @csrf
                @include('manager.services.partials.form-fields', ['prefix' => 'create', 'types' => $types])
                <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="font-semibold" style="color:var(--gc-text);">Attribuer à des techniciens</p>
                            <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Optionnel: coche les techniciens qui réalisent cette nouvelle prestation.</p>
                        </div>
                        @if ($technicians->isNotEmpty())
                            <div class="flex shrink-0 items-center gap-3 text-sm">
                                <button type="button" class="gc-link" data-service-technicians-select="all">Tout cocher</button>
                                <button type="button" class="gc-link" data-service-technicians-select="none">Tout décocher</button>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 grid max-h-64 grid-cols-1 gap-2 overflow-y-auto pr-1 md:grid-cols-2">
                        @forelse ($technicians as $technician)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm transition hover:-translate-y-0.5 hover:shadow-sm" style="border-color:var(--gc-border);">
                                <input
                                    type="checkbox"
                                    name="technician_ids[]"
                                    value="{{ $technician->id }}"
                                    class="gc-checkbox"
                                    data-service-technician-checkbox
                                    @checked(in_array($technician->id, array_map('intval', old('technician_ids', [])), true))
                                />
                                <span class="min-w-0">
                                    <span class="block truncate font-medium" style="color:var(--gc-text);">{{ $technician->full_name_with_departments }}</span>
                                    <span class="block truncate text-xs" style="color:var(--gc-text-soft);">
                                        {{ $technician->assigned_department_codes ? 'Départements '.$technician->assigned_department_codes.' · ' : '' }}{{ $technician->email }}
                                    </span>
                                </span>
                            </label>
                        @empty
                            <p class="rounded-lg border p-3 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucun technicien actif disponible.</p>
                        @endforelse
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="gc-link" data-modal-close="create-service-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="edit-service-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Modifier une prestation</h2>
            <form id="edit-service-form" method="POST" action="#" class="mt-4 space-y-4" data-validate-form>
                @csrf
                @method('PUT')
                @include('manager.services.partials.form-fields', ['prefix' => 'edit', 'types' => $types])
                <div class="flex justify-end gap-2">
                    <button type="button" class="gc-link" data-modal-close="edit-service-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-service-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Supprimer la prestation</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">La prestation <span id="delete-service-name" class="font-medium" style="color:var(--gc-text);"></span> sera supprimée.</p>
            <form id="delete-service-form" method="POST" action="#" class="mt-4 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" class="gc-link" data-modal-close="delete-service-modal">Annuler</button>
                <button type="submit" class="gc-btn-danger">Supprimer</button>
            </form>
        </div>
    </div>

    <script>
        const openButtons = document.querySelectorAll('[data-modal-open]');
        const closeButtons = document.querySelectorAll('[data-modal-close]');
        const filtersForm = document.getElementById('service-filters-form');
        const serviceTechnicianCheckboxes = Array.from(document.querySelectorAll('[data-service-technician-checkbox]'));

        const openModal = (id) => {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('hidden');
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('hidden');
        };

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const modalId = button.dataset.modalOpen;

                if (modalId === 'edit-service-modal') {
                    const form = document.getElementById('edit-service-form');
                    form.action = `/manager/services/${button.dataset.serviceId}`;
                    document.getElementById('edit_type').value = button.dataset.serviceType || '';
                    document.getElementById('edit_name').value = button.dataset.serviceName || '';
                    document.getElementById('edit_average_duration_minutes').value = button.dataset.serviceDuration || '';
                    document.getElementById('edit_external_aliases').value = JSON.parse(button.dataset.serviceAliases || '[]').join('\n');
                    window.TechCalendarForms?.refresh(form);
                }

                if (modalId === 'create-service-modal') {
                    window.TechCalendarForms?.refresh(document.querySelector('#create-service-modal form'));
                }

                if (modalId === 'delete-service-modal') {
                    document.getElementById('delete-service-form').action = button.dataset.deleteUrl;
                    document.getElementById('delete-service-name').textContent = button.dataset.serviceName || '';
                }

                openModal(modalId);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => closeModal(button.dataset.modalClose));
        });

        document.querySelectorAll('[data-service-technicians-select]').forEach((button) => {
            button.addEventListener('click', () => {
                const checked = button.dataset.serviceTechniciansSelect === 'all';
                serviceTechnicianCheckboxes.forEach((checkbox) => {
                    checkbox.checked = checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                });

                window.TechCalendarForms?.refresh(document.querySelector('#create-service-modal form'));
            });
        });

        document.querySelectorAll('.gc-modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.classList.add('hidden');
            });
        });

        if (filtersForm) {
            const searchInput = document.getElementById('q');
            const typeInput = document.getElementById('type');

            if (typeInput) {
                typeInput.addEventListener('change', () => filtersForm.submit());
            }

            let debounceTimer;
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => filtersForm.submit(), 350);
                });
            }
        }
    </script>
</x-layouts.app>
