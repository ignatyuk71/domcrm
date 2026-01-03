<x-app-layout>
    <style>
        /* Сучасні акценти */
        :root {
            --crm-card-radius: 1.25rem;
            --crm-primary: #0d6efd;
        }

        .dashboard-container {
            background-color: #f8fafc;
            min-height: 100vh;
        }

        /* Стиль карток статистики */
        .stat-card {
            border: 1px solid rgba(0, 0, 0, 0.03) !important;
            border-radius: var(--crm-card-radius);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05) !important;
        }

        /* Ефект скляної панелі для швидких дій */
        .action-panel {
            background: linear-gradient(135deg, #ffffff 0%, #f9f9ff 100%);
            border-radius: var(--crm-card-radius);
            border: 1px solid rgba(13, 110, 253, 0.1);
        }

        /* Кастомна таблиця */
        .custom-table thead th {
            background-color: #fcfcfd;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: #6c757d;
            border-top: none;
            padding: 1rem;
        }

        .custom-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f1f1f4;
        }

        /* Анімовані бейджі */
        .status-badge {
            padding: 0.5em 1em;
            font-weight: 500;
            letter-spacing: -0.01em;
            border: none;
        }

        /* Тіні для кнопок */
        .btn-shadow {
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }

        .chart-container {
            position: relative;
            margin: auto;
        }
    </style>

    <x-slot name="header">
        <div class="d-flex align-items-center justify-content-between py-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb small mb-1">
                        <li class="breadcrumb-item text-muted">DomCRM</li>
                        <li class="breadcrumb-item active" aria-current="page">Огляд</li>
                    </ol>
                </nav>
                <h2 class="h4 mb-0 fw-bold text-dark">Робочий стіл</h2>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div class="text-end d-none d-md-block">
                    <div class="small fw-bold text-dark">{{ now()->translatedFormat('l, d F') }}</div>
                    <div class="text-muted extra-small" style="font-size: 0.8rem;">Вітаємо, {{ Auth::user()->name }}!</div>
                </div>
                <div class="vr mx-2 h-100"></div>
                <button class="btn btn-white border rounded-circle p-2 position-relative">
                    <i class="bi bi-bell text-secondary"></i>
                    <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-4 dashboard-container">
        <div class="container">

            {{-- Статистика --}}
            <div class="row g-4 mb-4">
                @php
                    $stats = [
                        ['label' => 'Нові замовлення', 'value' => '12', 'trend' => '+2 за годину', 'color' => 'primary', 'icon' => 'bi-plus-circle'],
                        ['label' => 'В роботі', 'value' => '45', 'trend' => '80% готовності', 'color' => 'warning', 'icon' => 'bi-gear-wide-connected'],
                        ['label' => 'Очікують пакування', 'value' => '8', 'trend' => 'Терміново: 3', 'color' => 'info', 'icon' => 'bi-box-seam'],
                        ['label' => 'Відправлено', 'value' => '24', 'trend' => 'На 15% більше', 'color' => 'success', 'icon' => 'bi-truck'],
                    ];
                @endphp

                @foreach($stats as $s)
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="icon-box rounded-3 bg-{{ $s['color'] }} bg-opacity-10 text-{{ $s['color'] }} p-2">
                                        <i class="bi {{ $s['icon'] }} fs-4"></i>
                                    </div>
                                    <span class="small text-{{ $s['color'] }} fw-medium">{{ $s['trend'] }}</span>
                                </div>
                                <h6 class="text-muted small fw-bold mb-1">{{ $s['label'] }}</h6>
                                <h3 class="fw-bold mb-0 text-dark">{{ $s['value'] }}</h3>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="row g-4 mb-4">
                {{-- Графік --}}
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm h-100" style="border-radius: var(--crm-card-radius);">
                        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                            <h3 class="h6 fw-bold mb-0">Аналітика замовлень</h3>
                            <select class="form-select form-select-sm w-auto border-0 bg-light fw-medium">
                                <option>Останні 7 днів</option>
                                <option>Останні 30 днів</option>
                            </select>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" style="height: 320px;">
                                <canvas id="salesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Швидкі дії --}}
                <div class="col-lg-4">
                    <div class="card action-panel border-0 shadow-sm h-100">
                        <div class="card-body p-4 text-center">
                            <div class="mb-4">
                                <div class="bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 70px; height: 70px;">
                                    <i class="bi bi-lightning-charge-fill fs-2"></i>
                                </div>
                                <h4 class="fw-bold">Швидкий доступ</h4>
                                <p class="text-muted small">Керуйте основними процесами в один клік</p>
                            </div>
                            
                            <div class="d-grid gap-3">
                                <a href="{{ route('orders.create') }}" class="btn btn-primary btn-lg btn-shadow fw-bold py-3 rounded-3">
                                    <i class="bi bi-plus-lg me-2"></i>Нове замовлення
                                </a>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <a href="#" class="btn btn-white border w-100 py-3 rounded-3 hover-shadow transition-all">
                                            <i class="bi bi-box d-block fs-4 mb-1"></i> Пакування
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="#" class="btn btn-white border w-100 py-3 rounded-3 hover-shadow transition-all">
                                            <i class="bi bi-people d-block fs-4 mb-1"></i> Клієнти
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Таблиця останніх замовлень --}}
            <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: var(--crm-card-radius);">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="h6 fw-bold mb-0">Останні операції</h3>
                        <p class="text-muted extra-small mb-0">Список останніх 5 замовлень у системі</p>
                    </div>
                    <a href="#" class="btn btn-sm btn-light rounded-pill px-3 fw-bold">Дивитись всі</a>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Клієнт</th>
                                <th>Дата</th>
                                <th>Статус</th>
                                <th>Сума</th>
                                <th class="text-end">Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach([
                                ['id' => '10234', 'date' => 'Сьогодні, 14:30', 'name' => 'Олена Петрівна', 'status' => 'Новий', 'color' => 'primary', 'total' => '1 250 ₴'],
                                ['id' => '10233', 'date' => 'Сьогодні, 12:15', 'name' => 'Ігор Коваленко', 'status' => 'В роботі', 'color' => 'warning', 'total' => '3 400 ₴'],
                                ['id' => '10232', 'date' => 'Вчора, 18:45', 'name' => 'Марія Сидоренко', 'status' => 'Відправлено', 'color' => 'info', 'total' => '890 ₴'],
                                ['id' => '10231', 'date' => 'Вчора, 16:20', 'name' => 'ТОВ "БудМайстер"', 'status' => 'Виконано', 'color' => 'success', 'total' => '12 500 ₴'],
                            ] as $order)
                                <tr>
                                    <td><span class="fw-bold text-dark">#{{ $order['id'] }}</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-secondary fw-bold small me-2" style="width: 32px; height: 32px;">
                                                {{ substr($order['name'], 0, 1) }}
                                            </div>
                                            <span class="fw-medium">{{ $order['name'] }}</span>
                                        </div>
                                    </td>
                                    <td class="text-muted small">{{ $order['date'] }}</td>
                                    <td>
                                        <span class="badge status-badge bg-{{ $order['color'] }} bg-opacity-10 text-{{ $order['color'] }} rounded-pill">
                                            {{ $order['status'] }}
                                        </span>
                                    </td>
                                    <td><span class="fw-bold text-dark">{{ $order['total'] }}</span></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border-0 rounded-circle p-2">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('salesChart');
            if (ctx) {
                const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, 'rgba(13, 110, 253, 0.15)');
                gradient.addColorStop(1, 'rgba(13, 110, 253, 0)');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'],
                        datasets: [{
                            data: [12500, 19000, 15000, 22000, 18000, 30000, 25000],
                            borderColor: '#0d6efd',
                            borderWidth: 3,
                            backgroundColor: gradient,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#0d6efd',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { 
                                grid: { borderDash: [5, 5], color: '#eef0f2', drawBorder: false },
                                ticks: { color: '#94a3b8', font: { size: 11 } }
                            },
                            x: { 
                                grid: { display: false, drawBorder: false },
                                ticks: { color: '#94a3b8', font: { size: 11 } }
                            }
                        }
                    }
                });
            }
        });
    </script>
</x-app-layout>