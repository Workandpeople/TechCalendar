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

    <!-- Nav Item - Dashboard (visible à tous) -->
    <li class="nav-item {{ request()->routeIs('home.index') ? 'active' : '' }}">
        <a class="nav-link" href="{{ route('home.index') }}">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Section Admin -->
    @if ($userRole === 'admin')
        <div class="sidebar-heading">Admin</div>

        <li class="nav-item {{ request()->routeIs('manage-users.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('manage-users.index') }}">
            <i class="fas fa-fw fa-cog"></i>
            <span>Gestion des utilisateurs</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('manage-providers.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('manage-providers.index') }}">
            <i class="fas fa-fw fa-cog"></i>
            <span>Gestion des services</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('manage-appointments.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('manage-appointments.index') }}">
            <i class="fas fa-fw fa-cog"></i>
            <span>Gestion des RDV</span>
            </a>
        </li>

        <li class="nav-item {{ request()->routeIs('stats.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('stats.index') }}">
                <i class="fas fa-fw fa-chart-simple"></i>
            <span>Statistiques</span>
            </a>
        </li>
    @endif

    <!-- Section Assistant -->
    @if (in_array($userRole, ['admin', 'assistante']))
        <div class="sidebar-heading">Assistant</div>

        <li class="nav-item {{ request()->routeIs('appointment.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('appointment.index') }}">
                <i class="fas fa-fw fa-bookmark"></i>
                <span>Prise de RDV</span>
            </a>
        </li>

        <!-- Nav Item - Tables -->
        <li class="nav-item {{ request()->routeIs('calendar.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('calendar.index') }}">
                <i class="fas fa-fw fa-table"></i>
                <span>Calendrier</span>
            </a>
        </li>
    @endif

    <!-- Section Technicien -->
    @if (in_array($userRole, ['admin', 'tech']))
        <div class="sidebar-heading">Technicien</div>

        <li class="nav-item {{ request()->routeIs('tech-dashboard.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tech-dashboard.index') }}">
                <i class="fas fa-fw fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="nav-item {{ request()->routeIs('tech-calendar.index') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tech-calendar.index') }}">
                <i class="fas fa-fw fa-table"></i>
                <span>Calendrier</span>
            </a>
        </li>
    @endif

    <!-- Divider -->
    <hr class="sidebar-divider d-none d-md-block">

    <!-- Sidebar Toggler -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>
<!-- End of Sidebar -->
