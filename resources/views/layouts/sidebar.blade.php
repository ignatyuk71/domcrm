<aside class="pro-sidebar">
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
            <span class="item-text">Створити ТТН</span>
        </a>

        <div class="nav-divider">Складський облік</div>

        <a href="{{ url('/products') }}" class="sidebar-link">
            <span class="icon-frame"><i class="bi bi-box-seam-fill"></i></span>
            <span class="item-text">Товари</span>
        </a>

        <a href="#" class="sidebar-link">
            <span class="icon-frame"><i class="bi bi-people-fill"></i></span>
            <span class="item-text">База клієнтів</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="#" class="sidebar-link">
            <span class="icon-frame"><i class="bi bi-gear-wide-connected"></i></span>
            <span class="item-text">Налаштування</span>
        </a>
    </div>
</aside>