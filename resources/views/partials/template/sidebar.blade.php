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

        <!-- Manage Links -->
        <li class="nav-item {{ request()->is('manage-*') ? 'active' : '' }}">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseManage"
               aria-expanded="true" aria-controls="collapseManage">
                <i class="fas fa-fw fa-cog"></i>
                <span>Manage</span>
            </a>
            <div id="collapseManage" class="collapse {{ request()->is('manage-*') ? 'show' : '' }}" aria-labelledby="headingManage" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item {{ request()->routeIs('manage-users.index') ? 'active' : '' }}" href="{{ route('manage-users.index') }}">User Manage</a>
                    <a class="collapse-item {{ request()->routeIs('manage-providers.index') ? 'active' : '' }}" href="{{ route('manage-providers.index') }}">Provider Manage</a>
                    <a class="collapse-item {{ request()->routeIs('manage-appointments.index') ? 'active' : '' }}" href="{{ route('manage-appointments.index') }}">Appointment Manage</a>
                </div>
            </div>
        </li>

        <!-- Nav Item - Utilities Collapse Menu -->
        <li class="nav-item {{ request()->is('stats*') ? 'active' : '' }}">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities"
                aria-expanded="true" aria-controls="collapseUtilities">
                <i class="fas fa-fw fa-chart-simple"></i>
                <span>Statistiques</span>
            </a>
            <div id="collapseUtilities" class="collapse {{ request()->is('stats*') ? 'show' : '' }}" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item {{ request()->routeIs('stats.index') ? 'active' : '' }}" href="{{ route('stats.index') }}">Statistiques de Kilométrage</a>
                    <a class="collapse-item" href="#">Stats 2</a>
                    <a class="collapse-item" href="#">Stats 3</a>
                    <a class="collapse-item" href="#">Stats 4</a>
                </div>
            </div>
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
                <i class="fas fa-fw fa-bookmark"></i>
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
