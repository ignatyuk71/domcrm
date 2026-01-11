<x-app-layout>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --font-main: 'Plus Jakarta Sans', sans-serif;
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --glass-bg: rgba(255, 255, 255, 0.9);
            --glass-border: 1px solid rgba(255, 255, 255, 0.5);
            --card-shadow: 0 10px 40px -10px rgba(0,0,0,0.08);
            --hover-transform: translateY(-5px);
        }

        body {
            font-family: var(--font-main) !important;
            background-color: #f3f4f6; /* Світло-сірий преміум фон */
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(168, 85, 247, 0.1) 0px, transparent 50%);
            background-attachment: fixed;
        }

        /* --- UI Components --- */
        
        .dashboard-header {
            padding-bottom: 2rem;
        }

        .premium-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: var(--glass-border);
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow: hidden;
            position: relative;
        }

        .premium-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px -12px rgba(79, 70, 229, 0.15);
            border-color: rgba(99, 102, 241, 0.3);
        }

        /* Stat Cards Decor elements */
        .stat-icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: transform 0.3s ease;
        }

        .premium-card:hover .stat-icon-wrapper {
            transform: scale(1.1) rotate(5deg);
        }

        /* Dark Action Panel */
        .action-card-dark {
            background: #111827; /* Deep dark */
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        /* Декоративні кола на темній картці */
        .action-card-dark::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            filter: blur(60px);
            opacity: 0.6;
            border-radius: 50%;
        }

        .btn-glow {
            background: var(--primary-gradient);
            border: none;
            color: white;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4);
            transition: all 0.3s ease;
        }

        .btn-glow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.5);
            color: white;
        }

        .btn-glass {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            backdrop-filter: blur(5px);
        }
        .btn-glass:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* Modern Table */
        .modern-table thead th {
            background: transparent;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            font-weight: 700;
            padding: 1.25rem 1.5rem;
            border: none;
        }

        .modern-table tbody td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            font-weight: 500;
            color: #1f2937;
        }

        .modern-table tr:last-child td {
            border-bottom: none;
        }
        
        .modern-table tr {
            transition: background-color 0.2s;
        }
        .modern-table tr:hover {
            background-color: #f9fafb;
        }

        /* Status Badges */
        .status-pill {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-pill::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: currentColor;
        }
        
        /* Custom Colors for Badges */
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-info    { background: #e0f2fe; color: #075985; }
        .badge-primary { background: #e0e7ff; color: #3730a3; }

        /* Chart Tooltip customization */
        #salesChart {
            filter: drop-shadow(0 10px 20px rgba(99, 102, 241, 0.15));
        }
    </style>



    <div class="py-4 px-3 px-md-4">
        <div class="container-fluid" style="max-width: 1400px;">

            {{-- --- STATS GRID --- --}}
            <div class="row g-4 mb-5">
                @foreach($stats as $s)
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="premium-card h-100 p-4 d-flex align-items-center justify-content-between" style="background: {{ $s['bg'] }}">
                            <div>
                                <h6 class="text-secondary text-uppercase fw-bold mb-2" style="font-size: 0.7rem; letter-spacing: 1px;">{{ $s['label'] }}</h6>
                                <h2 class="fw-black text-dark mb-1" style="font-weight: 800;">{{ $s['value'] }}</h2>
                                <span class="badge bg-white text-secondary border shadow-sm fw-medium rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                                    {{ $s['sub'] }}
                                </span>
                            </div>
                            <div class="stat-icon-wrapper shadow-sm" style="background-color: {{ $s['icon_bg'] }}; color: {{ $s['icon_color'] }};">
                                <i class="bi {{ $s['icon'] }}"></i>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="row g-4 mb-5">
                {{-- --- MAIN CHART --- --}}
                <div class="col-lg-8">
                    <div class="premium-card h-100 p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h4 class="fw-bold text-dark mb-1">Динаміка продажів</h4>
                                <p class="text-muted small mb-0">Статистика доходів за обраний період</p>
                            </div>
                            <select class="form-select form-select-sm border-0 bg-light fw-bold text-secondary w-auto rounded-pill px-3 shadow-sm cursor-pointer">
                                <option>Цей тиждень</option>
                                <option>Цей місяць</option>
                            </select>
                        </div>
                        <div style="height: 350px; width: 100%;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>

                {{-- --- ACTION PANEL (DARK MODE BLOCK) --- --}}
                <div class="col-lg-4">
                    <div class="premium-card action-card-dark h-100 p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center gap-3 mb-4">
                                <div class="bg-white bg-opacity-10 p-3 rounded-circle text-white">
                                    <i class="bi bi-lightning-charge-fill fs-4"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0">Центр дій</h5>
                                    <small class="text-white-50">Швидке керування CRM</small>
                                </div>
                            </div>
                            
                            <p class="text-white-50 small mb-4">Створюйте замовлення, перевіряйте статус складських залишків або додавайте нових клієнтів в один клік.</p>
                        </div>

                        <div class="d-grid gap-3 position-relative" style="z-index: 2;">
                            <a href="{{ route('orders.create') }}" class="btn btn-glow rounded-3 py-3 fw-bold d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-plus-lg"></i> Створити замовлення
                            </a>
                            <div class="row g-2">
                                <div class="col-6">
                                    <a href="#" class="btn btn-glass w-100 py-3 rounded-3 fw-medium small">
                                        <i class="bi bi-box-seam d-block fs-5 mb-1"></i> Склад
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="#" class="btn btn-glass w-100 py-3 rounded-3 fw-medium small">
                                        <i class="bi bi-people d-block fs-5 mb-1"></i> Клієнти
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- --- MODERN TABLE --- --}}
            <div class="premium-card">
                <div class="px-4 py-4 border-bottom border-light d-flex justify-content-between align-items-center bg-white bg-opacity-50">
                    <div>
                        <h5 class="fw-bold mb-0">Останні транзакції</h5>
                        <p class="text-muted extra-small mb-0">Моніторинг останніх 5 замовлень</p>
                    </div>
                    <button class="btn btn-light btn-sm rounded-pill px-3 fw-bold text-secondary shadow-sm">
                        Всі замовлення <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table modern-table mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Замовлення</th>
                                <th>Клієнт</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th class="text-end pe-4">Сума</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $badgeMap = [
                                    'new' => 'badge-primary',
                                    'processing' => 'badge-warning',
                                    'packed' => 'badge-info',
                                    'shipped' => 'badge-info',
                                    'completed' => 'badge-success',
                                    'done' => 'badge-success',
                                ];
                            @endphp

                            @foreach($recentOrders as $order)
                                @php
                                    $customerName = $order->customer?->full_name ?: trim(($order->customer?->first_name ?? '') . ' ' . ($order->customer?->last_name ?? ''));
                                    $customerName = $customerName !== '' ? $customerName : 'Клієнт';
                                    $statusName = $order->statusRef?->name ?? ($order->status ?? '—');
                                    $statusCode = $order->statusRef?->code ?? ($order->status ?? 'default');
                                    $badgeClass = $badgeMap[$statusCode] ?? 'badge-primary';
                                    $total = number_format((float) ($order->items_sum_total ?? 0), 0, '.', ' ') . ' ₴';
                                    $dateLabel = $order->created_at ? $order->created_at->format('d.m H:i') : '—';
                                    $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($customerName) . '&background=6366f1&color=fff';
                                @endphp
                                <tr>
                                    <td class="ps-4">
                                        <span class="fw-bold text-dark">#{{ $order->order_number ?? $order->id }}</span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="{{ $avatarUrl }}" class="rounded-circle shadow-sm" width="36" height="36" alt="">
                                            <div>
                                                <div class="fw-bold text-dark small">{{ $customerName }}</div>
                                                <div class="text-muted" style="font-size: 0.75rem;">Постійний клієнт</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-pill {{ $badgeClass }}">
                                            {{ $statusName }}
                                        </span>
                                    </td>
                                    <td class="text-muted small">{{ $dateLabel }}</td>
                                    <td class="text-end pe-4">
                                        <span class="fw-black text-dark">{{ $total }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    {{-- Script for Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('salesChart');
            if (!ctx) return;

            // Створення красивого градієнта для графіку
            const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(99, 102, 241, 0.4)'); // Indigo
            gradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

            // Налаштування шрифтів
            Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
            Chart.defaults.color = '#94a3b8';

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: @json($chartLabels),
                    datasets: [{
                        label: 'Дохід (₴)',
                        data: @json($chartValues),
                        borderColor: '#6366f1', // Яскравий індиго
                        borderWidth: 4,
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.4, // Плавні криві (Безьє)
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#6366f1',
                        pointBorderWidth: 3,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: '#6366f1',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 12,
                            titleFont: { size: 13 },
                            bodyFont: { size: 14, weight: 'bold' },
                            cornerRadius: 8,
                            displayColors: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f1f5f9',
                                borderDash: [5, 5],
                                drawBorder: false
                            },
                            ticks: {
                                padding: 10,
                                font: { size: 11, weight: 500 }
                            }
                        },
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: {
                                padding: 10,
                                font: { size: 11, weight: 600 }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index',
                    },
                }
            });
        });
    </script>
</x-app-layout>
