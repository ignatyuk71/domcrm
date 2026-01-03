<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'DomCRM') }}</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #f53003;
            --dark-bg: #0f1111;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            color: #2d3436;
            overflow-x: hidden;
        }

        /* Градієнтний фон на задньому плані */
        .bg-gradient-soft {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 50%, rgba(245, 48, 3, 0.05) 0%, rgba(255, 255, 255, 0) 100%);
            z-index: -1;
        }

        .navbar-brand {
            font-size: 1.5rem;
            letter-spacing: -1px;
        }

        /* Стиль карток */
        .feature-card {
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 1rem 3rem rgba(0,0,0,0.08) !important;
            border-color: var(--primary-color);
        }

        /* Анімація логотипу */
        .logo-container {
            position: relative;
            padding: 3rem;
            background: white;
            border-radius: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.03);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.6rem 1.5rem;
            font-weight: 600;
        }

        .btn-primary:hover {
            background-color: #d42902;
            border-color: #d42902;
        }

        .text-primary-custom {
            color: var(--primary-color);
        }

        .icon-box {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: rgba(245, 48, 3, 0.1);
            color: var(--primary-color);
        }

        footer {
            margin-top: auto;
            border-top: 1px solid rgba(0,0,0,0.05);
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <div class="bg-gradient-soft"></div>

    <nav class="navbar navbar-expand-lg py-4">
        <div class="container">
            <a class="navbar-brand fw-bold text-dark d-flex align-items-center" href="#">
                <span class="text-primary-custom me-2"><i class="bi bi-rocket-takeoff-fill"></i></span>
                DomCRM
            </a>
            
            <div class="ms-auto d-flex gap-2">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="btn btn-outline-dark border-0 fw-semibold">Панель керування</a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-link text-dark text-decoration-none fw-semibold">Увійти</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-dark px-4 rounded-pill shadow-sm">Почати</a>
                        @endif
                    @endauth
                @endif
            </div>
        </div>
    </nav>

    <main class="flex-grow-1 d-flex align-items-center">
        <div class="container">
            <div class="row align-items-center g-5">
                
                <div class="col-lg-6 order-2 order-lg-1">
                    <div class="pe-lg-5">
                        <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill mb-3">Екосистема v{{ Illuminate\Foundation\Application::VERSION }}</span>
                        <h1 class="display-3 fw-bold mb-4">Створюйте щось <br><span class="text-primary-custom">дивовижне.</span></h1>
                        <p class="lead text-secondary mb-5">
                            Ласкаво просимо до вашого нового застосунку на Laravel. DomCRM забезпечує надійну основу для вашої бізнес-логіки. Почніть вивчати ресурси нижче.
                        </p>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <a href="https://laravel.com/docs" target="_blank" class="card feature-card h-100 text-decoration-none shadow-sm">
                                    <div class="card-body p-4">
                                        <div class="icon-box mb-3">
                                            <i class="bi bi-journal-text fs-4"></i>
                                        </div>
                                        <h5 class="fw-bold text-dark mb-1">Документація</h5>
                                        <p class="small text-muted mb-0">Все, що вам потрібно для освоєння фреймворку.</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-sm-6">
                                <a href="https://laracasts.com" target="_blank" class="card feature-card h-100 text-decoration-none shadow-sm">
                                    <div class="card-body p-4">
                                        <div class="icon-box mb-3" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                            <i class="bi bi-play-circle-fill fs-4"></i>
                                        </div>
                                        <h5 class="fw-bold text-dark mb-1">Laracasts</h5>
                                        <p class="small text-muted mb-0">Професійні відеоуроки для розробників.</p>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <div class="mt-5 d-flex align-items-center gap-3">
                            <a href="https://cloud.laravel.com" class="btn btn-primary btn-lg rounded-3 shadow">
                                <i class="bi bi-cloud-arrow-up me-2"></i>Розгорнути зараз
                            </a>
                            <a href="#" class="btn btn-outline-secondary btn-lg border-0">
                                Переглянути галерею <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 order-1 order-lg-2 text-center">
                    <div class="logo-container d-inline-block animate-float">
                        <svg width="200" height="200" viewBox="0 0 62 62" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M57.5 15.5V46.5L31 61.5L4.5 46.5V15.5L31 0.5L57.5 15.5Z" stroke="#f53003" stroke-width="2"/>
                            <path d="M31 15V47" stroke="#f53003" stroke-width="2"/>
                            <path d="M4.5 15.5L31 30.5L57.5 15.5" stroke="#f53003" stroke-width="2"/>
                        </svg>
                        <div class="mt-4">
                            <h2 class="fw-light text-muted">Екосистема <span class="fw-bold text-dark">Laravel</span></h2>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <footer class="bg-white py-4">
        <div class="container d-flex justify-content-between align-items-center">
            <p class="mb-0 text-muted small">
                &copy; {{ date('Y') }} <strong>DomCRM</strong>. Зроблено з любов'ю.
            </p>
            <div class="text-muted small">
                PHP v{{ PHP_VERSION }} <span class="mx-2">|</span> Laravel v{{ Illuminate\Foundation\Application::VERSION }}
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>