@props(['active' => 'sales'])

<nav @class(['analytics-section-nav', 'analytics-section-nav--soles' => in_array($active, ['soles', 'costs'])]) aria-label="Розділи аналітики">
    <a href="{{ route('analytics.sales') }}" @class(['analytics-section-link', 'active' => $active === 'sales'])
       @if($active === 'sales') aria-current="page" @endif>
        <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
        <span>Продажі</span>
    </a>
    <a href="{{ route('inventory.soles') }}" @class(['analytics-section-link', 'active' => $active === 'soles'])
       @if($active === 'soles') aria-current="page" @endif>
        <i class="bi bi-layers" aria-hidden="true"></i>
        <span>Запас підошви</span>
    </a>
    <a href="{{ route('analytics.costs') }}" @class(['analytics-section-link', 'active' => $active === 'costs'])
       @if($active === 'costs') aria-current="page" @endif>
        <i class="bi bi-calculator" aria-hidden="true"></i>
        <span>Собівартість</span>
    </a>
</nav>

<style>
    .analytics-section-nav {
        display: flex;
        gap: 8px;
        max-width: 1800px;
        margin: 0 auto 24px;
        border-bottom: 1px solid #e2e8f0;
        overflow-x: auto;
    }
    .analytics-section-nav--soles { max-width: 1720px; }
    .analytics-section-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        padding: 11px 16px;
        margin-bottom: -1px;
        border-bottom: 2px solid transparent;
        border-radius: 8px 8px 0 0;
        color: #64748b;
        font-size: 14px;
        font-weight: 650;
        line-height: 1.5;
        text-decoration: none;
        white-space: nowrap;
    }
    .analytics-section-link:hover { color: #4f46e5; background: #f1f3fb; }
    .analytics-section-link.active { color: #4f46e5; border-bottom-color: #4f46e5; background: #eef0ff; }
    .analytics-section-link:focus-visible { outline: 3px solid #a5b4fc; outline-offset: 3px; }
    @media (max-width: 575.98px) {
        .analytics-section-nav { gap: 4px; margin-bottom: 20px; }
        .analytics-section-link { flex: 0 0 auto; padding: 10px 8px; font-size: 13px; }
    }
</style>
