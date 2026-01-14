<style>
    /* 1. ШРИФТИ ТА БАЗА */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

    .navbar-static {
        font-family: 'Inter', sans-serif;
        background-color: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        height: 70px;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.03);
        position: relative;
        z-index: 1020; 
    }

    /* 2. КНОПКА ОЧИЩЕННЯ КЕШУ */
    .clear-cache-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 8px 20px;
        border-radius: 99px;
        border: 1px solid rgba(99, 102, 241, 0.2);
        background-color: rgba(99, 102, 241, 0.08);
        color: #4f46e5;
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        white-space: nowrap;
        min-width: 170px; /* Фіксуємо ширину */
    }

    .clear-cache-btn:hover {
        background-color: #6366f1;
        color: #ffffff;
        border-color: #6366f1;
        box-shadow: 0 8px 15px rgba(99, 102, 241, 0.25);
        transform: translateY(-2px);
    }

    .clear-cache-btn:active {
        transform: translateY(0) scale(0.97);
    }

    .icon-broom {
        font-size: 1.1em;
        transition: transform 0.4s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
    }

    .clear-cache-btn:hover .icon-broom {
        transform: rotate(-20deg) scale(1.1);
    }

    .clear-cache-btn.loading {
        background-color: #e0e7ff;
        color: #4338ca;
        pointer-events: none;
        opacity: 0.9;
        transform: none !important; 
        box-shadow: none !important;
    }

    .clear-cache-btn.loading .icon-broom {
        animation: sweep-spin 1s linear infinite;
        transform-origin: center; 
    }

    @keyframes sweep-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* 3. АВАТАРКА ТА ДРОПДАУН */
    .user-avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #f8fafc;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        transition: transform 0.2s, border-color 0.2s;
    }

    .nav-link:hover .user-avatar-circle {
        border-color: #6366f1;
        transform: scale(1.05);
    }
    
    .nav-link.show .user-avatar-circle {
        transform: scale(1); 
        border-color: #6366f1;
    }

    .dropdown-toggle::after {
        display: none !important;
    }

    .nav-link:focus, .btn:focus {
        box-shadow: none !important;
    }

    .dropdown-menu-clean {
        border: none;
        border-radius: 16px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        margin-top: 15px;
        padding: 8px;
        min-width: 220px;
        background-color: #ffffff; 
    }

    .dropdown-item {
        border-radius: 10px;
        padding: 10px 16px;
        font-size: 0.9rem;
        font-weight: 500;
        color: #4b5563;
        transition: background-color 0.2s;
        margin-bottom: 2px;
    }

    .dropdown-item:hover {
        background-color: #f3f4f6;
        color: #111827;
    }

    .dropdown-item i {
        font-size: 1.1em;
        vertical-align: -2px;
        opacity: 0.7;
    }
    
    .dropdown-item.text-danger:hover {
        background-color: #fef2f2;
        color: #dc2626;
    }
    
    .dropdown-divider {
        border-top-color: #f3f4f6;
        margin: 8px 0;
    }
</style>

<nav class="navbar navbar-expand-md navbar-light navbar-static">
    <div class="container-fluid px-4">
        
        <!-- ЛІВА ЧАСТИНА -->
        <div class="d-flex align-items-center me-auto">
            <button class="btn btn-light border-0 me-3 d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
                <i class="bi bi-list fs-4"></i>
            </button>

            <span class="navbar-brand fw-bold text-dark d-md-none me-3">DomCRM</span>

            <div class="d-none d-md-block">
                <span class="nav-link text-muted fw-medium d-flex align-items-center gap-2" style="font-size: 0.9rem;">
                    <i class="bi bi-calendar4-week"></i>
                    {{ now()->translatedFormat('d F Y') }}
                </span>
            </div>
        </div>

        <!-- ПРАВА ЧАСТИНА -->
        <ul class="navbar-nav flex-row align-items-center gap-3">
            
            <!-- Кнопка очистки кешу -->
            <li class="nav-item d-none d-md-block">
                <button id="clearCacheBtn" class="clear-cache-btn">
                    <span class="icon-broom">🧹</span> 
                    <span class="btn-text">Очистити кеш</span>
                </button>
            </li>

            <!-- Дропдаун користувача (СХОВАНО НА МОБІЛЬНОМУ: d-none d-md-block) -->
            <li class="nav-item dropdown d-none d-md-block">
                <a class="nav-link dropdown-toggle d-flex align-items-center gap-3 p-0" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    
                    <div class="d-none d-md-block text-end lh-1">
                        <div class="fw-bold text-dark" style="font-size: 0.9rem;">{{ Auth::user()->name }}</div>
                        <small class="text-muted" style="font-size: 0.75rem; letter-spacing: 0.02em;">Адміністратор</small>
                    </div>

                    <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=6366f1&color=fff&bold=true&size=128" 
                         class="user-avatar-circle" 
                         alt="Avatar">
                </a>

                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-clean animate__animated animate__fadeIn" aria-labelledby="userDropdown">
                    <li>
                        <a class="dropdown-item" href="{{ route('profile.edit') }}">
                            <i class="bi bi-person me-2"></i> Мій профіль
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#">
                            <i class="bi bi-gear me-2"></i> Налаштування
                        </a>
                    </li>
                    
                    <li><hr class="dropdown-divider"></li>
                    
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
</nav>

<script>
    (function () {
        const clearCacheBtn = document.getElementById('clearCacheBtn');
        const btnText = clearCacheBtn ? clearCacheBtn.querySelector('.btn-text') : null;
        
        if (!clearCacheBtn) return;

        clearCacheBtn.addEventListener('click', function () {
            clearCacheBtn.classList.add('loading');

            fetch('{{ url('/clear-everything') }}')
                .then(function (res) { return res.text(); })
                .then(function () {
                    setTimeout(() => {
                        console.log('Кеш очищено');
                        window.location.reload();
                    }, 500); 
                })
                .catch(function(err) {
                    console.error('Помилка очищення:', err);
                    clearCacheBtn.classList.remove('loading');
                });
        });
    })();
</script>