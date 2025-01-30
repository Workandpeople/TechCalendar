<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="{{ route('home.index') }}">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-laugh-wink"></i>
        </div>
        <div class="sidebar-brand-text mx-3">TechCalendar</div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard (visible uniquement pour l'admin) -->
    @if ($userRole === 'admin')
        <li class="nav-item {{ request()->routeIs('home.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('home.index') }}">
                <i class="fas fa-fw fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <!-- Divider -->
        <hr class="sidebar-divider">
    @endif

    <!-- Section Assistant (visible pour admin et assistante) -->
    @if (in_array($userRole, ['admin', 'assistante']))
        <div class="sidebar-heading mt-3">Admin</div>

        <li class="nav-item {{ request()->routeIs('manage-users.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('manage-users.index') }}">
                <i class="fas fa-fw fa-users"></i>
                <span>Gestion des utilisateurs</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('manage-providers.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('manage-providers.index') }}">
                <i class="fas fa-fw fa-briefcase"></i>
                <span>Gestion des services</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('manage-appointments.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('manage-appointments.index') }}">
                <i class="fas fa-fw fa-calendar-check"></i>
                <span>Gestion des RDV</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('stats.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('stats.index') }}">
                <i class="fas fa-fw fa-chart-line"></i>
                <span>Statistiques</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <div class="sidebar-heading">Assistant</div>

        <li class="nav-item {{ request()->is('search-appointments*') || request()->routeIs('appointment.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('appointment.index') }}">
                <i class="fas fa-fw fa-bookmark"></i>
                <span>Prise de RDV</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('calendar.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('calendar.index') }}">
                <i class="fas fa-fw fa-calendar-alt"></i>
                <span>Calendrier</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">
    @endif

    <!-- Section Technicien (visible uniquement pour admin et tech) -->
    @if (in_array($userRole, ['tech']))
        <div class="sidebar-heading">Technicien</div>

        <li class="nav-item {{ request()->routeIs('techDashboard.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tech-dashboard.index') }}">
                <i class="fas fa-fw fa-chart-bar"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('techCalendar.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tech-calendar.index') }}">
                <i class="fas fa-fw fa-calendar-day"></i>
                <span>Calendrier</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">
    @endif

    <!-- Sidebar Toggler -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>
<!-- End of Sidebar -->
