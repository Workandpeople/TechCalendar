<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Gerant</p>
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
                    <button type="button" class="gc-btn-primary" data-modal-open="create-service-modal">Creer une prestation</button>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('manager.services') }}" class="gc-link">Reset filtres</a>
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
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="gc-btn-soft"
                                            data-modal-open="edit-service-modal"
                                            data-service-id="{{ $service->id }}"
                                            data-service-type="{{ $service->type }}"
                                            data-service-name="{{ $service->name }}"
                                            data-service-duration="{{ $service->average_duration_minutes }}"
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
                                <td colspan="4" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucune prestation.</td>
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
            <h2 class="text-lg font-semibold">Creer une prestation</h2>
            <form method="POST" action="{{ route('manager.services.store') }}" class="mt-4 space-y-4">
                @csrf
                @include('manager.services.partials.form-fields', ['prefix' => 'create', 'types' => $types])
                <div class="flex justify-end gap-2">
                    <button type="button" class="gc-link" data-modal-close="create-service-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Creer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="edit-service-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Modifier une prestation</h2>
            <form id="edit-service-form" method="POST" action="#" class="mt-4 space-y-4">
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
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">La prestation <span id="delete-service-name" class="font-medium" style="color:var(--gc-text);"></span> sera supprimee.</p>
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
