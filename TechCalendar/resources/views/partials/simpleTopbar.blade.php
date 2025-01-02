<!-- Topbar -->
<ul class="navbar-nav ml-auto">
    <li class="nav-item dropdown no-arrow">
        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button">
            <span class="mr-2 text-gray-600 small">{{ Auth::user()->prenom }} {{ Auth::user()->nom }}</span>
        </a>
    </li>
</ul>
<!-- End of Topbar -->