<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color:var(--gc-text-soft);">Admin</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Parametres</h1>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Overrides applicatifs stockes chiffres en BDD, fallback automatique vers le .env.</p>
        </div>

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#fecaca;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
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
                            <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                                <div class="mb-3 flex items-start justify-between gap-3">
                                    <div>
                                        <label class="gc-label mb-1" for="setting_{{ str_replace('.', '_', $setting['key']) }}">{{ $setting['label'] }}</label>
                                        <p class="text-xs" style="color:var(--gc-text-soft);">{{ $setting['key'] }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $setting['source'] === 'bdd' ? '#dcfce7' : '#fef3c7' }};color:{{ $setting['source'] === 'bdd' ? '#15803d' : '#b45309' }};">
                                        {{ strtoupper($setting['source']) }}
                                    </span>
                                </div>

                                @if ($setting['type'] === 'select')
                                    <select id="setting_{{ str_replace('.', '_', $setting['key']) }}" name="settings[{{ $setting['key'] }}]" class="gc-input">
                                        <option value="">Fallback .env</option>
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
                                        placeholder="{{ $setting['is_secret'] && $setting['has_database_value'] ? 'Valeur BDD definie - laisser vide pour conserver' : 'Fallback .env si vide' }}"
                                        autocomplete="off"
                                    />
                                @endif

                                @if ($setting['description'])
                                    <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $setting['description'] }}</p>
                                @endif

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <p class="text-xs" style="color:var(--gc-text-soft);">
                                        {{ $setting['has_database_value'] ? 'Override BDD actif' : 'Utilise le .env' }}
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
                <button type="submit" class="gc-btn-primary">Enregistrer les parametres</button>
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
