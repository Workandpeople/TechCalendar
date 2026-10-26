@props(['crmNavigation' => false])
<!DOCTYPE html>
@php
    $workspace = \App\Support\WorkspaceUi::forRoute(request()->route()?->getName());
    $reverbClientConfig = [
        'key' => config('broadcasting.connections.reverb.key'),
        'host' => config('reverb.client.host') ?: config('broadcasting.connections.reverb.options.host'),
        'port' => config('reverb.client.port') ?: config('broadcasting.connections.reverb.options.port'),
        'scheme' => config('reverb.client.scheme') ?: config('broadcasting.connections.reverb.options.scheme'),
    ];
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-sidebar-collapse-storage-key="techcalendar.sidebar.collapsed">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $workspace['label'] }} · {{ config('app.name') }}</title>
        <x-favicon />
        <script>
            window.TechCalendarReverbConfig = @json($reverbClientConfig);
            try {
                document.documentElement.classList.toggle('sidebar-hidden', localStorage.getItem(document.documentElement.dataset.sidebarCollapseStorageKey) === '1');
            } catch {
                // Keep navigation visible when local storage is unavailable.
            }
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="gc-workspace h-screen overflow-hidden antialiased" data-workspace-tone="{{ $workspace['tone'] }}">
        <a class="gc-skip-link" href="#workspace-main">Aller au contenu</a>
        @php
            /** @var \App\Models\User $user */
            $user = auth()->user();
            $notifications = app(\App\Services\SidebarNotifications::class)->forUser($user);

            $sections = [];
            $dossierItems = $user->can('viewAny', \App\Models\Dossier::class)
                ? [['route' => 'v2.dossiers.index', 'label' => 'Les dossiers', 'icon' => 'dossiers', 'active' => 'v2.dossiers.*']]
                : [];

            if ($user->admin) {
                $sections[] = [
                    'id' => 'admin',
                    'label' => 'Admin',
                    'collapsible' => true,
                    'items' => [
                        ['route' => 'admin.dashboard', 'label' => 'Tableau de bord', 'icon' => 'dashboard'],
                        ['route' => 'admin.users', 'active' => 'admin.users*', 'label' => 'Utilisateurs internes', 'icon' => 'users'],
                        ['route' => 'admin.settings', 'active' => 'admin.settings*', 'label' => 'Paramètres', 'icon' => 'settings', 'badge' => $notifications['settings'], 'badge_label' => 'paramètre(s) non renseigné(s)'],
                    ],
                ];

                $sections[] = [
                    'id' => 'technician-planning',
                    'label' => 'Planning technicien',
                    'collapsible' => true,
                    'items' => [
                        ['route' => 'tech.planning', 'label' => 'Planning technicien', 'icon' => 'planning', 'badge' => $notifications['today'], 'badge_label' => 'RDV aujourd’hui'],
                    ],
                ];
            }

            if ($user->admin || $user->role === 0) {
                $sections[] = [
                    'id' => 'manager',
                    'label' => 'Gérant',
                    'collapsible' => true,
                    'items' => [
                        ['route' => 'manager.dashboard', 'label' => 'Tableau de bord', 'icon' => 'dashboard'],
                        [
                            'id' => 'manager-data',
                            'label' => 'Gestion des données',
                            'collapsible' => true,
                            'items' => [
                                ['route' => 'manager.users', 'active' => 'manager.users*', 'label' => 'Utilisateurs internes', 'icon' => 'users'],
                                ['route' => 'manager.delegataires', 'active' => ['manager.delegataires*', 'manager.clients.*'], 'label' => config('crm.v2_enabled') ? 'Clients et délégataires' : 'Délégataires', 'icon' => 'delegataires'],
                                ['route' => 'manager.mail-templates', 'active' => ['manager.mail-templates*', 'manager.mail-senders*'], 'label' => 'Mails', 'icon' => 'mail'],
                                ['route' => 'manager.services', 'active' => 'manager.services*', 'label' => 'Prestations', 'icon' => 'services'],
                            ],
                        ],
                        ...($user->admin ? [] : $dossierItems),
                        ['route' => 'manager.lots', 'active' => 'manager.lots*', 'label' => 'Les lots', 'icon' => 'lots'],
                    ],
                ];
            }

            if ($user->admin || in_array($user->role, [0, 1], true)) {
                $sections[] = [
                    'id' => 'planner',
                    'label' => 'Planning',
                    'collapsible' => true,
                    'items' => [
                        ['route' => 'planner.dashboard', 'label' => $user->admin || $user->role === 0 ? 'Vue planning' : 'Tableau de bord', 'icon' => 'dashboard'],
                        ...($user->role === 0 && ! $user->admin ? [] : $dossierItems),
                        ['route' => 'planner.book', 'active' => 'planner.book*', 'label' => 'Prendre un RDV', 'icon' => 'book', 'badge' => $notifications['pending'], 'badge_label' => 'RDV à placer'],
                        ['route' => 'planner.tracking', 'active' => ['planner.tracking*', 'planner.appointments.modify'], 'label' => 'Les RDV', 'icon' => 'tracking'],
                    ],
                ];
            }

            if ($user->isExternal()) {
                $sections = [['id' => 'client', 'label' => 'Espace client', 'collapsible' => false, 'items' => [
                    ['route' => 'client.dossiers', 'active' => 'client.dossiers*', 'label' => 'Mes dossiers', 'icon' => 'dossiers'],
                    ...(app(\App\Services\Crm\ClientAccess::class)->allows($user, 'lots')
                        ? [['route' => 'client.lots', 'active' => 'client.lots*', 'label' => 'Mes lots', 'icon' => 'lots']] : []),
                ]]];
            }
            $showSidebar = $user->isExternal() || $user->admin || in_array($user->role, [0, 1], true);
            $isTechPlanningPage = request()->routeIs('tech.planning');
        @endphp

        <div class="flex h-screen overflow-hidden" data-sidebar-storage-key="techcalendar.sidebar.sections.v2.{{ $user->id }}">
            @if ($showSidebar)
                <aside id="app-sidebar" class="app-sidebar hidden h-screen shrink-0 overflow-y-auto border-r border-[color:var(--gc-border)] bg-white lg:block">
                    <div class="sidebar-header flex h-16 items-center border-b border-[color:var(--gc-border)] px-4">
                        <img src="{{ asset('images/logo.png') }}" alt="Genius Contrôle" class="sidebar-logo h-10 w-auto" />
                        <div class="gc-workspace-brand"><strong>TechCalendar</strong><span>Espace de travail</span></div>
                    </div>

                    <nav aria-label="Navigation principale" class="space-y-5 px-3 py-4">
                        @foreach ($sections as $section)
                            <x-sidebar-section :section="$section" scope="desktop" />
                        @endforeach
                    </nav>
                </aside>
            @endif

            <div class="flex h-screen min-w-0 flex-1 flex-col overflow-hidden">
                <header class="gc-workspace-topbar relative h-16 shrink-0 border-b border-[color:var(--gc-border)] bg-white {{ $isTechPlanningPage || $crmNavigation ? 'px-3 sm:px-6' : 'px-6' }}">
                    <div class="flex h-full items-center gap-3 {{ $showSidebar ? 'justify-between' : 'justify-end' }}">
                        @if ($showSidebar)
                            <button id="sidebar-toggle" type="button" class="sidebar-toggle hidden items-center gap-2 rounded-lg border border-[color:var(--gc-border)] px-3 py-2 text-sm font-semibold text-[color:var(--gc-text)] hover:bg-[color:var(--gc-accent-soft)] lg:inline-flex"
                                aria-label="Replier le menu" title="Replier le menu" aria-expanded="true" aria-controls="app-sidebar">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                                    <rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16" />
                                    <path class="sidebar-toggle-collapse" d="m15 9-3 3 3 3" />
                                    <path class="sidebar-toggle-expand" d="m13 9 3 3-3 3" />
                                </svg>
                                <span>Menu</span>
                            </button>
                            <details class="lg:hidden" id="crm-mobile-navigation">
                                <summary class="cursor-pointer rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold">Menu</summary>
                                <nav aria-label="Navigation mobile" class="absolute left-3 right-3 top-14 z-50 max-h-[75vh] space-y-4 overflow-y-auto rounded-xl border border-slate-200 bg-white p-4 shadow-xl">
                                    @foreach ($sections as $section)
                                        <x-sidebar-section :section="$section" scope="mobile" />
                                    @endforeach
                                </nav>
                            </details>
                        @endif
                        <div class="gc-workspace-location hidden min-w-0 flex-1 md:flex" aria-label="Rubrique actuelle"><x-nav-icon :name="$workspace['icon']" /><span>{{ $workspace['label'] }}</span></div>
                        <x-workspace-help :context="$workspace" />
                        <div id="header-user-menu" class="header-user-menu relative">
                            <button id="header-user-menu-trigger" type="button" class="flex items-center gap-3 rounded-xl border border-[color:var(--gc-border)] px-3 py-2" aria-haspopup="menu" aria-expanded="false">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-[color:var(--gc-primary)] text-sm font-semibold text-white">{{ $user->initials }}</span>
                                <span class="hidden max-w-48 truncate text-sm font-medium text-[color:var(--gc-text)] sm:inline">{{ $user->full_name_with_departments }}</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-[color:var(--gc-text-soft)]">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>

                            <div id="header-user-menu-dropdown" role="menu" class="header-user-menu-dropdown absolute right-0 top-full z-20 mt-2 w-40 rounded-xl border border-[color:var(--gc-border)] bg-white p-1 shadow-md">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[color:var(--gc-text)] hover:bg-[color:var(--gc-accent-soft)]">Déconnexion</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main id="workspace-main" tabindex="-1" class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-6">
                    @if (config('simulation.enabled'))
                        <div role="status" class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                            <strong>{{ app()->environment('demo') ? 'Démonstration V2.' : 'Simulation locale.' }}</strong> Copie des données réelles. Emails désactivés.
                            @if (app()->environment('demo') && config('simulation.allow_external_apis'))
                                Mapbox et OpenAI autorisés. Global+ en test uniquement. Notifications mobiles et CAPCEE désactivés.
                            @else
                                {{ config('simulation.allow_external_apis') ? 'API externes autorisées : vérifier les environnements ciblés.' : 'Appels API sortants bloqués.' }}
                            @endif
                        </div>
                    @endif
                    <div class="gc-workspace-content">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @if ($user->must_change_password)
            <div class="gc-modal" data-modal-static="true">
                <div class="gc-modal-panel max-w-md">
                    <h2 class="text-lg font-semibold">Changement de mot de passe requis</h2>
                    <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                        Pour votre première connexion, vous devez définir un nouveau mot de passe.
                    </p>
                    <form method="POST" action="{{ route('account.first-password.update') }}" class="mt-4 space-y-4" data-validate-form>
                        @csrf
                        <div>
                            <label class="gc-label" for="first_login_password">Nouveau mot de passe</label>
                            <input id="first_login_password" name="password" type="password" class="gc-input" required autocomplete="new-password" data-password-rules />
                        </div>
                        <div>
                            <label class="gc-label" for="first_login_password_confirmation">Confirmation</label>
                            <input id="first_login_password_confirmation" name="password_confirmation" type="password" class="gc-input" required autocomplete="new-password" />
                        </div>
                        <button type="submit" class="gc-btn-primary w-full">Mettre à jour le mot de passe</button>
                    </form>
                </div>
            </div>
        @endif

        <script>
            const workspaceHelp = document.querySelector('[data-workspace-help]');
            document.addEventListener('click', (event) => {
                if (workspaceHelp && !workspaceHelp.contains(event.target)) workspaceHelp.open = false;
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && workspaceHelp?.open) {
                    workspaceHelp.open = false;
                    workspaceHelp.querySelector('summary').focus();
                }
            });
            const crmMobileNavigation = document.getElementById('crm-mobile-navigation');
            if (crmMobileNavigation) {
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && crmMobileNavigation.open) {
                        crmMobileNavigation.open = false;
                        crmMobileNavigation.querySelector('summary').focus();
                    }
                });
                document.addEventListener('click', (event) => {
                    if (!crmMobileNavigation.contains(event.target)) crmMobileNavigation.open = false;
                });
            }

            document.querySelectorAll('.gc-modal').forEach((modal) => {
                modal.setAttribute('role', modal.getAttribute('role') || 'dialog');
                modal.setAttribute('aria-modal', modal.getAttribute('aria-modal') || 'true');
            });

            const headerUserMenu = document.getElementById('header-user-menu');
            const headerUserMenuTrigger = document.getElementById('header-user-menu-trigger');
            let headerUserMenuCloseTimer = null;

            const openHeaderUserMenu = () => {
                window.clearTimeout(headerUserMenuCloseTimer);
                headerUserMenu?.classList.add('is-open');
                headerUserMenuTrigger?.setAttribute('aria-expanded', 'true');
            };

            const closeHeaderUserMenu = () => {
                window.clearTimeout(headerUserMenuCloseTimer);
                headerUserMenu?.classList.remove('is-open');
                headerUserMenuTrigger?.setAttribute('aria-expanded', 'false');
            };

            const scheduleHeaderUserMenuClose = () => {
                window.clearTimeout(headerUserMenuCloseTimer);
                headerUserMenuCloseTimer = window.setTimeout(closeHeaderUserMenu, 450);
            };

            if (headerUserMenu && headerUserMenuTrigger) {
                headerUserMenu.addEventListener('mouseenter', openHeaderUserMenu);
                headerUserMenu.addEventListener('mouseleave', scheduleHeaderUserMenuClose);
                headerUserMenu.addEventListener('focusin', openHeaderUserMenu);
                headerUserMenu.addEventListener('focusout', (event) => {
                    if (!headerUserMenu.contains(event.relatedTarget)) {
                        scheduleHeaderUserMenuClose();
                    }
                });

                headerUserMenuTrigger.addEventListener('click', (event) => {
                    event.stopPropagation();
                    headerUserMenu.classList.contains('is-open') ? closeHeaderUserMenu() : openHeaderUserMenu();
                });

                document.addEventListener('click', (event) => {
                    if (!headerUserMenu.contains(event.target)) {
                        closeHeaderUserMenu();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    const menuIsActive = headerUserMenu.classList.contains('is-open') || headerUserMenu.contains(document.activeElement);

                    if (event.key === 'Escape' && menuIsActive) {
                        closeHeaderUserMenu();
                        headerUserMenuTrigger.focus();
                    }
                });
            }
        </script>
    </body>
</html>
