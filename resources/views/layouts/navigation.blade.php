<style>
    /* 1. ШРИФТИ ТА БАЗА */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

    .navbar-static {
        font-family: 'Inter', sans-serif;
        background-color: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        height: 70px;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.03); /* Дуже легка тінь для об'єму */
    }

    /* 2. КНОПКА ОЧИЩЕННЯ КЕШУ (MODERN STYLE) */
    .clear-cache-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 20px;
        border-radius: 99px; /* Pill shape */
        border: 1px solid rgba(99, 102, 241, 0.2); /* Легка рамка */
        background-color: rgba(99, 102, 241, 0.08); /* Світлий індиго фон */
        
        color: #4f46e5; /* Індиго текст */
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
        
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    /* Ховер ефект */
    .clear-cache-btn:hover {
        background-color: #6366f1;
        color: #ffffff;
        border-color: #6366f1;
        box-shadow: 0 8px 15px rgba(99, 102, 241, 0.25);
        transform: translateY(-2px);
    }

    /* Активний стан (клік) */
    .clear-cache-btn:active {
        transform: translateY(0) scale(0.97);
    }

    /* Анімація іконки віника */
    .icon-broom {
        font-size: 1.1em;
        transition: transform 0.4s ease;
        display: inline-block;
    }

    /* При наведенні віник нахиляється */
    .clear-cache-btn:hover .icon-broom {
        transform: rotate(-20deg) scale(1.1);
    }

    /* Клас для анімації завантаження (додається через JS) */
    .clear-cache-btn.loading {
        background-color: #e0e7ff;
        color: #4338ca;
        pointer-events: none; /* Блокуємо повторні кліки */
        opacity: 0.8;
    }

    .clear-cache-btn.loading .icon-broom {
        animation: sweep-spin 1s linear infinite;
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

    .dropdown-menu-clean {
        border: none;
        border-radius: 16px; /* Більш заокруглені кути */
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        margin-top: 15px;
        padding: 8px;
        min-width: 220px;
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
    
    /* Розділювач */
    .dropdown-divider {
        border-top-color: #f3f4f6;
        margin: 8px 0;
    }
</style>

<nav class="navbar navbar-expand-md navbar-light navbar-static">
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
                    <span class="nav-link text-muted fw-medium d-flex align-items-center gap-2" style="font-size: 0.9rem;">
                        <i class="bi bi-calendar4-week"></i>
                        {{ now()->translatedFormat('d F Y') }}
                    </span>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center gap-3">
                
                <li class="nav-item">
                    <button id="clearCacheBtn" class="clear-cache-btn">
                        <span class="icon-broom">🧹</span> 
                        <span class="btn-text">Очистити кеш</span>
                    </button>
                </li>

                <li class="nav-item dropdown">
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
                        <li class="px-3 py-2 border-bottom mb-2 d-md-none bg-light rounded-top">
                            <span class="fw-bold text-dark">{{ Auth::user()->name }}</span>
                        </li>

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
    </div>
</nav>

<script>
    (function () {
        const clearCacheBtn = document.getElementById('clearCacheBtn');
        const btnText = clearCacheBtn ? clearCacheBtn.querySelector('.btn-text') : null;
        
        if (!clearCacheBtn) return;

        clearCacheBtn.addEventListener('click', function () {
            // 1. Включаємо анімацію
            clearCacheBtn.classList.add('loading');
            if(btnText) btnText.textContent = 'Очищення...';

            fetch('{{ url('/clear-everything') }}')
                .then(function (res) { return res.text(); })
                .then(function () {
                    // 2. Успіх (трохи затримки, щоб юзер побачив процес)
                    setTimeout(() => {
                        console.log('Кеш очищено');
                        window.location.reload();
                    }, 500); 
                })
                .catch(function(err) {
                    console.error('Помилка очищення:', err);
                    clearCacheBtn.classList.remove('loading');
                    if(btnText) btnText.textContent = 'Помилка!';
                });
        });
    })();
</script>