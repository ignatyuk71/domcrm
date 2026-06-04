<x-app-layout>
    <div class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-warning-subtle text-warning-emphasis text-uppercase fw-bold mb-2">Інтеграції</span>
                <h1 class="h3 fw-bold mb-1">Незмаплені позиції</h1>
                <p class="text-secondary mb-0">Товари із замовлень сайтів, які не розпізналися по SKU. Зістав один раз — наступні підуть автоматично.</p>
            </div>
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>Налаштування інтеграцій</a>
        </div>

        @include('partials.flash-toasts')

        @forelse($items as $item)
            <div class="card border-0 shadow-sm rounded-4 mb-3 mapping-item" data-item-id="{{ $item->id }}">
                <div class="card-body p-4">
                    <div class="row g-3 align-items-start">
                        <div class="col-12 col-lg-5">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $item->order?->source?->name ?? 'Сайт' }}</span>
                                <span class="text-secondary small">Замовлення №{{ $item->order?->order_number }}</span>
                            </div>
                            <div class="fw-bold fs-6">{{ $item->product_title ?: '—' }}</div>
                            <div class="text-secondary small mt-1">
                                @if($item->sku)<span class="me-2">SKU сайту: <span class="font-monospace">{{ $item->sku }}</span></span>@endif
                                @if($item->size)<span class="me-2">Розмір: {{ $item->size }}</span>@endif
                                <span class="me-2">К-сть: {{ $item->qty }}</span>
                                <span>Ціна: {{ $item->price }}</span>
                            </div>
                        </div>

                        <div class="col-12 col-lg-7">
                            <form method="POST" action="{{ route('integrations.review.map') }}">
                                @csrf
                                <input type="hidden" name="order_item_id" value="{{ $item->id }}">
                                <input type="hidden" name="product_id" class="map-product-id">
                                <input type="hidden" name="product_variant_id" class="map-variant-id">

                                <label class="form-label small fw-semibold mb-1">Зіставити з товаром CRM</label>
                                <div class="position-relative">
                                    <input type="text" class="form-control form-control-sm map-search" autocomplete="off" placeholder="Пошук за назвою або SKU…">
                                    <div class="map-results list-group position-absolute w-100 shadow-sm" style="z-index:20; display:none; max-height:260px; overflow:auto;"></div>
                                </div>

                                <div class="map-chosen alert alert-success py-2 px-3 mt-2 mb-0 small" style="display:none;"></div>

                                <div class="d-flex align-items-center justify-content-between mt-2">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="remember" value="1" id="remember-{{ $item->id }}" checked>
                                        <label class="form-check-label small text-secondary" for="remember-{{ $item->id }}">Запам'ятати (далі авто)</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary map-submit" disabled>
                                        <i class="bi bi-check2 me-1"></i>Зіставити
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-5 text-center text-secondary">
                    <i class="bi bi-emoji-smile fs-1 d-block mb-2 opacity-50"></i>
                    Немає незмаплених позицій — усе зіставлено. 🎉
                </div>
            </div>
        @endforelse

        @if($items->hasPages())
            <div class="mt-3">{{ $items->links() }}</div>
        @endif
    </div>

    <script>
        (function () {
            function esc(s) {
                return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            }

            let timer = null;

            document.addEventListener('input', function (e) {
                if (!e.target.classList.contains('map-search')) return;
                const card = e.target.closest('.mapping-item');
                const results = card.querySelector('.map-results');
                const q = e.target.value.trim();

                clearTimeout(timer);
                if (q.length < 2) { results.style.display = 'none'; results.innerHTML = ''; return; }

                timer = setTimeout(function () {
                    fetch('/products?with_variants=1&per_page=10&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                        .then(r => r.json())
                        .then(data => {
                            const products = data.data || [];
                            results.innerHTML = '';
                            if (!products.length) {
                                results.innerHTML = '<div class="list-group-item small text-muted">Нічого не знайдено</div>';
                                results.style.display = 'block';
                                return;
                            }
                            products.forEach(function (p) {
                                const variants = p.variants || [];
                                if (variants.length) {
                                    variants.forEach(function (v) {
                                        const btn = document.createElement('button');
                                        btn.type = 'button';
                                        btn.className = 'list-group-item list-group-item-action map-pick';
                                        btn.dataset.productId = p.id;
                                        btn.dataset.variantId = v.id;
                                        btn.innerHTML = '<strong>' + esc(p.title) + '</strong> <span class="text-muted">' + esc(v.size || '') + '</span> <span class="badge bg-light text-dark border ms-1">' + esc(v.sku || '') + '</span>';
                                        results.appendChild(btn);
                                    });
                                } else {
                                    const btn = document.createElement('button');
                                    btn.type = 'button';
                                    btn.className = 'list-group-item list-group-item-action map-pick';
                                    btn.dataset.productId = p.id;
                                    btn.dataset.variantId = '';
                                    btn.innerHTML = '<strong>' + esc(p.title) + '</strong> <span class="badge bg-light text-dark border ms-1">' + esc(p.sku || '') + '</span> <span class="text-muted">(без варіантів)</span>';
                                    results.appendChild(btn);
                                }
                            });
                            results.style.display = 'block';
                        })
                        .catch(() => { results.style.display = 'none'; });
                }, 250);
            });

            document.addEventListener('click', function (e) {
                const pick = e.target.closest('.map-pick');
                if (pick) {
                    const card = pick.closest('.mapping-item');
                    card.querySelector('.map-product-id').value = pick.dataset.productId;
                    card.querySelector('.map-variant-id').value = pick.dataset.variantId || '';
                    const chosen = card.querySelector('.map-chosen');
                    chosen.innerHTML = '✓ ' + pick.innerHTML;
                    chosen.style.display = 'block';
                    card.querySelector('.map-results').style.display = 'none';
                    card.querySelector('.map-submit').disabled = false;
                    return;
                }
                if (!e.target.classList.contains('map-search')) {
                    document.querySelectorAll('.map-results').forEach(r => { r.style.display = 'none'; });
                }
            });
        })();
    </script>
</x-app-layout>
