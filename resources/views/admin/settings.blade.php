<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color:var(--gc-text-soft);">Admin</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Paramètres</h1>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Overrides applicatifs stockes de facon chiffree en BDD. Priorite: BDD si renseignée, sinon .env/config, sinon fallback applicatif.</p>
        </div>

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#fecaca;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="gc-card p-5">
            <div class="mb-5 flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Maintenance API</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Réinitialisation des RDV externes</h2>
                    <p class="mt-2 max-w-3xl text-sm" style="color:var(--gc-text-soft);">
                        Vide uniquement le cache local des demandes récupérées depuis les APIs externes. Les rendez-vous déjà créés dans TechCalendar sont conservés.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                @foreach ($externalAppointmentSources ?? [] as $source)
                    <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold" style="color:var(--gc-text);">{{ $source['label'] }}</h3>
                                <p class="mt-1 text-xs leading-5" style="color:var(--gc-text-soft);">{{ $source['description'] }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold" style="background:#f8fafc;color:var(--gc-text);border:1px solid var(--gc-border);">
                                {{ $source['appointments_count'] }} RDV
                            </span>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('admin.settings.external-appointments.reset') }}"
                            class="mt-4"
                            onsubmit="return confirm('Réinitialiser le cache local {{ addslashes($source['label']) }} ? Les RDV déjà créés dans TechCalendar seront conservés.');"
                        >
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="source" value="{{ $source['key'] }}">
                            <button type="submit" class="gc-btn-danger w-full justify-center">
                                {{ $source['reset_label'] }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        </section>

        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6" data-validate-form>
            @csrf
            @method('PUT')

            @foreach ($groups as $group => $settings)
                <section class="gc-card p-5">
                    <div class="mb-5 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">Configuration</p>
                            <h2 class="text-lg font-semibold" style="color:var(--gc-text);">{{ $group }}</h2>
                        </div>
                        <span class="rounded-full px-3 py-1 text-sm" style="background:var(--gc-accent-soft);color:var(--gc-text);">{{ $settings->count() }} cle(s)</span>
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        @foreach ($settings as $setting)
                            @php
                                $sourceMeta = match ($setting['source']) {
                                    'bdd' => [
                                        'label' => 'BDD active',
                                        'background' => '#dcfce7',
                                        'color' => '#15803d',
                                    ],
                                    'env' => [
                                        'label' => '.ENV actif',
                                        'background' => '#e0f2fe',
                                        'color' => '#1d4ed8',
                                    ],
                                    default => [
                                        'label' => 'Fallback',
                                        'background' => '#fef3c7',
                                        'color' => '#b45309',
                                    ],
                                };
                            @endphp
                            <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                                <div class="mb-3 flex items-start justify-between gap-3">
                                    <div>
                                        <label class="gc-label mb-1" for="setting_{{ str_replace('.', '_', $setting['key']) }}">{{ $setting['label'] }}</label>
                                        <p class="text-xs" style="color:var(--gc-text-soft);">{{ $setting['key'] }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $sourceMeta['background'] }};color:{{ $sourceMeta['color'] }};">
                                        {{ $sourceMeta['label'] }}
                                    </span>
                                </div>

                                <div class="mb-3 flex flex-wrap items-center gap-2">
                                    @if ($setting['env_key'])
                                        <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#f8fafc;color:var(--gc-text);border:1px solid var(--gc-border);">
                                            .env: {{ $setting['env_key'] }}
                                        </span>
                                    @endif
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $setting['has_env_value'] ? '#ecfdf5' : '#fff7ed' }};color:{{ $setting['has_env_value'] ? '#047857' : '#c2410c' }};">
                                        {{ $setting['has_env_value'] ? 'Valeur .env disponible' : 'Valeur .env absente' }}
                                    </span>
                                </div>

                                @if ($setting['type'] === 'select')
                                    <select id="setting_{{ str_replace('.', '_', $setting['key']) }}" name="settings[{{ $setting['key'] }}]" class="gc-input">
                                        <option value="">Utiliser .env / fallback</option>
                                        @foreach ($setting['options'] as $value => $label)
                                            <option value="{{ $value }}" @selected((string) old("settings.{$setting['key']}", $setting['value']) === (string) $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input
                                        id="setting_{{ str_replace('.', '_', $setting['key']) }}"
                                        name="settings[{{ $setting['key'] }}]"
                                        type="{{ $setting['type'] === 'password' ? 'password' : ($setting['type'] === 'email' ? 'email' : 'text') }}"
                                        class="gc-input"
                                        value="{{ $setting['is_secret'] ? '' : old("settings.{$setting['key']}", $setting['value']) }}"
                                        placeholder="{{ $setting['is_secret'] && $setting['has_database_value'] ? 'Valeur BDD définie - laisser vide pour conserver' : 'Laisser vide pour utiliser .env / fallback' }}"
                                        autocomplete="off"
                                    />
                                @endif

                                @if ($setting['description'])
                                    <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $setting['description'] }}</p>
                                @endif

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <p class="text-xs" style="color:var(--gc-text-soft);">
                                        @if ($setting['has_database_value'])
                                            Override BDD prioritaire.
                                        @elseif ($setting['has_env_value'])
                                            Utilise actuellement la valeur .env/config.
                                        @else
                                            Aucune valeur .env: utilise le fallback applicatif.
                                        @endif
                                    </p>
                                    @if ($setting['has_database_value'])
                                        <button
                                            type="submit"
                                            form="reset-setting-{{ str_replace('.', '_', $setting['key']) }}"
                                            class="gc-btn-danger"
                                        >
                                            Revenir .env
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="flex justify-end">
                <button type="submit" class="gc-btn-primary">Enregistrer les paramètres</button>
            </div>
        </form>

        @foreach ($groups as $settings)
            @foreach ($settings as $setting)
                @if ($setting['has_database_value'])
                    <form id="reset-setting-{{ str_replace('.', '_', $setting['key']) }}" method="POST" action="{{ route('admin.settings.destroy') }}" class="hidden">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="key" value="{{ $setting['key'] }}">
                    </form>
                @endif
            @endforeach
        @endforeach
    </div>
</x-layouts.app>
