<style>
    /* --- ШРИФТИ --- */
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

    /* --- ЗМІННІ --- */
    :root {
        /* Суцільний темний колір без прозорості */
        --sidebar-bg: #0f172a; 
        --sidebar-bg-hover: #1e293b;
        --accent-color: #6366f1;
        --text-muted: #94a3b8;
        --text-light: #f8fafc;
        --border-color: rgba(255, 255, 255, 0.08);
        --transition-speed: 0.3s;
    }

    /* --- БАЗА --- */
    .pro-sidebar {
        font-family: 'Plus Jakarta Sans', sans-serif;
        /* Важливо: Суцільний колір фону */
        background-color: var(--sidebar-bg) !important; 
        color: var(--text-muted);
        display: flex;
        flex-direction: column;
        height: 100vh;
        transition: width var(--transition-speed) cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1045;
        border-right: 1px solid var(--border-color);
        overflow: visible !important; 
        /* Тінь, щоб відділити від світлого контенту */
        box-shadow: 4px 0 24px rgba(0,0,0,0.4); 
    }

    /* --- ЛОГОТИП --- */
    .sidebar-header {
        height: 80px;
        min-height: 80px;
        display: flex;
        align-items: center;
        padding: 0 24px;
        border-bottom: 1px solid var(--border-color);
        background: var(--sidebar-bg); /* Суцільний фон */
        overflow: hidden;
    }

    .logo-box {
        min-width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #4f46e5, #8b5cf6);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.4rem;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        position: relative;
        z-index: 10;
    }

    .logo-text {
        margin-left: 16px;
        display: flex;
        flex-direction: column;
        white-space: nowrap;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .company { color: var(--text-light); font-weight: 800; font-size: 1.1rem; }
    .version { 
        color: var(--accent-color); 
        font-size: 0.7rem; 
        font-weight: 700; 
        text-transform: uppercase; 
        background: rgba(99, 102, 241, 0.1);
        padding: 2px 6px;
        border-radius: 4px;
        width: fit-content;
        margin-top: 2px;
    }

    /* --- НАВІГАЦІЯ --- */
    .sidebar-nav {
        flex: 1;
        padding: 24px 12px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        overflow-y: auto; 
        overflow-x: hidden;
        /* Стилізація скролу */
        scrollbar-width: thin;
        scrollbar-color: #334155 var(--sidebar-bg);
    }
    
    .sidebar-nav::-webkit-scrollbar { width: 4px; }
    .sidebar-nav::-webkit-scrollbar-track { background: var(--sidebar-bg); }
    .sidebar-nav::-webkit-scrollbar-thumb { background-color: #334155; border-radius: 10px; }

    .sidebar-link {
        display: flex;
        align-items: center;
        height: 50px;
        padding: 0 14px;
        border-radius: 10px;
        text-decoration: none;
        color: var(--text-muted);
        transition: all 0.2s ease;
        position: relative;
        white-space: nowrap;
        flex-shrink: 0;
        overflow: hidden; 
    }

    .icon-frame {
        min-width: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        margin-right: 14px;
        color: #64748b;
        transition: transform 0.2s, color 0.2s;
    }

    .item-text {
        font-weight: 600;
        font-size: 0.92rem;
        opacity: 0;
        transition: opacity 0.2s;
    }

    /* Hover */
    .sidebar-link:hover {
        background: var(--sidebar-bg-hover);
        color: var(--text-light);
    }
    .sidebar-link:hover .icon-frame { 
        color: var(--text-light); 
        transform: scale(1.1);
    }

    /* Active State */
    .sidebar-link.active {
        background: rgba(99, 102, 241, 0.1);
        color: #818cf8;
    }
    .sidebar-link.active .icon-frame { color: #818cf8; }
    
    /* Active indicator stripe */
    .sidebar-link.active::before {
        content: '';
        position: absolute;
        left: 0; top: 50%; transform: translateY(-50%);
        height: 20px; width: 3px;
        background: var(--accent-color);
        border-radius: 0 4px 4px 0;
    }

    .nav-divider {
        margin: 20px 0 10px 16px;
        text-transform: uppercase;
        font-size: 0.65rem;
        color: #475569;
        font-weight: 700;
        letter-spacing: 1px;
        opacity: 0;
        transition: opacity 0.2s;
        white-space: nowrap;
    }

    /* --- ФУТЕР --- */
    .sidebar-footer {
        padding: 16px;
        border-top: 1px solid var(--border-color);
        flex-shrink: 0; 
        position: relative; 
        z-index: 1200; 
        background: var(--sidebar-bg); /* Суцільний фон */
    }

    /* =================================================================
       ПК (DESKTOP)
       ================================================================= */
    @media (min-width: 992px) {
        .pro-sidebar {
            width: 80px; 
            position: fixed;
            top: 0; left: 0;
        }

        /* Розширення */
        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) {
            width: 280px;
        }

        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) .logo-text,
        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) .item-text,
        .pro-sidebar:hover:not(:has(.sidebar-footer:hover)) .nav-divider {
            opacity: 1;
            transition-delay: 0.05s;
        }

        .mobile-arrow { display: none; }
        .sidebar-footer > .sidebar-link > .item-text { display: none !important; }

        /* Кнопка налаштувань */
        .sidebar-footer > .sidebar-link {
            justify-content: center;
            width: 48px; height: 48px;
            margin: 0 auto;
            border-radius: 50%; 
            background: rgba(255,255,255,0.05);
            transition: all 0.3s;
        }
        
        .sidebar-footer > .sidebar-link .icon-frame { margin-right: 0; font-size: 1.4rem; }

        .sidebar-footer:hover > .sidebar-link {
            background: var(--accent-color);
            color: #fff;
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(99, 102, 241, 0.4);
        }
        
        .sidebar-footer:hover > .sidebar-link .bi-gear-wide-connected {
            animation: spin 3s linear infinite;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* --- POPUP MENU --- */
        .sidebar-footer .sidebar-submenu {
            position: absolute;
            left: 74px; 
            bottom: 20px;
            width: 260px; 
            
            /* Тут залишаємо трохи скла, бо це випадайка */
            background: #1e293b;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 8px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            
            display: flex; flex-direction: column; gap: 4px;
            opacity: 0; visibility: hidden;
            transform: translateX(-15px);
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 1500; 
        }

        .sidebar-footer:hover .sidebar-submenu {
            opacity: 1; visibility: visible;
            transform: translateX(0);
        }

        .sidebar-footer .sidebar-link-sub {
            padding: 10px 14px;
            font-size: 0.9rem;
            border-radius: 8px;
            color: #cbd5e1;
            display: flex; align-items: center; text-decoration: none;
            transition: all 0.2s;
        }
        
        .sidebar-footer .sidebar-link-sub:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            transform: translateX(4px);
        }
        
        .sidebar-footer .sidebar-link-sub .icon-frame {
            font-size: 1.1rem; margin-right: 12px; min-width: 24px; color: #94a3b8; 
        }
        .sidebar-footer .sidebar-link-sub:hover .icon-frame { color: var(--accent-color); }

        /* Трикутник */
        .sidebar-footer .sidebar-submenu::before {
            content: ''; position: absolute; left: -6px; bottom: 30px; width: 12px; height: 12px;
            background: #1e293b;
            border-left: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);
            transform: rotate(45deg);
        }
        /* Міст */
        .sidebar-footer .sidebar-submenu::after {
            content: ''; position: absolute; top: 0; bottom: 0; left: -30px; width: 30px;
        }

        .submenu-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 6px 0; }
        
        body { padding-left: 80px; transition: padding-left 0.3s; }
    }

    /* =================================================================
       МОБІЛЬНА (MOBILE)
       ================================================================= */
    @media (max-width: 991px) {
        .pro-sidebar { width: 280px !important; overflow-y: auto !important; box-shadow: none; border-right: none;}
        .logo-text, .item-text, .nav-divider { opacity: 1 !important; }
        .mobile-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--border-color); background: var(--sidebar-bg); }
        .btn-close-white { filter: invert(1); opacity: 0.7; }
        body { padding-left: 0 !important; }

        .sidebar-footer { padding: 10px 16px 40px 16px; background: transparent; border-top: 1px solid var(--border-color); }

        /* Кнопка відкриття */
        .sidebar-footer > .sidebar-link {
            width: 100%; justify-content: flex-start; height: 52px; padding: 0 16px;
            background: rgba(255,255,255,0.03); border-radius: 10px;
            cursor: pointer; border: 1px solid transparent;
        }
        
        .sidebar-footer.mobile-active > .sidebar-link {
            background: rgba(99, 102, 241, 0.1);
            border-color: rgba(99, 102, 241, 0.2);
            color: #fff;
        }

        .sidebar-footer > .sidebar-link .item-text { display: block !important; color: inherit; }
        
        .mobile-arrow { display: block; font-size: 0.8rem; transition: transform 0.3s ease; }
        .sidebar-footer.mobile-active .mobile-arrow { transform: rotate(180deg); color: var(--accent-color); }

        /* Акордеон */
        .sidebar-footer .sidebar-submenu {
            display: none; flex-direction: column; 
            margin-top: 8px; padding-left: 12px; gap: 4px;
        }

        .sidebar-footer.mobile-active .sidebar-submenu { display: flex; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .sidebar-footer .sidebar-link-sub {
            padding: 12px 16px; font-size: 0.95rem; color: #94a3b8; text-decoration: none;
            display: flex; align-items: center; border-radius: 10px;
        }
        .sidebar-footer .sidebar-link-sub:hover { background: rgba(255,255,255,0.05); color: #fff; }
        
        .sidebar-footer .sidebar-link-sub .icon-frame { font-size: 1.1rem; margin-right: 14px; min-width: 24px; color: #64748b; }
        .sidebar-footer .sidebar-submenu::before, .sidebar-footer .sidebar-submenu::after, .submenu-divider { display: none; }
    }
</style>

<aside class="pro-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="sidebarLabel">
    
    <div class="mobile-header d-lg-none">
        <span class="fw-bold text-white fs-5" style="letter-spacing: -0.5px;">Меню</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#mobileSidebar" aria-label="Close"></button>
    </div>

    <div class="sidebar-header">
        <div class="logo-box">
            <i class="bi bi-buildings-fill"></i>
        </div>
        <div class="logo-text">
            <span class="company">DomCRM</span>
            <span class="version">Pro Version</span>
        </div>
    </div>

    @php
        $currentUser = auth()->user();
        $isOwner = $currentUser?->isOwner() ?? false;
        $isOperator = $currentUser?->hasAnyRole([\App\Models\User::ROLE_OPERATOR]) ?? false;
        $isPacker = $currentUser?->hasAnyRole([\App\Models\User::ROLE_PACKER]) ?? false;
        $canSeeOperations = $isOwner || $isOperator;
        $canSeePacking = $isOwner || $isOperator || $isPacker;
        $canSeeCommunication = $isOwner || $isOperator;
        $canSeeContent = $isOwner || $isOperator;
        $inboxUnread = $canSeeCommunication ? (int) \App\Models\InboxConversation::sum('unread_count') : 0;
    @endphp

    <nav class="sidebar-nav">
        <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->is('dashboard') ? 'active' : '' }}">
            <span class="icon-frame"><i class="bi bi-grid-1x2-fill"></i></span>
            <span class="item-text">Дашборд</span>
        </a>

        @if($canSeeOperations)
            <a href="{{ url('/orders') }}" class="sidebar-link {{ request()->is('orders*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-basket2-fill"></i></span>
                <span class="item-text">Замовлення</span>
            </a>

            <a href="{{ url('/orders/create') }}" class="sidebar-link">
                <span class="icon-frame"><i class="bi bi-plus-circle-fill"></i></span>
                <span class="item-text">Створити Замовлення</span>
            </a>
        @endif

        @if($canSeePacking || $canSeeOperations)
            <div class="nav-divider">Склад</div>
        @endif

        @php
            $packingCount = 0;

            if ($canSeePacking) {
                $packingService = app(\App\Services\PackingService::class);
                $packingService->releaseStaleOrders();
                $queueStatusIds = $packingService->queueStatusIds();

                $packingCount = \App\Models\Order::query()
                    ->when($queueStatusIds, fn ($query) => $query->whereIn('status_id', $queueStatusIds), fn ($query) => $query->whereRaw('1 = 0'))
                    ->where(function ($query) {
                        $query->whereNull('packing_status')
                            ->orWhereIn('packing_status', ['pending', 'processing', 'skipped']);
                    })
                    ->count();
            }
        @endphp

        @if($canSeePacking)
            <a href="{{ route('packing.list') }}" class="sidebar-link {{ request()->is('packing*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-box-seam-fill"></i></span>
                <span class="item-text">Пакування</span>

                @if($packingCount > 0)
                    <span class="badge rounded-pill ms-auto me-2 shadow-sm" style="background: var(--accent-color); font-size: 0.75rem; padding: 5px 10px;">{{ $packingCount }}</span>
                @endif
            </a>
        @endif

        @if($canSeeOperations)
            <a href="{{ url('/products') }}" class="sidebar-link {{ request()->is('products*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-archive-fill"></i></span>
                <span class="item-text">Товари</span>
            </a>

            @php $reviewCount = \App\Models\Order::where('needs_review', true)->count(); @endphp
            <a href="{{ route('integrations.review') }}" class="sidebar-link {{ request()->is('integrations/review*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-link-45deg"></i></span>
                <span class="item-text">Незмаплені</span>
                @if($reviewCount > 0)
                    <span class="badge rounded-pill ms-auto me-2 shadow-sm" style="background:#f59e0b; font-size:0.75rem; padding:5px 10px;">{{ $reviewCount }}</span>
                @endif
            </a>
        @endif

        @if($canSeeCommunication)
            <div class="nav-divider">Комунікація</div>

            <a href="{{ route('inbox.index') }}" class="sidebar-link {{ request()->is('inbox*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-chat-dots-fill"></i></span>
                <span class="item-text">Чат</span>
                @if($inboxUnread > 0)
                    <span class="badge rounded-pill ms-auto me-2 shadow-sm" style="background:#ef4444; font-size:0.75rem; padding:5px 10px;">{{ $inboxUnread }}</span>
                @endif
            </a>

            <a href="{{ url('/customers') }}" class="sidebar-link {{ request()->is('customers*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-people-fill"></i></span>
                <span class="item-text">Клієнти</span>
            </a>
        @endif

        @if($canSeeContent)
            <div class="nav-divider">Контент</div>

            <a href="{{ route('gallery.index') }}" class="sidebar-link {{ request()->is('gallery*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-images"></i></span>
                <span class="item-text">Галерея</span>
            </a>

            <a href="{{ route('templates.index') }}" class="sidebar-link {{ request()->is('templates*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-journal-text"></i></span>
                <span class="item-text">Шаблони</span>
            </a>
        @endif
    </nav>

    <div class="sidebar-footer mt-auto" id="settings-footer">
        
        <div class="sidebar-link" id="settings-toggle">
            <span class="icon-frame"><i class="bi bi-gear-wide-connected"></i></span>
            <span class="item-text">Налаштування</span>
            <i class="bi bi-chevron-down ms-auto mobile-arrow"></i>
        </div>
        
        <div class="sidebar-submenu">
            <a href="{{ route('profile.edit') }}" class="sidebar-link-sub {{ request()->is('profile*') ? 'active' : '' }}">
                <span class="icon-frame"><i class="bi bi-person-circle"></i></span>
                <span class="item-text-sub">Профіль</span>
            </a>

            @if($isOwner)
                <a href="{{ route('settings.team.index') }}" class="sidebar-link-sub {{ request()->is('settings/team*') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-people"></i></span>
                    <span class="item-text-sub">Команда</span>
                </a>
                <a href="{{ route('finance.index') }}" class="sidebar-link-sub {{ request()->is('finance*') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-wallet2"></i></span>
                    <span class="item-text-sub">Фінанси</span>
                </a>

                <div class="submenu-divider"></div>

                <a href="{{ route('settings.novaPoshta.index') }}" class="sidebar-link-sub {{ request()->is('settings/nova-poshta') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-box-seam"></i></span>
                    <span class="item-text-sub">Нова Пошта</span>
                </a>
                <a href="{{ route('settings.integrations.index') }}" class="sidebar-link-sub {{ request()->is('settings/integrations*') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-hdd-network"></i></span>
                    <span class="item-text-sub">Інтеграції</span>
                </a>
                <a href="{{ route('settings.meta.index') }}" class="sidebar-link-sub {{ request()->is('settings/meta*') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-facebook"></i></span>
                    <span class="item-text-sub">Facebook / IG</span>
                </a>
                <a href="{{ route('settings.categories.index') }}" class="sidebar-link-sub {{ request()->is('settings/categories') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-tags"></i></span>
                    <span class="item-text-sub">Категорії</span>
                </a>
                <a href="{{ route('settings.colors.index') }}" class="sidebar-link-sub {{ request()->is('settings/colors') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-palette2"></i></span>
                    <span class="item-text-sub">Кольори</span>
                </a>
                <a href="{{ route('settings.tags.index') }}" class="sidebar-link-sub {{ request()->is('settings/tags') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-bookmark-star"></i></span>
                    <span class="item-text-sub">Теги</span>
                </a>
                <a href="{{ route('settings.statuses.index') }}" class="sidebar-link-sub {{ request()->is('settings/statuses') ? 'active' : '' }}">
                    <span class="icon-frame"><i class="bi bi-list-check"></i></span>
                    <span class="item-text-sub">Статуси</span>
                </a>
            @endif
        </div>
    </div>
</aside>

<script>
// Mobile Toggle Logic
document.addEventListener('DOMContentLoaded', () => {
    const settingsToggle = document.getElementById('settings-toggle');
    const settingsFooter = document.getElementById('settings-footer');

    if (settingsToggle && settingsFooter) {
        settingsToggle.addEventListener('click', (e) => {
            if (window.innerWidth < 992) {
                settingsFooter.classList.toggle('mobile-active');
            }
        });
    }
});
</script>

