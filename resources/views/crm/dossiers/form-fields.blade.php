        @php $portalCreation = $portalCreation ?? false; @endphp
        <form method="POST" action="{{ $portalCreation ? route('client.dossiers.store') : ($dossier->exists ? route('v2.dossiers.update', $dossier) : route('v2.dossiers.store')) }}" class="space-y-6">
            @csrf
            @if ($portalCreation)<input type="hidden" name="submission_id" value="{{ is_string(old('submission_id')) ? old('submission_id') : $submissionId }}">@endif
            <input type="hidden" name="_form" value="information">
            @if ($dossier->exists)
                @method('PUT')
                <input type="hidden" name="version" value="{{ old('version', $dossier->version) }}">
            @endif
            @unless ($portalCreation)
            <div class="grid gap-6 xl:grid-cols-2">
                @include('crm.dossiers.contact-fields', ['role' => 'client'])
                @include('crm.dossiers.contact-fields', ['role' => 'delegataire'])
            </div>
            @endunless
            @php
                $groups = [
                    'Bénéficiaire' => [
                        'company_name' => ['Raison sociale', 190], 'last_name' => ['Nom', 120],
                        'first_name' => ['Prénom', 120], 'email' => ['Email', 190], 'phone' => ['Téléphone', 500],
                    ],
                    'Lieu d’inspection' => [
                        'site_name' => ['Nom du site / bâtiment', 190], 'address' => ['Adresse *', 500],
                        'postal_code' => ['Code postal', 10], 'city' => ['Ville *', 190],
                    ],
                    'Adresse administrative du bénéficiaire' => [
                        'beneficiary_address' => ['Adresse', 500], 'beneficiary_postal_code' => ['Code postal', 10], 'beneficiary_city' => ['Ville', 190],
                    ],
                    'Installateur' => [
                        'installer_name' => ['Raison sociale du professionnel', 190], 'installer_siren' => ['SIREN (9 chiffres)', 9],
                    ],
                ];
            @endphp
            <div class="grid gap-6 xl:grid-cols-2">
                @foreach ($groups as $label => $fields)
                    <section class="gc-card p-5">
                        <h2 class="mb-4 text-lg font-semibold">{{ $label }}</h2>
                        @if ($label === 'Bénéficiaire')<p class="mb-4 text-sm text-slate-500">Renseigne au minimum la raison sociale ou le nom.</p>@endif
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($fields as $field => [$fieldLabel, $maxLength])
                                <div class="{{ str_contains($field, 'address') || in_array($field, ['company_name', 'installer_name']) ? 'sm:col-span-2' : '' }}">
                                    <label for="{{ $field }}" class="gc-label">{{ $fieldLabel }}</label>
                                    @php($fieldValue = old($field, $dossier->$field ?: ($field === 'company_name' && blank($dossier->last_name) && blank($dossier->first_name) ? $dossier->legacy_label : null)))
                                    <input id="{{ $field }}" name="{{ $field }}" class="gc-input" value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}" maxlength="{{ $maxLength }}" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" @required(in_array($field, ['address', 'city'])) @if ($field === 'installer_siren') inputmode="numeric" pattern="[0-9]{9}" @endif>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
            @unless ($dossier->exists)
                <section class="gc-card p-4 sm:p-5" aria-labelledby="dossier-services-label">
                    <label id="dossier-services-label" for="dossier-service-ids" class="gc-label">Fiche technique *</label>
                    @php($selectedServices = is_array(old('service_ids')) ? old('service_ids') : [])
                    @php($selectedServiceId = is_scalar($selectedServices[0] ?? null) ? (string) $selectedServices[0] : '')
                    <select id="dossier-service-ids" name="service_ids[]" class="gc-input" required data-searchable
                        data-search-label="Rechercher par nom ou code…"
                        data-no-results-label="Aucune prestation ne correspond à la recherche."
                        aria-describedby="dossier-services-help" @disabled($services->isEmpty())>
                        <option value="">Choisir une fiche technique…</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected((string) $service->id === $selectedServiceId)>{{ $service->name }} · {{ $service->display_type }}</option>
                        @endforeach
                    </select>
                    <p id="dossier-services-help" class="mt-2 text-xs text-slate-500">Une fiche technique par dossier. Le rendez-vous sera organisé ensuite.</p>
                    @if ($services->isEmpty())
                        <p class="mt-2 text-sm text-rose-800">{{ $portalCreation ? 'Aucune prestation disponible. Contactez notre équipe avant de créer un dossier.' : 'Ajoute d’abord une prestation dans le référentiel existant.' }}</p>
                    @endif
                </section>
            @endunless
            <div class="gc-dialog-actions flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-5">
                <p class="text-sm text-slate-500">{{ $portalCreation ? '* Champs obligatoires. Aucun rendez-vous n’est fixé à cette étape.' : '* Champs obligatoires. Aucun appel aux CRM externes.' }}</p>
                <button class="gc-btn-primary" type="submit" @disabled(! $dossier->exists && $services->isEmpty())>{{ $portalCreation ? 'Transmettre le dossier' : ($dossier->exists ? 'Enregistrer les modifications' : 'Créer le dossier local') }}</button>
            </div>
        </form>
