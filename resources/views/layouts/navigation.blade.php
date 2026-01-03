<nav class="navbar navbar-expand-md navbar-light bg-white border-bottom sticky-top">
    <div class="container-fluid px-4">
        <!-- Mobile Toggler & Brand -->
        <div class="d-flex align-items-center d-md-none w-100">
            <button class="navbar-toggler border-0 p-0 me-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <span class="navbar-brand fw-bold text-dark">DomCRM</span>
        </div>

        <div class="collapse navbar-collapse" id="navbarContent">
            <!-- Left Side: Desktop Nav Links -->
            <ul class="navbar-nav me-auto mb-2 mb-md-0">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active fw-bold text-primary' : 'text-secondary' }}" href="{{ route('dashboard') }}">
                        Dashboard
                    </a>
                </li>
            </ul>

            <!-- Right Side: User Dropdown -->
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center text-secondary" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="d-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded border border-primary-subtle fw-bold me-2" style="width: 32px; height: 32px;">
                            {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                        </div>
                        <span class="d-none d-md-inline">{{ Auth::user()->name }}</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" aria-labelledby="userDropdown">
                        <li>
                            <h6 class="dropdown-header text-muted small">
                                Signed in as <br>
                                <strong class="text-dark">{{ Auth::user()->name }}</strong>
                            </h6>
                        </li>
                        <li><a class="dropdown-item" href="{{ route('profile.edit') }}">Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <a class="dropdown-item text-danger" href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();">
                                    Log Out
                                </a>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
