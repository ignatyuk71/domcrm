<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DomCRM - Система управління бізнесом</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #f53003; /* Твій фірмовий колір */
            --primary-hover: #d42902;
            --light-bg: #f8f9fa;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            color: #2d3436;
        }

        /* Навігація */
        .navbar-brand {
            font-size: 1.5rem;
            letter-spacing: -0.5px;
        }

        /* Герой-секція (верхня частина) */
        .hero-section {
            padding: 80px 0;
            background: radial-gradient(circle at 80% 50%, rgba(245, 48, 3, 0.08) 0%, rgba(255, 255, 255, 0) 70%);
        }

        /* Картки переваг */
        .feature-card {
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 16px;
            padding: 2rem;
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-color: rgba(245, 48, 3, 0.2);
        }

        .icon-circle {
            width: 60px;
            height: 60px;
            background-color: rgba(245, 48, 3, 0.1);
            color: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Кнопки */
        .btn-primary-custom {
            background-color: var(--primary-color);
            color: white;
            padding: 0.8rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary-custom:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 48, 3, 0.3);
            color: white;
        }

        /* Анімація головної картинки */
        .hero-img {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
            100% { transform: translateY(0px); }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-lg py-3 sticky-top bg-white border-bottom">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="#">
                <i class="bi bi-rocket-takeoff-fill text-danger me-2"></i>
                DomCRM
            </a>
            
            <div class="ms-auto d-flex gap-2">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="btn btn-dark rounded-pill px-4">Панель керування</a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-outline-dark border-0 rounded-pill fw-medium">Увійти</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-primary-custom">Реєстрація</a>
                        @endif
                    @endauth
                @endif
            </div>
        </div>
    </nav>

    <main class="flex-grow-1">
        <div class="hero-section">
            <div class="container">
                <div class="row align-items-center gy-5">
                    <div class="col-lg-6">
                        <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill mb-3">CRM система v1.0</span>
                        <h1 class="display-4 fw-bold mb-4 text-dark">
                            Керуйте бізнесом <br>
                            <span style="color: var(--primary-color);">просто та ефективно</span>
                        </h1>
                        <p class="lead text-secondary mb-5">
                            DomCRM — це ідеальне рішення для обліку замовлень, контролю складу та роботи з клієнтами. Забудьте про Excel, перейдіть на новий рівень.
                        </p>
                        
                        <div class="d-flex gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="btn btn-primary-custom btn-lg">Перейти до Dashboard</a>
                            @else
                                <a href="{{ route('login') }}" class="btn btn-primary-custom btn-lg">Почати роботу</a>
                                <a href="#features" class="btn btn-outline-secondary btn-lg rounded-pill px-4 border-2">Дізнатись більше</a>
                            @endauth
                        </div>
                    </div>
                    
                    <div class="col-lg-6 text-center">
                        <div class="hero-img">
                            <i class="bi bi-laptop" style="font-size: 15rem; color: #2d3436;"></i>
                            <div class="position-absolute top-50 start-50 translate-middle" style="z-index: -1; width: 300px; height: 300px; background: rgba(245, 48, 3, 0.1); filter: blur(60px); border-radius: 50%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="features" class="py-5 bg-light">
            <div class="container py-4">
                <div class="text-center mb-5">
                    <h2 class="fw-bold">Можливості DomCRM</h2>
                    <p class="text-muted">Все необхідне для вашого магазину в одному місці</p>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="icon-circle">
                                <i class="bi bi-cart-check"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Обробка замовлень</h4>
                            <p class="text-muted mb-0">
                                Швидке створення замовлень, зміна статусів, відстеження оплат та інтеграція з доставкою.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="icon-circle">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Складський облік</h4>
                            <p class="text-muted mb-0">
                                Контроль залишків, прихід товарів, автоматичне списання та інвентаризація в пару кліків.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="icon-circle">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Аналітика та Звіти</h4>
                            <p class="text-muted mb-0">
                                Дивіться, скільки ви заробляєте. Зручні графіки продажів та статистика по менеджерам.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-white py-4 border-top mt-auto">
        <div class="container text-center">
            <p class="mb-0 text-muted small">
                &copy; {{ date('Y') }} <strong>DomCRM</strong>. Всі права захищено.
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>