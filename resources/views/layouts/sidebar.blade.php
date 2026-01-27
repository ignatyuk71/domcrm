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
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1045;
        border-right: 1px solid rgba(255,255,255,0.05);
        /* Дозволяємо випадаючому меню виходити за межі на ПК */
        overflow: visible !important; 
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
        transition: opacity 0.2s;
    }

    .company { color: #fff; font-weight: 800; font-size: 1.1rem; letter-spacing: 0.5px; }
    .version { color: #64748b; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

    /* --- НАВІГАЦІЯ (СЕРЕДИНА) --- */
    .sidebar-nav {
        flex: 1;
        padding: 20px 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        /* Скрол тільки тут на ПК */
        overflow-y: auto; 
        overflow-x: hidden;
        scrollbar-width: none; 
        -ms-overflow-style: none;
    }
    .sidebar-nav::-webkit-scrollbar { display: none; }

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
        flex-shrink: 0;
        overflow: hidden; 
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
        transition: opacity 0.2s;
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
        transition: opacity 0.2s;
        white-space: nowrap;
    }

    /* --- ФУТЕР (НАЛАШТУВАННЯ) --- */
    .sidebar-footer {
        padding: 20px;
        border-top: 1px solid rgba(255,255,255,0.05);
        flex-shrink: 0; 
        position: relative; 
        z-index: 1200; 
    }

    /* =================================================================
       ЛОГІКА ПК (DESKTOP) - Тільки для екранів ширше 992px
       ================================================================= */
    @media (min-width: 992px) {
        .pro-sidebar {
            width: 80px;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #0f172a !important;
        }

        /* Розширюємо сайдбар при наведенні, АЛЕ НЕ ЯКЩО ми на футері */
        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) {
            width: 280px;
            box-shadow: 10px 0 30px rgba(0,0,0,0.3);
        }

        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) .logo-text,
        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) .item-text,
        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) .nav-divider {
            opacity: 1;
            transition-delay: 0.1s;
        }

        /* Кнопка налаштувань на ПК - тільки іконка */
        .sidebar-footer > .sidebar-link > .item-text { display: none !important; }

        .sidebar-footer > .sidebar-link {
            justify-content: center;
            width: 46px;
            height: 46px;
            margin: 0 auto;
            border-radius: 12px;
            padding: 0;
            background: transparent;
            position: relative; 
        }
        .sidebar-footer > .sidebar-link .icon-frame { margin-right: 0; }

        .sidebar-footer:hover > .sidebar-link {
            background: #1e293b;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        /* --- ВИПАДАЮЧЕ МЕНЮ (Pop-out) ДЛЯ ПК --- */
        .sidebar-footer .sidebar-submenu {
            position: absolute;
            left: 70px; 
            bottom: 15px;
            width: 270px; 
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            
            display: flex;
            flex-direction: column;
            gap: 2px;
            
            opacity: 0;
            visibility: hidden;
            transform: translateX(-10px);
            transition: all 0.2s ease;
            z-index: 1500; 
        }

        .sidebar-footer .sidebar-submenu::before {
            content: '';
            position: absolute;
            left: -6px; bottom: 24px;
            width: 12px; height: 12px;
            background: #1e293b;
            border-left: 1px solid rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transform: rotate(45deg);
        }

        .sidebar-footer .sidebar-submenu::after {
            content: '';
            position: absolute;
            top: 0; bottom: 0; left: -25px; width: 25px;
        }

        .sidebar-footer:hover .sidebar-submenu {
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
        }

        .sidebar-footer .sidebar-link-sub {
            padding: 10px 14px;
            font-size: 0.9rem;
            border-radius: 8px;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            text-decoration: none;
            white-space: nowrap; 
        }
        .sidebar-footer .sidebar-link-sub:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
        }
        .sidebar-footer .sidebar-link-sub .icon-frame {
            font-size: 1.1rem; margin-right: 12px; min-width: 24px; color: #94a3b8;
        }
        .sidebar-footer .sidebar-link-sub:hover .icon-frame { color: #818cf8; }
        
        .submenu-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 6px 4px; }
        
        body { padding-left: 80px; transition: padding-left 0.3s; }
    }

    /* =================================================================
       ЛОГІКА МОБІЛЬНА (MOBILE) - Простий список
       ================================================================= */
    @media (max-width: 991px) {
        .pro-sidebar { 
            width: 280px !important; 
            overflow-y: auto !important; /* На мобільному скролимо все разом */
        }
        
        /* Робимо всі тексти видимими */
        .logo-text, .item-text, .nav-divider { opacity: 1 !important; }
        
        .mobile-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: #0f172a; }
        .btn-close-white { filter: invert(1); opacity: 0.8; }
        body { padding-left: 0 !important; }

        /* --- СКИДАННЯ СТИЛІВ ФУТЕРА ДЛЯ МОБІЛЬНОГО --- */
        .sidebar-footer {
            height: auto;
            border-top: 1px solid rgba(255,255,255,0.05);
            padding: 10px 12px 30px 12px; /* Трохи відступу знизу */
        }

        /* Заголовок "Налаштування" як звичайний пункт меню */
        .sidebar-footer > .sidebar-link {
            width: 100%;
            justify-content: flex-start;
            height: 52px;
            margin-bottom: 5px;
            padding: 0 12px;
            background: transparent;
            pointer-events: none; /* Щоб не клікалось, бо це просто заголовок групи */
        }
        
        /* Повертаємо текст "Налаштування" */
        .sidebar-footer > .sidebar-link .item-text { 
            display: block !important; 
            color: #fff; 
        }

        /* Стиль списку підменю (акордеон) */
        .sidebar-footer .sidebar-submenu {
            position: static; /* Відміняємо позиціонування */
            display: flex;
            flex-direction: column;
            background: transparent;
            box-shadow: none;
            border: none;
            padding: 0 0 0 10px; /* Відступ зліва для ієрархії */
            width: 100%;
            opacity: 1;
            visibility: visible;
            transform: none;
            gap: 2px;
        }

        /* Пункти підменю */
        .sidebar-footer .sidebar-link-sub {
            padding: 10px 12px;
            font-size: 0.9rem;
            color: #94a3b8;
            text-decoration: none;
            display: flex;
            align-items: center;
            border-left: 1px solid rgba(255,255,255,0.1); /* Лінія зліва для краси */
            border-radius: 0 8px 8px 0;
            margin-left: 14px; /* Вирівнювання під іконку шестерні */
        }
        
        .sidebar-footer .sidebar-link-sub .icon-frame {
            font-size: 1.1rem; 
            margin-right: 12px; 
            min-width: 24px; 
            color: #64748b;
        }

        /* Ховаємо декоративні елементи ПК версії */
        .sidebar-footer .sidebar-submenu::before, 
        .sidebar-footer .sidebar-submenu::after, 
        .submenu-divider { 
            display: none; 
        }
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

        <a href="{{ route('gallery.index') }}" class="sidebar-link {{ request()->is('gallery*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-images"></i></span>
            <span class="item-text">Галерея</span>
        </a>

        <a href="{{ route('templates.index') }}" class="sidebar-link {{ request()->is('templates*') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-chat-text-fill"></i></span>
            <span class="item-text">Шаблони</span>
        </a>
    </nav>

    <div class="sidebar-footer mt-auto">
        <div class="sidebar-link">
            <span class="icon-frame"><i class="bi bi-gear-wide-connected"></i></span>
            <span class="item-text">Налаштування</span>
        </div>
        
        <div class="sidebar-submenu">
            <a href="{{ route('profile.edit') }}" class="sidebar-link-sub {{ request()->is('profile*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-person-circle"></i></span>
                <span class="item-text-sub">Профіль</span>
            </a>
            <a href="{{ route('finance.index') }}" class="sidebar-link-sub {{ request()->is('finance*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-cash-coin"></i></span>
                <span class="item-text-sub">Фінанси</span>
            </a>

            <div class="submenu-divider"></div>

             <a href="#" class="sidebar-link-sub">
                <span class="icon-frame"><i class="bi bi-share-fill"></i></span>
                <span class="item-text-sub">Соціалізація</span>
            </a>
             <a href="#" class="sidebar-link-sub">
                <span class="icon-frame"><i class="bi bi-truck-front-fill"></i></span>
                <span class="item-text-sub">Інтеграція Нова Пошта</span>
            </a>
            <a href="#" class="sidebar-link-sub">
                <span class="icon-frame"><i class="bi bi-three-dots"></i></span>
                <span class="item-text-sub">Інші налаштування</span>
            </a>
        </div>
    </div>
</aside>

<script>
(() => {
  const badge = document.getElementById('chat-unread-badge');
  const dot = document.getElementById('chat-unread-dot');
  if (!badge) return;

  const updateBadge = async () => {
    if (document.hidden) return; 
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
    } catch (e) { console.error(e); }
  };
  updateBadge();
  setInterval(updateBadge, 30000);
})();
</script>

<style>
    .chat-badge-collapsed {
        position: absolute;
        top: -2px;
        right: -2px;
        width: 15px;
        height: 15px;
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