<!-- Topbar -->
<ul class="navbar-nav ml-auto">
    <!-- User Info -->
    <li class="nav-item dropdown no-arrow">
        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button">
            <span class="mr-2 text-gray-600 small">{{ Auth::user()->prenom }} {{ Auth::user()->nom }}</span>
        </a>
    </li>

    <!-- Logout -->
    <li class="nav-item">
        <a class="nav-link" href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" title="Se déconnecter">
            <i class="fas fa-sign-out-alt text-gray-600"></i>
        </a>
        <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
            @csrf
        </form>
    </li>
</ul>
<!-- End of Topbar -->