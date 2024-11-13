<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="#">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-laugh-wink"></i>
        </div>
        <div class="sidebar-brand-text mx-3">TechCalendar</div>
    </a>    

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Admin Panel -->
    @if(Auth::user()->role->role === 'administrateur')
        <div class="sidebar-heading">
            Admin Panel
        </div>
        <li class="nav-item {{ Route::is('admin.manage_user') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.manage_user') }}">
                <i class="fas fa-fw fa-cog"></i>
                <span>Gestion des Utilisateurs</span>
            </a>
        </li>
        <li class="nav-item {{ Route::is('admin.manage_presta') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.manage_presta') }}">
                <i class="fas fa-fw fa-cog"></i>
                <span>Gestion des Préstations</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">
    @endif

    <!-- Assistante Panel -->
    @if(Auth::user()->role->role === 'administrateur' || Auth::user()->role->role === 'assistante')
        <div class="sidebar-heading">
            Assistante Panel
        </div>
        <li class="nav-item {{ Route::is('assistant.dashboard') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('assistant.dashboard') }}">
                <i class="fas fa-fw fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="nav-item {{ Route::is('assistant.prendre_rdv') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('assistant.prendre_rdv') }}">
                <i class="fas fa-fw fa-wrench"></i>
                <span>Prise de RDV</span>
            </a>
        </li>
        <li class="nav-item {{ Route::is('assistant.agenda_tech') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('assistant.agenda_tech') }}">
                <i class="fas fa-fw fa-table"></i>
                <span>Agenda des Tech</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">
    @endif

    <!-- Tech Panel -->
    @if(Auth::user()->role->role === 'administrateur' || Auth::user()->role->role === 'assistante' || Auth::user()->role->role === 'technicien')
        <div class="sidebar-heading">
            Tech Panel
        </div>
        <li class="nav-item {{ Route::is('tech.dashboard') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tech.dashboard') }}">
                <i class="fas fa-fw fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="nav-item {{ Route::is('tech.agenda') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('tech.agenda') }}">
                <i class="fas fa-fw fa-table"></i>
                <span>Agenda</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider d-none d-md-block">
    @endif

    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>
<!-- End of Sidebar -->