<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DomCRM - Система управління бізнесом</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #f53003; /* Твій фірмовий колір */
            --primary-hover: #d42902;
            --primary-light: rgba(245, 48, 3, 0.1);
            --light-bg: #f8f9fa;
            --dark-text: #2d3436;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            color: var(--dark-text);
            overflow-x: hidden;
        }

        /* Навігація */
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        /* --- КНОПКИ --- */
        .btn-primary-custom {
            background: var(--primary-color);
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
            color: white;
            padding: 0.8rem 2.5rem;
            border-radius: 50px;
            font-weight: 600;
            border: none;
            box-shadow: 0 4px 15px rgba(245, 48, 3, 0.3);
            transition: all 0.3s ease;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(245, 48, 3, 0.4);
            color: white;
        }

        .btn-outline-custom {
            color: var(--primary-color);
            background-color: transparent;
            border: 2px solid var(--primary-color);
            padding: 0.8rem 2.5rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-outline-custom:hover {
            background-color: var(--primary-light);
            color: var(--primary-hover);
            transform: translateY(-3px);
        }

        /* --- ГЕРОЙ-СЕКЦІЯ --- */
        .hero-section {
            padding: 100px 0;
            position: relative;
            background: radial-gradient(circle at 70% 30%, rgba(245, 48, 3, 0.05) 0%, rgba(255, 255, 255, 0) 70%);
        }

        /* Контейнер для графічного банера */
        .hero-graphic-container {
            position: relative;
            perspective: 1000px;
        }

        /* Декоративний фон за банером */
        .hero-blob {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-10deg);
            width: 120%;
            height: 120%;
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(255,255,255,0) 80%);
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            z-index: -1;
            filter: blur(40px);
        }

        /* Стилізований макет інтерфейсу */
        .hero-mockup {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08), 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
            overflow: hidden;
            transform: rotateY(-10deg) rotateX(5deg);
            transition: transform 0.5s ease;
            animation: float 6s ease-in-out infinite;
        }
        
        .hero-mockup:hover {
             transform: rotateY(0deg) rotateX(0deg);
        }

        /* Верхня панель макету */
        .mockup-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            gap: 8px;
        }
        .mockup-dot { width: 12px; height: 12px; border-radius: 50%; background: #e2e6ea;}
        .mockup-dot.red { background: #ff5f57; }
        .mockup-dot.yellow { background: #ffbd2e; }
        .mockup-dot.green { background: #28c940; }

        /* Вміст макету */
        .mockup-body {
            padding: 20px;
            min-height: 300px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .skeleton-block { background: #f1f3f5; border-radius: 8px; }
        .skeleton-chart { height: 150px; background: linear-gradient(180deg, var(--primary-light) 0%, rgba(255,255,255,0) 100%); border-bottom: 2px solid var(--primary-light); position: relative;}
        .skeleton-row { height: 25px; width: 100%; }
        .skeleton-row-short { height: 25px; width: 60%; }

        @keyframes float {
            0% { transform: translateY(0px) rotateY(-10deg) rotateX(5deg); }
            50% { transform: translateY(-25px) rotateY(-10deg) rotateX(5deg); }
            100% { transform: translateY(0px) rotateY(-10deg) rotateX(5deg); }
        }

        /* Картки переваг */
        .feature-card {
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 20px;
            padding: 2.5rem;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            border-color: var(--primary-light);
        }

        .icon-circle {
            width: 64px;
            height: 64px;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .feature-card:hover .icon-circle {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-lg py-3 sticky-top bg-white/80 backdrop-blur border-bottom" style="backdrop-filter: blur(10px); background-color: rgba(255,255,255,0.9);">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <div class="bg-danger text-white rounded p-2 me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                     <i class="bi bi-rocket-takeoff-fill" style="font-size: 1.2rem;"></i>
                </div>
                DomCRM
            </a>
            
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="ms-auto d-flex gap-2 align-items-center">
                    @if (Route::has('login'))
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-dark rounded-pill px-4 fw-semibold">Панель керування</a>
                        @else
                            <a href="{{ route('login') }}" class="btn text-dark fw-semibold me-2">Увійти</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn btn-primary-custom">Реєстрація</a>
                            @endif
                        @endauth
                    @endif
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1">
        <div class="hero-section overflow-hidden">
            <div class="container">
                <div class="row align-items-center gy-5">
                    <div class="col-lg-6 py-5">
                        
                        <h1 class="display-3 fw-extrabold mb-4 lh-tight" style="color: #1a1a1a;">
                            Керуйте бізнесом <br>
                            <span style="background: linear-gradient(to right, var(--primary-color), var(--primary-hover)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">просто та ефективно</span>
                        </h1>
                        <p class="lead text-secondary mb-5 pe-lg-5" style="font-size: 1.25rem;">
                            DomCRM — це ідеальне рішення для обліку замовлень, контролю складу та роботи з клієнтами. Забудьте про Excel, перейдіть на новий рівень автоматизації.
                        </p>
                        
                        <div class="d-flex flex-wrap gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="btn btn-primary-custom btn-lg">Перейти до Dashboard</a>
                            @else
                                <a href="{{ route('register') }}" class="btn btn-primary-custom btn-lg">Почати роботу (Безкоштовно)</a>
                                <a href="#features" class="btn btn-outline-custom btn-lg">Дізнатись більше</a>
                            @endauth
                        </div>
                        <div class="mt-4 text-muted small">
                            <i class="bi bi-check-circle-fill text-success me-1"></i> 14 днів пробного періоду. Кредитна карта не потрібна.
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="hero-graphic-container p-4">
                            <div class="hero-blob"></div>
                            
                            <div class="hero-mockup">
                                <div class="mockup-header">
                                    <div class="mockup-dot red"></div>
                                    <div class="mockup-dot yellow"></div>
                                    <div class="mockup-dot green"></div>
                                </div>
                                <div class="mockup-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="skeleton-block" style="width: 150px; height: 30px;"></div>
                                        <div class="skeleton-block" style="width: 100px; height: 30px; background-color: var(--primary-light);"></div>
                                    </div>
                                    <div class="row g-3 mb-2">
                                        <div class="col-4">
                                            <div class="skeleton-block p-3" style="height: 80px;"></div>
                                        </div>
                                        <div class="col-4">
                                            <div class="skeleton-block p-3" style="height: 80px;"></div>
                                        </div>
                                        <div class="col-4">
                                            <div class="skeleton-block p-3" style="height: 80px; background-color: var(--primary-light);"></div>
                                        </div>
                                    </div>
                                    <div class="skeleton-chart d-flex align-items-end p-2 gap-2 text-center justify-content-center">
                                         <span class="badge bg-danger mb-4 shadow animate-pulse">🔥 Продажі ростуть!</span>
                                    </div>
                                    <div class="skeleton-row skeleton-block"></div>
                                    <div class="skeleton-row-short skeleton-block"></div>
                                </div>
                            </div>
                            </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="features" class="py-5" style="background-color: #f8f9fa;">
            <div class="container py-5">
                <div class="text-center mb-5 mx-auto" style="max-width: 700px;">
                    <h6 class="text-danger fw-bold text-uppercase ls-2">Чому ми?</h6>
                    <h2 class="fw-bold display-5 mb-3">Можливості DomCRM</h2>
                    <p class="text-muted lead">Ми зібрали все необхідне для вашого магазину в одному місці, щоб ви фокусувались на рості.</p>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="icon-circle">
                                <i class="bi bi-cart-check-fill"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Обробка замовлень</h4>
                            <p class="text-muted mb-0">
                                Швидке створення замовлень, зміна статусів в один клік, відстеження оплат та інтеграція з службами доставки.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="icon-circle">
                                <i class="bi bi-box-seam-fill"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Складський облік</h4>
                            <p class="text-muted mb-0">
                                Повний контроль залишків у реальному часі. Прихід товарів, автоматичне списання та інвентаризація.
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
                                Дивіться, скільки ви реально заробляєте. Зручні графіки продажів, P&L звіти та статистика по менеджерам.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-white py-5 border-top mt-auto">
        <div class="container">
            <div class="row gy-4 justify-content-between">
                <div class="col-lg-4">
                     <a class="navbar-brand d-flex align-items-center mb-3" href="#">
                        <div class="bg-danger text-white rounded p-2 me-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                             <i class="bi bi-rocket-takeoff-fill" style="font-size: 1rem;"></i>
                        </div>
                        DomCRM
                    </a>
                    <p class="text-muted small mb-4">Сучасна система для автоматизації вашого бізнесу. Зроблено з увагою до деталей.</p>
                </div>
                <div class="col-lg-2 col-6">
                    <h6 class="fw-bold mb-3">Продукт</h6>
                    <ul class="list-unstyled text-muted small flex-column d-flex gap-2">
                        <li><a href="#" class="text-reset text-decoration-none">Можливості</a></li>
                        <li><a href="#" class="text-reset text-decoration-none">Ціни</a></li>
                        <li><a href="#" class="text-reset text-decoration-none">API</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-6">
                     <h6 class="fw-bold mb-3">Компанія</h6>
                    <ul class="list-unstyled text-muted small flex-column d-flex gap-2">
                        <li><a href="#" class="text-reset text-decoration-none">Про нас</a></li>
                        <li><a href="#" class="text-reset text-decoration-none">Контакти</a></li>
                        <li><a href="#" class="text-reset text-decoration-none">Блог</a></li>
                        <li><a href="{{ route('privacy') }}" class="text-reset text-decoration-none">Політика конфіденційності</a></li>
                        <li><a href="{{ route('terms-of-use') }}" class="text-reset text-decoration-none">Умови користування</a></li>
                    </ul>
                </div>
                 <div class="col-lg-3">
                    <h6 class="fw-bold mb-3">Підтримка</h6>
                    <p class="text-muted small mb-2">Потрібна допомога? Пишіть нам:</p>
                    <a href="mailto:support@domcrm.com" class="fw-bold text-dark text-decoration-none">support@domcrm.com</a>
                </div>
            </div>
            <div class="border-top mt-5 pt-4 text-center text-muted small">
                <p class="mb-0">
                    &copy; {{ date('Y') }} <strong>DomCRM</strong>. Всі права захищено.
                </p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
