<style>
    /* Гарний шрифт, щоб букви не були розтягнуті */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');

    .navbar-static {
        font-family: 'Inter', sans-serif;
        background-color: #ffffff;
        border-bottom: 1px solid #e5e7eb; /* Тонка лінія знизу */
        height: 70px;
    }

    /* Стиль для аватарки (кружечок) */
    .user-avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #f8fafc;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        transition: transform 0.2s;
    }

    .nav-link:hover .user-avatar-circle {
        border-color: #6366f1; /* Синій обідок при наведенні */
        transform: scale(1.05);
    }

    /* Дропдаун (Випадаюче меню) */
    .dropdown-menu-clean {
        border: none;
        border-radius: 12px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        margin-top: 10px;
        padding: 8px;
    }

    .dropdown-item {
        border-radius: 8px;
        padding: 8px 16px;
        font-size: 0.9rem;
        font-weight: 500;
        color: #374151;
    }

    .dropdown-item:hover {
        background-color: #f3f4f6;
        color: #111827;
    }

    .dropdown-item.text-danger:hover {
        background-color: #fef2f2;
        color: #dc2626;
    }
</style>

<nav class="navbar navbar-expand-md navbar-light bg-white navbar-static">
    <div class="container-fluid px-4">
        
        <div class="d-flex align-items-center d-md-none w-100">
            <button class="navbar-toggler border-0 p-0 me-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
                <span class="navbar-toggler-icon"></span>
            </button>
            <span class="navbar-brand fw-bold text-dark">DomCRM</span>
            <button class="btn btn-sm btn-light border ms-auto" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
                <i class="bi bi-list fs-5"></i>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="navbarContent">
            
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <span class="nav-link text-muted fw-medium" style="font-size: 0.9rem;">
                        {{ now()->translatedFormat('d F Y') }}
                    </span>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        
                        <div class="d-none d-md-block text-end lh-1">
                            <div class="fw-bold text-dark" style="font-size: 0.9rem;">{{ Auth::user()->name }}</div>
                            <small class="text-muted" style="font-size: 0.75rem;">Адміністратор</small>
                        </div>

                        <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=6366f1&color=fff&bold=true" 
                             class="user-avatar-circle" 
                             alt="Avatar">
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-clean" aria-labelledby="userDropdown">
                        <li class="px-3 py-2 border-bottom mb-2 d-md-none">
                            <span class="fw-bold text-dark">{{ Auth::user()->name }}</span>
                        </li>

                        <li>
                            <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                <i class="bi bi-person me-2 text-secondary"></i> Мій профіль
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="#">
                                <i class="bi bi-gear me-2 text-secondary"></i> Налаштування
                            </a>
                        </li>
                        
                        <li><hr class="dropdown-divider my-2"></li>
                        
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger fw-bold">
                                    <i class="bi bi-box-arrow-right me-2"></i> Вийти
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>