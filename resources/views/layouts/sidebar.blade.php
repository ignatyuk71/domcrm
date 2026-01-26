<style>
    /* --- ШРИФТИ --- */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

    /* --- БАЗОВІ СТИЛІ --- */
    .pro-sidebar {
        font-family: 'Inter', sans-serif;
        background: #0f172a;
        color: #94a3b8;
        display: flex;
        flex-direction: column;
        height: 100vh;
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1045;
        border-right: 1px solid rgba(255,255,255,0.05);
        overflow-x: hidden;
        /* Тонкий скрол */
        scrollbar-width: thin; 
        scrollbar-color: #334155 #0f172a;
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
        overflow: hidden;
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
        opacity: 0;
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
        overflow-x: hidden;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        height: 52px;
        padding: 0 12px;
        border-radius: 12px;
        text-decoration: none;
        color: #94a3b8;
        transition: all 0.2s ease;
        position: relative;
        white-space: nowrap;
        cursor: pointer;
        user-select: none;
    }

    .icon-frame {
        min-width: 30px;
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
        flex-grow: 1;
    }

    /* Стрілка для підменю */
    .chevron-icon {
        font-size: 0.8rem;
        transition: transform 0.3s ease, opacity 0.3s;
        opacity: 0;
    }
    
    /* Поворот стрілки коли меню відкрите */
    .sidebar-link[aria-expanded="true"] .chevron-icon {
        transform: rotate(90deg);
    }

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

    .nav-divider {
        margin: 20px 0 10px 16px;
        text-transform: uppercase;
        font-size: 0.7rem;
        color: #475569;
        font-weight: 700;
        letter-spacing: 1px;
        opacity: 0;
        transition: opacity 0.3s;
        white-space: nowrap;
    }

    /* --- FOOTER & SUBMENU (НАЛАШТУВАННЯ) --- */
    .sidebar-footer {
        padding: 10px 12px 20px 12px;
        border-top: 1px solid rgba(255,255,255,0.05);
        flex-shrink: 0;
        background: #0f172a;
    }

    /* Контейнер підменю */
    .sidebar-submenu-inner {
        padding-left: 20px;
        margin-top: 5px;
        margin-left: 15px;
        border-left: 2px solid rgba(255,255,255,0.1);
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .submenu-link {
        display: flex;
        align-items: center;
        padding: 10px 12px;
        color: #94a3b8;
        text-decoration: none;
        font-size: 0.9rem;
        border-radius: 8px;
        transition: all 0.2s;
        white-space: nowrap;
        opacity: 0; /* Приховано поки сайдбар згорнутий */
    }

    .submenu-link:hover {
        color: #fff;
        background: rgba(255,255,255,0.05);
    }

    .submenu-link.active {
        color: #818cf8;
        font-weight: 600;
        background: rgba(99, 102, 241, 0.1);
    }

    .submenu-link i {
        margin-right: 10px;
        font-size: 1.1rem;
    }

    /* --- ЛОГІКА ПК --- */
    @media (min-width: 992px) {
        .pro-sidebar {
            width: 80px;
            position: fixed;
            top: 0;
            left: 0;
        }

        .pro-sidebar:hover {
            width: 280px;
            box-shadow: 10px 0 30px rgba(0,0,0,0.3);
        }

        /* Показуємо текст тільки при наведенні */
        .pro-sidebar:hover .logo-text,
        .pro-sidebar:hover .item-text,
        .pro-sidebar:hover .nav-divider,
        .pro-sidebar:hover .chevron-icon,
        .pro-sidebar:hover .submenu-link {
            opacity: 1;
            transition-delay: 0.05s;
        }
        
        body { padding-left: 80px; transition: padding-left 0.3s; }
    }

    /* --- ЛОГІКА МОБІЛЬНА --- */
    @media (max-width: 991px) {
        .pro-sidebar {
            width: 280px !important;
            transform: none;
            box-shadow: none;
        }
        
        .logo-text, .item-text, .nav-divider, .chevron-icon, .submenu-link {
            opacity: 1 !important;
        }

        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #0f172a;
        }
        
        .btn-close-white { filter: invert(1); opacity: 0.8; }
        body { padding-left: 0 !important; }
    }
</style>

