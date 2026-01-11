<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>DomMood • Складська черга</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"/>

    <style>
        :root {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --accent: #ffb020;
            --accent-ink: #1f2937;
            --ink: #1f2937;
            --muted: #6b7280;
            --urgent: #ef4444;
            --radius: 20px;
        }

        body {
            background-color: var(--bg);
            color: var(--ink);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            padding-bottom: 50px;
        }

        /* Header */
        .top-nav {
            background: var(--card-bg);
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .brand-logo {
            font-weight: 800;
            font-size: 1.4rem;
            color: var(--ink);
            text-decoration: none;
        }

        .brand-logo span { color: var(--accent); }

        /* Order Card */
        .order-bento-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
            cursor: pointer;
        }

        .order-bento-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
        }

        /* Urgent Indicator */
        .order-bento-card.priority::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 6px;
            background: var(--urgent);
        }

        .card-header-clean {
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .order-number {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 0.2rem;
        }

        .carrier-tag {
            background: #f3f4f6;
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .np-brand { color: #ef4444; }

        .items-preview {
            padding: 0 1.5rem 1.5rem;
        }

        .item-row {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f9fafb;
            padding: 10px;
            border-radius: 14px;
            margin-bottom: 8px;
        }

        .item-img {
            width: 45px; height: 45px;
            background: #eee;
            border-radius: 10px;
            object-fit: cover;
        }

        .item-qty {
            background: var(--ink);
            color: #fff;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 700;
        }

        .card-footer-actions {
            padding: 1.2rem 1.5rem;
            background: #fafafa;
            border-top: 1px solid #f1f1f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-pack {
            background: var(--accent);
            color: var(--accent-ink);
            border: none;
            padding: 10px 24px;
            border-radius: 14px;
            font-weight: 700;
            transition: 0.2s;
        }

        .btn-pack:hover {
            background: var(--ink);
            color: white;
        }

        .urgent-badge {
            background: #fee2e2;
            color: #b91c1c;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<header class="top-nav shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="brand-logo">DomMood <span>WMS</span></a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small fw-bold"><i class="bi bi-person-circle"></i> Склад №1</span>
            <button class="btn btn-dark btn-sm rounded-pill px-3">Зміна: 8 год</button>
        </div>
    </div>
</header>

<main class="container">
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="fw-bold m-0">Черга на пакування</h2>
            <p class="text-muted">Знайдено замовлень: {{ $orders->count() }}</p>
        </div>
        <div class="col-md-6 text-md-end">
            <div class="btn-group shadow-sm rounded-4 overflow-hidden">
                <button class="btn btn-white border bg-white active px-4">Всі</button>
                <button class="btn btn-white border bg-white px-4">🔥 Термінові</button>
            </div>
        </div>
    </div>

    <div class="row" id="packingQueue">
        @forelse ($orders as $order)
            <div class="col-lg-4 col-md-6">
                <div class="order-bento-card {{ $order->is_priority ? 'priority' : '' }}" onclick="startPacking({{ $order->id }})">
                    <div class="card-header-clean">
                        <div>
                            <div class="order-number">#{{ $order->order_number }}</div>
                            <div class="text-muted small fw-bold">
                                {{ $order->created_at?->diffForHumans() ?? 'Щойно' }} • {{ $order->delivery->city_name ?? 'Місто не вказано' }}
                            </div>
                        </div>
                        @if($order->is_priority)
                            <span class="urgent-badge">🔥 Пріоритет</span>
                        @endif
                    </div>

                    <div class="items-preview">
                        @foreach ($order->items->take(3) as $item)
                            <div class="item-row">
                                <div class="item-img"></div>
                                <div class="flex-grow-1">
                                    <div class="small fw-bold">{{ $item->product_name ?? $item->name ?? 'Товар' }}</div>
                                    <div class="text-muted" style="font-size: 0.8rem;">{{ $item->variant ?? '—' }}</div>
                                </div>
                                <div class="item-qty">x{{ $item->qty ?? 1 }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="card-footer-actions">
                        <div class="carrier-tag">
                            <i class="bi bi-box-seam np-brand"></i> Нова Пошта
                        </div>
                        <button class="btn-pack">
                            Пакувати <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-light text-center shadow-sm">Замовлень для пакування немає.</div>
            </div>
        @endforelse
    </div>
</main>

<script>
    async function startPacking(orderId) {
        try {
            const response = await fetch(`/packing/${orderId}/start`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();

            if (response.ok) {
                window.location.href = `/packing/${orderId}`;
            } else {
                alert('Помилка: ' + data.error);
            }
        } catch (error) {
            console.error('Помилка запиту:', error);
            alert('Не вдалося з’єднатися з сервером');
        }
    }
</script>

</body>
</html>
