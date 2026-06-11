<!DOCTYPE html>
@php
    $reverbClientConfig = [
        'key' => config('broadcasting.connections.reverb.key'),
        'host' => config('broadcasting.connections.reverb.options.host'),
        'port' => config('broadcasting.connections.reverb.options.port'),
        'scheme' => config('broadcasting.connections.reverb.options.scheme'),
    ];
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name') }}</title>
        <script>
            window.TechCalendarReverbConfig = @json($reverbClientConfig);
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-screen overflow-hidden antialiased">
        @php
            /** @var \App\Models\User $user */
            $user = auth()->user();

            $sections = [];

            if ($user->admin) {
                $sections[] = [
                    'label' => 'Admin',
                    'items' => [
                        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'admin.users', 'label' => 'Gestion des users', 'icon' => 'users'],
                        ['route' => 'admin.settings', 'label' => 'Parametres', 'icon' => 'settings'],
                    ],
                ];

                $sections[] = [
                    'label' => 'Mon planning',
                    'items' => [
                        ['route' => 'tech.planning', 'label' => 'Mon planning', 'icon' => 'planning'],
                    ],
                ];

                $sections[] = [
                    'label' => 'Gerant',
                    'items' => [
                        ['route' => 'manager.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'manager.users', 'label' => 'Gestion des users', 'icon' => 'users'],
                        ['route' => 'manager.services', 'label' => 'Gestion des prestations', 'icon' => 'services'],
                        ['route' => 'manager.lots', 'label' => 'Gestion des lots', 'icon' => 'lots'],
                        ['route' => 'manager.appointments', 'label' => 'Gestion des rdv', 'icon' => 'appointments'],
                    ],
                ];

                $sections[] = [
                    'label' => 'Planning',
                    'items' => [
                        ['route' => 'planner.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'planner.book', 'label' => 'Prendre un rdv', 'icon' => 'book'],
                        ['route' => 'planner.tracking', 'label' => 'Suivi des rdv', 'icon' => 'tracking'],
                    ],
                ];
            } elseif ($user->role === 0) {
                $sections[] = [
                    'label' => 'Gerant',
                    'items' => [
                        ['route' => 'manager.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'manager.users', 'label' => 'Gestion des users', 'icon' => 'users'],
                        ['route' => 'manager.services', 'label' => 'Gestion des prestations', 'icon' => 'services'],
                        ['route' => 'manager.lots', 'label' => 'Gestion des lots', 'icon' => 'lots'],
                        ['route' => 'manager.appointments', 'label' => 'Gestion des rdv', 'icon' => 'appointments'],
                    ],
                ];
            } elseif ($user->role === 1) {
                $sections[] = [
                    'label' => 'Planning',
                    'items' => [
                        ['route' => 'planner.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'planner.book', 'label' => 'Prendre un rdv', 'icon' => 'book'],
                        ['route' => 'planner.tracking', 'label' => 'Suivi des rdv', 'icon' => 'tracking'],
                    ],
                ];
            }

            $showSidebar = $user->admin || in_array($user->role, [0, 1], true);
            $isTechPlanningPage = request()->routeIs('tech.planning');
        @endphp

        <div class="flex h-screen overflow-hidden">
            @if ($showSidebar)
                <aside id="app-sidebar" class="app-sidebar h-screen shrink-0 overflow-y-auto border-r border-[color:var(--gc-border)] bg-white">
                    <div class="sidebar-header flex h-16 items-center justify-between border-b border-[color:var(--gc-border)] px-4">
                        <img src="{{ asset('images/logo.png') }}" alt="Genius Controle" class="sidebar-logo h-10 w-auto" />
                        <button id="sidebar-toggle" type="button" class="sidebar-toggle rounded-lg p-2 text-[color:var(--gc-text)] hover:bg-[color:var(--gc-accent-soft)]" aria-label="Replier le menu" aria-expanded="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                                <path class="sidebar-chevron" d="m15 18-6-6 6-6" />
                            </svg>
                        </button>
                    </div>

                    <nav class="space-y-6 px-3 py-4">
                        @foreach ($sections as $section)
                            <section>
                                <h3 class="sidebar-section-title px-3 pb-2 text-xs font-semibold uppercase tracking-[0.1em] text-[color:var(--gc-text-soft)]">{{ $section['label'] }}</h3>
                                <ul class="space-y-1">
                                    @foreach ($section['items'] as $item)
                                        @php $isActive = request()->routeIs($item['route']); @endphp
                                        <li class="sidebar-item">
                                            <a href="{{ route($item['route']) }}" class="sidebar-link {{ $isActive ? 'sidebar-link-active' : '' }}">
                                                <span class="shrink-0">
                                                    <x-nav-icon :name="$item['icon']" />
                                                </span>
                                                <span class="sidebar-link-label">{{ $item['label'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </nav>
                </aside>
            @endif

            <div class="flex h-screen min-w-0 flex-1 flex-col overflow-hidden">
                <header class="h-16 shrink-0 border-b border-[color:var(--gc-border)] bg-white {{ $isTechPlanningPage ? 'px-3 sm:px-6' : 'px-6' }}">
                    <div class="flex h-full items-center justify-end">
                        <div id="header-user-menu" class="header-user-menu relative">
                            <button id="header-user-menu-trigger" type="button" class="flex items-center gap-3 rounded-xl border border-[color:var(--gc-border)] px-3 py-2" aria-haspopup="menu" aria-expanded="false">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-[color:var(--gc-primary)] text-sm font-semibold text-white">{{ $user->initials }}</span>
                                <span class="text-sm font-medium text-[color:var(--gc-text)] {{ $isTechPlanningPage ? 'hidden sm:inline' : '' }}">{{ $user->full_name }}</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-[color:var(--gc-text-soft)]">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>

                            <div id="header-user-menu-dropdown" role="menu" class="header-user-menu-dropdown absolute right-0 top-full z-20 mt-2 w-40 rounded-xl border border-[color:var(--gc-border)] bg-white p-1 shadow-md">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[color:var(--gc-text)] hover:bg-[color:var(--gc-accent-soft)]">Deconnexion</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="min-h-0 flex-1 overflow-y-auto {{ $isTechPlanningPage ? 'p-3 sm:p-6' : 'p-6' }}">
                    <div class="{{ $isTechPlanningPage ? 'p-0 sm:rounded-2xl sm:border sm:border-[color:var(--gc-border)] sm:bg-white sm:p-6 sm:shadow-sm' : 'gc-card p-6' }}">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @if ($user->must_change_password)
            <div class="gc-modal">
                <div class="gc-modal-panel max-w-md">
                    <h2 class="text-lg font-semibold">Changement de mot de passe requis</h2>
                    <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                        Pour votre premiere connexion, vous devez definir un nouveau mot de passe.
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
                        <button type="submit" class="gc-btn-primary w-full">Mettre a jour le mot de passe</button>
                    </form>
                </div>
            </div>
        @endif

        <script>
            const sidebar = document.getElementById('app-sidebar');
            const toggleButton = document.getElementById('sidebar-toggle');
            const sidebarStorageKey = 'techcalendar.sidebar.collapsed';

            const readStoredSidebarState = () => {
                try {
                    return localStorage.getItem(sidebarStorageKey) === '1';
                } catch (error) {
                    return false;
                }
            };

            const storeSidebarState = (isCollapsed) => {
                try {
                    localStorage.setItem(sidebarStorageKey, isCollapsed ? '1' : '0');
                } catch (error) {
                    // localStorage can be unavailable in some restricted browser contexts.
                }
            };

            const applySidebarState = (isCollapsed) => {
                sidebar.classList.toggle('sidebar-collapsed', isCollapsed);
                toggleButton.setAttribute('aria-expanded', String(!isCollapsed));
            };

            const dispatchSidebarResize = () => {
                [80, 220, 420].forEach((delay) => {
                    window.setTimeout(() => {
                        window.dispatchEvent(new CustomEvent('techcalendar:layout-resized'));
                    }, delay);
                });
            };

            if (sidebar && toggleButton) {
                applySidebarState(readStoredSidebarState());

                toggleButton.addEventListener('click', () => {
                    const isCollapsed = !sidebar.classList.contains('sidebar-collapsed');
                    applySidebarState(isCollapsed);
                    storeSidebarState(isCollapsed);
                    dispatchSidebarResize();
                });
            }

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