<aside class="pro-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="sidebarLabel">
    
    <div class="mobile-header d-lg-none">
        <span class="fw-bold text-white fs-5">Меню</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#mobileSidebar" aria-label="Close"></button>
    </div>

    <div class="sidebar-header">
        <div class="logo-box">
            <i class="bi bi-buildings-fill"></i>
        </div>
        <div class="logo-text">
            <span class="company">Dom-CRM</span>
            <span class="version">Pro Version</span>
        </div>
    </div>

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
            <span class="item-text">Створити Замовлення</span>
        </a>

        <div class="nav-divider">Склад</div>

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

        <a href="{{ url('/messenger') }}" class="sidebar-link {{ request()->is('messenger*') ? 'active' : '' }}">
            <span class="icon-frame">
                <i class="bi bi-chat-dots-fill"></i>
                <span id="chat-unread-dot" class="chat-badge-collapsed d-none"></span>
            </span>
            <span class="item-text">Чати</span>
            <span id="chat-unread-badge" class="badge bg-danger rounded-pill ms-auto me-3 chat-badge-expanded d-none" style="font-size: 0.7rem;"></span>
        </a>

        <a href="{{ url('/messenger/funnel') }}" class="sidebar-link {{ request()->is('messenger/funnel') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-kanban-fill"></i></span>
            <span class="item-text">Воронка чатів</span>
        </a>
    </nav>

    <div class="sidebar-footer mt-auto">
        @php
            $isSettingsActive = request()->is('profile*') || request()->is('finance*') || request()->is('gallery*') || request()->is('templates*');
        @endphp

        <a class="sidebar-link {{ $isSettingsActive ? 'active' : '' }}" 
           data-bs-toggle="collapse" 
           href="#settingsCollapse" 
           role="button" 
           aria-expanded="{{ $isSettingsActive ? 'true' : 'false' }}" 
           aria-controls="settingsCollapse">
            
            <span class="icon-frame"><i class="bi bi-gear-wide-connected"></i></span>
            <span class="item-text">Налаштування</span>
            <i class="bi bi-chevron-right chevron-icon ms-auto me-2"></i>
        </a>

        <div class="collapse {{ $isSettingsActive ? 'show' : '' }}" id="settingsCollapse">
            <nav class="sidebar-submenu-inner">
                
                <a href="{{ route('profile.edit') }}" class="submenu-link {{ request()->is('profile*') ? 'active' : '' }}">
                    <i class="bi bi-person-circle"></i>
                    <span>Профіль</span>
                </a>

                <a href="{{ route('finance.index') }}" class="submenu-link {{ request()->is('finance*') ? 'active' : '' }}">
                    <i class="bi bi-cash-coin"></i>
                    <span>Фінанси</span>
                </a>

                <a href="{{ route('gallery.index') }}" class="submenu-link {{ request()->is('gallery*') ? 'active' : '' }}">
                    <i class="bi bi-images"></i>
                    <span>Галерея</span>
                </a>

                <a href="{{ route('templates.index') }}" class="submenu-link {{ request()->is('templates*') ? 'active' : '' }}">
                    <i class="bi bi-chat-text-fill"></i>
                    <span>Шаблони</span>
                </a>

                <form method="POST" action="{{ route('logout') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="submenu-link w-100 text-start border-0 bg-transparent">
                        <i class="bi bi-box-arrow-left text-danger"></i>
                        <span class="text-danger">Вихід</span>
                    </button>
                </form>
            </nav>
        </div>
    </div>
</aside>

<script>
(() => {
  const badge = document.getElementById('chat-unread-badge');
  const dot = document.getElementById('chat-unread-dot');
  if (!badge) return;

  const updateBadge = async () => {
    try {
      const response = await fetch('/api/chat/unread-count', { headers: { 'Accept': 'application/json' } });
      if (!response.ok) return;
      const data = await response.json();
      const count = Number(data?.count || 0);

      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.remove('d-none');
        if (dot) dot.classList.remove('d-none');
      } else {
        badge.classList.add('d-none');
        if (dot) dot.classList.add('d-none');
      }
    } catch (_) { }
  };

  updateBadge();
  setInterval(updateBadge, 30000);
})();
</script>

<style>
    /* Стилі бейджа чату */
    .chat-badge-collapsed {
        position: absolute;
        top: -2px;
        right: -2px;
        width: 14px;
        height: 14px;
        background: #ef4444;
        border: 2px solid #0f172a;
        border-radius: 50%;
    }

    @media (min-width: 992px) {
        .chat-badge-expanded { display: none; }
        .pro-sidebar:hover .chat-badge-expanded { display: inline-flex; }
        .pro-sidebar:hover .chat-badge-collapsed { display: none; }
    }
</style>