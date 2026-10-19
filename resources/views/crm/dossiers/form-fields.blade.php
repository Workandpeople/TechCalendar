        <form method="POST" action="{{ $dossier->exists ? route('v2.dossiers.update', $dossier) : route('v2.dossiers.store') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="_form" value="information">
            @if ($dossier->exists)
                @method('PUT')
                <input type="hidden" name="version" value="{{ old('version', $dossier->version) }}">
            @endif
            <div class="grid gap-6 xl:grid-cols-2">
                @include('crm.dossiers.contact-fields', ['role' => 'client'])
                @include('crm.dossiers.contact-fields', ['role' => 'delegataire'])
            </div>
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
                                    <input id="{{ $field }}" name="{{ $field }}" class="gc-input" value="{{ old($field, $dossier->$field ?: ($field === 'company_name' && blank($dossier->last_name) && blank($dossier->first_name) ? $dossier->legacy_label : null)) }}" maxlength="{{ $maxLength }}" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" @required(in_array($field, ['address', 'city'])) @if ($field === 'installer_siren') inputmode="numeric" pattern="[0-9]{9}" @endif>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
            @unless ($dossier->exists)
                <fieldset class="gc-card p-5">
                    <legend class="px-2 text-lg font-semibold">Prestations *</legend>
                    <p class="mb-4 text-sm text-slate-500">Les prestations sélectionnées constituent les fiches du dossier. Elles ne créent pas encore de rendez-vous.</p>
                    <div class="grid max-h-72 gap-2 overflow-y-auto md:grid-cols-2">
                        @forelse ($services as $service)
                            <label class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm hover:bg-slate-50"><input type="checkbox" name="service_ids[]" value="{{ $service->id }}" @checked(in_array($service->id, old('service_ids', [])))><span>{{ $service->name }} <span class="text-xs text-slate-500">{{ $service->type }}</span></span></label>
                        @empty
                            <p class="text-sm text-rose-800">Ajoute d’abord une prestation dans le référentiel existant.</p>
                        @endforelse
                    </div>
                </fieldset>
            @endunless
            <div class="gc-dialog-actions flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-5">
                <p class="text-sm text-slate-500">* Champs obligatoires. Aucun appel aux CRM externes.</p>
                <button class="gc-btn-primary" type="submit" @disabled(! $dossier->exists && $services->isEmpty())>{{ $dossier->exists ? 'Enregistrer les modifications' : 'Créer le dossier local' }}</button>
            </div>
        </form>
