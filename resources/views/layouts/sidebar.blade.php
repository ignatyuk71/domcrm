<style>
    /* --- ШРИФТИ --- */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

    /* --- БАЗОВІ СТИЛІ (Темна тема) --- */
    .pro-sidebar {
        font-family: 'Inter', sans-serif;
        background: #0f172a;
        color: #94a3b8;
        display: flex;
        flex-direction: column;
        height: 100vh;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1045; /* Вище ніж navbar */
        border-right: 1px solid rgba(255,255,255,0.05);
        overflow-x: hidden;
    }

    /* --- ЛОГОТИП --- */
    .sidebar-header {
        height: 80px;
        min-height: 80px;
        display: flex;
        align-items: center;
        padding: 0 20px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        background: rgba(0,0,0,0.2);
        flex-shrink: 0;
    }

    .logo-box {
        min-width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.4rem;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
    }

    .logo-text {
        margin-left: 15px;
        display: flex;
        flex-direction: column;
        white-space: nowrap;
        opacity: 0; /* За замовчуванням приховано на ПК */
        transition: opacity 0.3s;
    }

    .company { color: #fff; font-weight: 800; font-size: 1.1rem; letter-spacing: 0.5px; }
    .version { color: #64748b; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

    /* --- НАВІГАЦІЯ --- */
    .sidebar-nav {
        flex: 1;
        padding: 20px 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        overflow-y: auto;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        height: 52px;
        padding: 0 12px; /* Внутрішні відступи */
        border-radius: 12px;
        text-decoration: none;
        color: #94a3b8;
        transition: all 0.2s ease;
        position: relative;
        white-space: nowrap;
    }

    .icon-frame {
        min-width: 30px; /* Фіксоване місце під іконку */
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        margin-right: 14px;
        color: #64748b;
        transition: color 0.2s;
    }

    .item-text {
        font-weight: 600;
        font-size: 0.95rem;
        opacity: 0;
        transition: opacity 0.3s;
    }

    /* Ховер ефекти */
    .sidebar-link:hover {
        background: rgba(255,255,255,0.08);
        color: #f1f5f9;
    }
    .sidebar-link:hover .icon-frame { color: #f1f5f9; }

    .sidebar-link.active {
        background: linear-gradient(90deg, rgba(99, 102, 241, 0.15), transparent);
        color: #818cf8;
        border-left: 3px solid #6366f1;
    }
    .sidebar-link.active .icon-frame { color: #818cf8; }

    /* Розділювач */
    .nav-divider {
        margin: 20px 0 10px 16px;
        text-transform: uppercase;
        font-size: 0.7rem;
        color: #475569;
        font-weight: 700;
        letter-spacing: 1px;
        opacity: 0;
        transition: opacity 0.3s;
    }

    .sidebar-footer {
        padding: 20px;
        border-top: 1px solid rgba(255,255,255,0.05);
        flex-shrink: 0;
    }

    /* --- ЛОГІКА ПК (DESKTOP) --- */
    @media (min-width: 992px) {
        .pro-sidebar {
            width: 80px;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #0f172a !important; /* ВИПРАВЛЕНО: примусовий темний фон */
        }

        .pro-sidebar:hover {
            width: 280px;
            box-shadow: 10px 0 30px rgba(0,0,0,0.3);
        }

        /* Показуємо текст при наведенні */
        .pro-sidebar:hover .logo-text,
        .pro-sidebar:hover .item-text,
        .pro-sidebar:hover .nav-divider {
            opacity: 1;
            transition-delay: 0.1s;
        }

        /* Відступ для контенту на сторінці */
        body { padding-left: 80px; transition: padding-left 0.3s; }
    }

    /* --- ЛОГІКА МОБІЛЬНА (MOBILE) --- */
    @media (max-width: 991px) {
        /* Скидаємо стилі фіксованого сайдбару для мобільного */
        .pro-sidebar {
            width: 280px !important; /* Фіксована ширина */
            transform: none;
            box-shadow: none;
            background-color: #0f172a !important; /* На всяк випадок */
        }
        
        /* Текст завжди видно на мобільному */
        .logo-text, .item-text, .nav-divider {
            opacity: 1 !important;
        }

        /* Стилі для offcanvas header */
        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #0f172a;
        }
        
        .btn-close-white {
            filter: invert(1);
            opacity: 0.8;
        }

        /* Прибираємо відступ body на мобільному */
        body { padding-left: 0 !important; }
    }
</style>

<!-- Sidebar -->
<aside class="pro-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="sidebarLabel">
    
    <!-- Кнопка закриття (тільки для мобільного) -->
    <div class="mobile-header d-lg-none">
        <span class="fw-bold text-white fs-5">Меню</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#mobileSidebar" aria-label="Close"></button>
    </div>

    <!-- Логотип -->
    <div class="sidebar-header">
        <div class="logo-box">
            <i class="bi bi-buildings-fill"></i>
        </div>
        <div class="logo-text">
            <span class="company">Dom-CRM</span>
            <span class="version">Pro Version</span>
        </div>
    </div>

    <!-- Навігація -->
    <nav class="sidebar-nav">
        <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->is('dashboard') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-grid-1x2-fill"></i></span>
            <span class="item-text">Дашборд</span>
        </a>

        <a href="{{ url('/orders') }}" class="sidebar-link {{ request()->is('orders*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-basket2-fill"></i></span>
            <span class="item-text">Замовлення</span>
        </a>

        <a href="{{ url('/orders/create') }}" class="sidebar-link">
            <span class="icon-frame"><i class="bi bi-plus-square-fill"></i></span>
            <span class="item-text">Створити ТТН</span>
        </a>

        <div class="nav-divider">Склад</div>

        <!-- Отримуємо кількість замовлень у черзі пакування -->
        @php
            $packingCount = \App\Models\Order::whereIn('status_id', config('packing.status_ids.queue', [4]))->count();
        @endphp

        <a href="{{ route('packing.list') }}" class="sidebar-link {{ request()->is('packing*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-box-seam-fill"></i></span>
            <span class="item-text">Пакування</span>
            
            @if($packingCount > 0)
                <span class="badge bg-primary rounded-pill ms-auto me-3" style="font-size: 0.7rem;">{{ $packingCount }}</span>
            @endif
        </a>

        <a href="{{ url('/products') }}" class="sidebar-link {{ request()->is('products*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-archive-fill"></i></span>
            <span class="item-text">Товари</span>
        </a>

        <div class="nav-divider">Клієнти та Чати</div>

        <a href="{{ url('/customers') }}" class="sidebar-link {{ request()->is('customers*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-people-fill"></i></span>
            <span class="item-text">База клієнтів</span>
        </a>

        <a href="{{ url('/chats') }}" class="sidebar-link {{ request()->is('chats*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-chat-dots-fill"></i></span>
            <span class="item-text">Чати</span>
            <!-- Бейдж повідомлень (червоний) - статичний для прикладу -->
            <span class="badge bg-danger rounded-pill ms-auto me-3" style="font-size: 0.7rem;">3</span>
        </a>
    </nav>

    <!-- Футер -->
    <div class="sidebar-footer mt-auto">
        <a href="{{ route('profile.edit') }}" class="sidebar-link">
            <span class="icon-frame"><i class="bi bi-gear-wide-connected"></i></span>
            <span class="item-text">Налаштування</span>
        </a>
    </div>
</aside>