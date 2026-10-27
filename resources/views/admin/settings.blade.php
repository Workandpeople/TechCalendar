<x-layouts.app>
    @php
        $allSettings = $groups->flatten(1);
        $oldSettings = old('settings', []);
        $sourceLabels = ['bdd' => 'BDD active', 'env' => '.ENV actif', 'fallback' => 'Valeur par défaut'];
    @endphp
    <div class="settings-workspace" data-settings-workspace>
        <header class="settings-heading">
            <div>
                <p class="settings-eyebrow">Administration</p>
                <h1>Paramètres</h1>
                <p>Les valeurs BDD sont chiffrées et prioritaires sur la configuration du serveur.</p>
            </div>
            <a href="#connection-tests" class="gc-btn-secondary settings-small"><x-nav-icon name="check" /> Tests de connexion</a>
        </header>
        @if (session('status'))
            <div class="gc-alert" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="gc-alert settings-error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('admin.settings.update') }}" data-settings-form>
            @csrf
            @method('PUT')
            <div class="settings-toolbar">
                <div class="settings-filters">
                    <label class="settings-search">
                        <span class="sr-only">Rechercher une variable</span>
                        <x-nav-icon name="search" />
                        <input type="search" class="gc-input" placeholder="Rechercher un nom, une clé ou une variable .env…" data-settings-search autocomplete="off">
                    </label>
                    <label>
                        <span class="sr-only">Section</span>
                        <select class="gc-input" data-settings-group>
                            <option value="">Toutes les sections</option>
                            @foreach ($groups->keys() as $group)
                                <option value="{{ $group }}">{{ $group }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">Source ou état</span>
                        <select class="gc-input" data-settings-source>
                            <option value="">Toutes les variables ({{ $allSettings->count() }})</option>
                            <option value="bdd">BDD ({{ $allSettings->where('source', 'bdd')->count() }})</option>
                            <option value="env">.env / serveur ({{ $allSettings->where('source', 'env')->count() }})</option>
                            <option value="missing">Non renseignées ({{ $allSettings->where('is_missing', true)->count() }})</option>
                            <option value="fallback">Valeurs par défaut ({{ $allSettings->where('source', 'fallback')->where('is_missing', false)->count() }})</option>
                        </select>
                    </label>
                </div>
                <div class="settings-toolbar-footer">
                    <div><span data-settings-count aria-live="polite">{{ $allSettings->count() }} variables</span><span data-settings-dirty class="settings-dirty" hidden></span></div>
                    <div class="settings-toolbar-actions">
                        <button type="button" class="settings-reset-filters" data-settings-reset>Effacer les filtres</button>
                        <button type="submit" class="gc-btn-primary settings-small" aria-label="Enregistrer les paramètres">Enregistrer</button>
                    </div>
                </div>
            </div>
            <div class="settings-groups">
                @foreach ($groups as $group => $settings)
                    <section class="gc-card settings-group" data-setting-section data-group="{{ $group }}">
                        <h2><span>{{ $group }}</span><span class="settings-section-count" data-setting-section-count>{{ $settings->count() }}</span></h2>
                        @foreach ($settings as $setting)
                            @php
                                $id = 'setting_'.str_replace('.', '_', $setting['key']);
                                $inputValue = is_array($oldSettings) && array_key_exists($setting['key'], $oldSettings)
                                    && (is_scalar($oldSettings[$setting['key']]) || $oldSettings[$setting['key']] === null)
                                    ? $oldSettings[$setting['key']] : $setting['value'];
                            @endphp
                            <div class="settings-row" data-setting-row data-source="{{ $setting['source'] }}"
                                data-missing="{{ $setting['is_missing'] ? 'true' : 'false' }}"
                                data-search="{{ $setting['label'].' '.$setting['key'].' '.$setting['env_key'].' '.$setting['description'] }}">
                                <div class="settings-row-label">
                                    <label for="{{ $id }}">{{ $setting['label'] }}</label>
                                    <code title="{{ $setting['key'] }}">{{ $setting['env_key'] ?: $setting['key'] }}</code>
                                    @if ($setting['description'])
                                        <small>{{ $setting['description'] }}</small>
                                    @endif
                                </div>
                                <div class="settings-row-control">
                                    @if ($setting['type'] === 'select')
                                        <select id="{{ $id }}" name="settings[{{ $setting['key'] }}]" class="gc-input" data-setting-input data-saved-value="{{ $setting['value'] }}">
                                            <option value="">Utiliser .env / défaut</option>
                                            @foreach ($setting['options'] as $value => $label)
                                                <option value="{{ $value }}" @selected((string) $inputValue === (string) $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input id="{{ $id }}" name="settings[{{ $setting['key'] }}]" data-setting-input data-saved-value="{{ $setting['is_secret'] ? '' : $setting['value'] }}"
                                            type="{{ $setting['type'] === 'password' ? 'password' : ($setting['type'] === 'email' ? 'email' : 'text') }}"
                                            class="gc-input" value="{{ $setting['is_secret'] ? '' : $inputValue }}"
                                            @if ($setting['type'] === 'integer') inputmode="numeric" @endif
                                            placeholder="{{ $setting['is_secret'] && ! $setting['is_missing'] ? 'Secret enregistré · laisser vide pour conserver' : 'Utiliser .env / défaut' }}"
                                            autocomplete="off">
                                    @endif
                                </div>
                                <div class="settings-row-source">
                                    <span class="settings-source" data-source="{{ $setting['is_missing'] ? 'missing' : $setting['source'] }}"
                                        title=".env: {{ $setting['env_key'] ?: '—' }} · {{ $setting['has_env_value'] ? 'Valeur .env disponible' : 'Valeur .env absente' }}">
                                        {{ $setting['is_missing'] ? 'Non renseignée' : $sourceLabels[$setting['source']] }}
                                    </span>
                                    @if ($setting['has_database_value'])
                                        <button type="submit" form="reset-{{ $id }}" class="settings-revert" title="Supprimer la valeur BDD et utiliser la configuration du serveur">Revenir .env</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </div>
            <p class="settings-empty" data-settings-empty hidden>Aucune variable ne correspond à ces filtres.</p>
            <p class="settings-help">Un secret laissé vide est conservé. « Revenir .env » retire uniquement sa valeur BDD. Les filtres ne modifient pas les valeurs.</p>
        </form>
        <section id="connection-tests" class="gc-card settings-diagnostics" data-diagnostics data-url="{{ route('admin.settings.diagnostics') }}">
            <header>
                <div><p class="settings-eyebrow">Diagnostic à la demande</p><h2>Tests de connexion</h2></div>
                <button type="button" class="gc-btn-secondary settings-small" data-diagnostics-all hidden>Tout tester</button>
            </header>
            <p class="settings-help">Valeurs enregistrées uniquement : enregistrez vos changements avant de tester. Aucun email, SMS, dossier ou document n’est envoyé. Les tests ne modifient pas les modes simulation ni les activations.</p>
            <div class="settings-diagnostic-grid">
                @foreach ($diagnostics as $key => $diagnostic)
                    <article class="settings-diagnostic" data-diagnostic="{{ $key }}">
                        <div class="settings-diagnostic-title">
                            <h3>{{ $diagnostic['label'] }}</h3>
                            <button type="button" class="gc-btn-secondary settings-small" data-diagnostic-run hidden>Tester</button>
                        </div>
                        <p>{{ $diagnostic['description'] }}</p>
                        @if ($key === 'mail' && ! config('crm.v2_enabled'))
                            <label class="settings-mail-sender">
                                <span class="sr-only">Connexion mail à tester</span>
                                <select class="gc-input" data-diagnostic-mail-sender>
                                    <option value="">Transport par défaut</option>
                                    @foreach ($mailSenders as $sender)
                                        <option value="{{ $sender['id'] }}">{{ $sender['name'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <p class="settings-diagnostic-result" data-diagnostic-result data-state="idle" role="status" aria-live="polite">Non testé</p>
                    </article>
                @endforeach
            </div>
            <noscript><p class="settings-help">Activez JavaScript pour lancer les tests de connexion.</p></noscript>
        </section>
        @foreach ($allSettings as $setting)
            @if ($setting['has_database_value'])
                <form id="reset-setting_{{ str_replace('.', '_', $setting['key']) }}" method="POST" action="{{ route('admin.settings.destroy') }}" hidden>
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="key" value="{{ $setting['key'] }}">
                </form>
            @endif
        @endforeach
    </div>
</x-layouts.app>
