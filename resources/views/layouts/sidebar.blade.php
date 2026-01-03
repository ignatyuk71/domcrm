@php
    $isActive = fn($pattern) => request()->is($pattern) 
        ? 'nav-link active d-flex align-items-center gap-3 mb-1' 
        : 'nav-link link-light text-opacity-75 d-flex align-items-center gap-3 mb-1';
@endphp

<aside class="d-none d-lg-flex flex-column flex-shrink-0 bg-dark text-white border-end border-secondary sticky-top" style="width: 280px; height: 100vh; overflow-y: auto;">
    <a href="{{ route('dashboard') }}" class="d-flex align-items-center text-white text-decoration-none p-3 border-bottom border-secondary">
        <div class="d-flex align-items-center justify-content-center rounded shadow-sm bg-primary bg-gradient text-white fw-bold me-3" style="width: 40px; height: 40px;">
            DM
        </div>
        <div class="lh-1">
            <div class="fw-bold">Dom-CRM</div>
            <small class="text-white-50" style="font-size: 0.75rem;">Україна • прототип</small>
        </div>
    </a>

    <div class="flex-grow-1 p-3">
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item">
                <a href="{{ route('dashboard') }}" class="{{ $isActive('dashboard') }}">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">🏠</span>
                    Dashboard
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ url('/orders') }}" class="{{ $isActive('orders') }}">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">🧾</span>
                    Замовлення
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ url('/orders/create') }}" class="{{ $isActive('orders/create') }}">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">➕</span>
                    Додати замовлення
                </a>
            </li>

            <li class="nav-item mt-3">
                <div class="text-uppercase text-white-50 fw-bold small px-3 mb-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">Склад</div>
            </li>
            <li class="nav-item">
                <a href="{{ url('/products') }}" class="{{ $isActive('products') }}">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">🛒</span>
                    Товари
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ url('/products/create') }}" class="{{ $isActive('products/create') }}">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">➕</span>
                    Додати товар
                </a>
            </li>

            <li class="nav-item mt-3">
                <div class="text-uppercase text-white-50 fw-bold small px-3 mb-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">Інше</div>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link link-light text-opacity-75 d-flex align-items-center gap-3 mb-1">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">👥</span>
                    Клієнти
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link link-light text-opacity-75 d-flex align-items-center gap-3 mb-1">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">🚚</span>
                    Доставка
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link link-light text-opacity-75 d-flex align-items-center gap-3 mb-1">
                    <span class="d-flex align-items-center justify-content-center rounded bg-secondary bg-opacity-25" style="width: 32px; height: 32px;">⚙️</span>
                    Налаштування
                </a>
            </li>
        </ul>
    </div>

    <div class="mt-auto p-3 border-top border-secondary small text-white-50 d-flex justify-content-between">
        <span>Версія UI</span>
        <span class="fw-bold text-white">v1</span>
    </div>
</aside>
