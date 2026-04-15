<x-app-layout>
    @php
        $toastMessages = [];

        if (session('success')) {
            $toastMessages[] = [
                'type' => 'success',
                'title' => 'Готово',
                'message' => session('success'),
            ];
        }

        if ($errors->any()) {
            foreach ($errors->all() as $error) {
                $toastMessages[] = [
                    'type' => 'danger',
                    'title' => 'Помилка',
                    'message' => $error,
                ];
            }
        }

        $totalUsers = empty($migrationPending) ? $users->count() : 0;
        $activeUsers = empty($migrationPending) ? $users->where('is_active', true)->count() : 0;
        $blockedUsers = empty($migrationPending) ? $users->where('is_active', false)->count() : 0;
    @endphp

    <div class="container-fluid px-4 py-4 team-page">
        <section class="team-hero-card mb-4">
            <div>
                <span class="team-hero-tag">Налаштування CRM</span>
                <h1 class="team-hero-title">Команда</h1>
                <p class="team-hero-subtitle mb-0">Керуйте доступами співробітників до розділів CRM без відкритої реєстрації.</p>
            </div>
            <div class="team-hero-stats">
                <div class="team-stat">
                    <div class="team-stat-label">Всього</div>
                    <div class="team-stat-value">{{ $totalUsers }}</div>
                </div>
                <div class="team-stat">
                    <div class="team-stat-label">Активні</div>
                    <div class="team-stat-value">{{ $activeUsers }}</div>
                </div>
                <div class="team-stat">
                    <div class="team-stat-label">Заблоковані</div>
                    <div class="team-stat-value">{{ $blockedUsers }}</div>
                </div>
            </div>
        </section>

        @if(!empty($migrationPending))
            <section class="team-warning-card">
                <div class="d-flex align-items-start gap-3">
                    <span class="team-warning-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                    <div>
                        <h2 class="h6 fw-bold mb-1">Міграція не застосована</h2>
                        <p class="text-secondary mb-2">Для розділу керування командою потрібно виконати міграції.</p>
                        <code>php artisan migrate</code>
                    </div>
                </div>
            </section>
        @else
            <div class="row g-4">
                <div class="col-12 col-xxl-4">
                    <section class="team-panel-card team-create-card h-100">
                        <div class="team-panel-head">
                            <h2>Новий користувач</h2>
                            <p>Створення акаунта для співробітника з потрібною роллю.</p>
                        </div>

                        <form method="POST" action="{{ route('settings.team.store') }}" class="team-create-form">
                            @csrf

                            <label class="team-input-label" for="new_user_name">Ім’я</label>
                            <input id="new_user_name" name="name" type="text" class="form-control team-input" value="{{ old('name') }}" required>

                            <label class="team-input-label mt-3" for="new_user_email">Email</label>
                            <input id="new_user_email" name="email" type="email" class="form-control team-input" value="{{ old('email') }}" required>

                            <label class="team-input-label mt-3" for="new_user_role">Роль</label>
                            <select id="new_user_role" name="role" class="form-select team-input" required>
                                @foreach ($roleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('role', \App\Models\User::ROLE_OPERATOR) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>

                            <div class="row g-2 mt-1">
                                <div class="col-12 col-md-6 col-xxl-12">
                                    <label class="team-input-label mt-3" for="new_user_password">Пароль</label>
                                    <input id="new_user_password" name="password" type="password" class="form-control team-input" required>
                                </div>
                                <div class="col-12 col-md-6 col-xxl-12">
                                    <label class="team-input-label mt-3" for="new_user_password_confirmation">Підтвердження</label>
                                    <input id="new_user_password_confirmation" name="password_confirmation" type="password" class="form-control team-input" required>
                                </div>
                            </div>

                            <button type="submit" class="btn team-submit-btn mt-4">
                                <i class="bi bi-person-plus-fill me-2"></i>
                                Додати користувача
                            </button>
                        </form>
                    </section>
                </div>

                <div class="col-12 col-xxl-8">
                    <section class="team-panel-card h-100">
                        <div class="team-panel-head">
                            <h2>Склад команди</h2>
                            <p>Редагуйте роль, статус та пароль для існуючих користувачів.</p>
                        </div>

                        <div class="team-table-wrap">
                            <table class="table team-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Користувач</th>
                                        <th>Роль</th>
                                        <th>Статус</th>
                                        <th>Новий пароль</th>
                                        <th class="pe-3 text-end">Дії</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($users as $user)
                                        @php
                                            $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
                                            $initials = collect($parts)->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
                                        @endphp
                                        <tr>
                                            <td class="ps-3">
                                                <div class="team-user-cell">
                                                    <span class="team-avatar">{{ $initials ?: 'U' }}</span>
                                                    <div>
                                                        <div class="fw-semibold">{{ $user->name }}</div>
                                                        <div class="small text-secondary">{{ $user->email }}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td colspan="4" class="pe-3">
                                                <form method="POST" action="{{ route('settings.team.update', $user) }}" class="row g-2 align-items-center">
                                                    @csrf
                                                    @method('PATCH')

                                                    <div class="col-12 col-lg-3">
                                                        <select name="role" class="form-select form-select-sm team-row-input">
                                                            @foreach ($roleOptions as $value => $label)
                                                                <option value="{{ $value }}" @selected($user->role === $value)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>

                                                    <div class="col-12 col-lg-2">
                                                        <select name="is_active" class="form-select form-select-sm team-row-input">
                                                            <option value="1" @selected($user->is_active)>Активний</option>
                                                            <option value="0" @selected(!$user->is_active)>Заблокований</option>
                                                        </select>
                                                    </div>

                                                    <div class="col-12 col-lg-3">
                                                        <input type="password" name="password" class="form-control form-control-sm team-row-input" placeholder="Новий пароль">
                                                    </div>

                                                    <div class="col-12 col-lg-3">
                                                        <input type="password" name="password_confirmation" class="form-control form-control-sm team-row-input" placeholder="Підтвердження">
                                                    </div>

                                                    <div class="col-12 col-lg-1">
                                                        <div class="d-grid gap-1">
                                                            <button type="submit" class="btn btn-sm team-row-save-btn w-100" title="Зберегти">
                                                                <i class="bi bi-check2"></i>
                                                            </button>

                                                            @if(auth()->id() !== $user->id)
                                                                <button
                                                                    type="submit"
                                                                    form="delete-team-user-{{ $user->id }}"
                                                                    class="btn btn-sm team-row-delete-btn w-100"
                                                                    title="Видалити користувача"
                                                                >
                                                                    <i class="bi bi-trash3"></i>
                                                                </button>
                                                            @else
                                                                <span class="team-row-self-badge">Ви</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </form>

                                                @if(auth()->id() !== $user->id)
                                                    <form
                                                        id="delete-team-user-{{ $user->id }}"
                                                        method="POST"
                                                        action="{{ route('settings.team.destroy', $user) }}"
                                                        class="d-none"
                                                        onsubmit="return confirm('Видалити користувача {{ $user->name }}? Цю дію не можна скасувати.')"
                                                    >
                                                        @csrf
                                                        @method('DELETE')
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="py-5 text-center text-secondary">Користувачів ще немає.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        @endif
    </div>

    @if(count($toastMessages) > 0)
        <div class="toast-container position-fixed top-0 end-0 p-3 team-toast-stack">
            @foreach($toastMessages as $index => $toast)
                <div
                    class="toast team-toast border-0 shadow"
                    role="alert"
                    aria-live="assertive"
                    aria-atomic="true"
                    data-bs-delay="{{ $toast['type'] === 'danger' ? 7000 : 4500 }}"
                >
                    <div class="toast-header team-toast-header {{ $toast['type'] === 'danger' ? 'is-danger' : 'is-success' }}">
                        <i class="bi {{ $toast['type'] === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill' }} me-2"></i>
                        <strong class="me-auto">{{ $toast['title'] }}</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body team-toast-body">{{ $toast['message'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof bootstrap === 'undefined') {
                return;
            }

            document.querySelectorAll('.team-toast').forEach(function (element) {
                const toast = new bootstrap.Toast(element);
                toast.show();
            });
        });
    </script>

    <style>
        .team-page {
            --team-accent: #2563eb;
            --team-accent-soft: #dbeafe;
            --team-surface: #ffffff;
            --team-border: #e5e7eb;
            --team-muted: #64748b;
            --team-dark: #0f172a;
        }

        .team-hero-card {
            background: radial-gradient(circle at top left, #e0ecff 0%, #f8fbff 55%, #ffffff 100%);
            border: 1px solid var(--team-border);
            border-radius: 20px;
            padding: 1.6rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: space-between;
            align-items: flex-end;
        }

        .team-hero-tag {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #1d4ed8;
            font-weight: 700;
            background: rgba(37, 99, 235, .1);
            border: 1px solid rgba(37, 99, 235, .2);
            border-radius: 999px;
            padding: .3rem .6rem;
            margin-bottom: .75rem;
        }

        .team-hero-title {
            font-size: clamp(1.35rem, 2vw, 1.9rem);
            font-weight: 800;
            color: var(--team-dark);
            margin-bottom: .35rem;
        }

        .team-hero-subtitle {
            color: var(--team-muted);
            max-width: 680px;
        }

        .team-hero-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(88px, 1fr));
            gap: .65rem;
            min-width: 290px;
        }

        .team-stat {
            background: var(--team-surface);
            border: 1px solid var(--team-border);
            border-radius: 14px;
            padding: .7rem .8rem;
            text-align: center;
        }

        .team-stat-label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #64748b;
            font-weight: 700;
        }

        .team-stat-value {
            margin-top: .15rem;
            color: #0f172a;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1;
        }

        .team-warning-card {
            border-radius: 16px;
            border: 1px solid #fed7aa;
            background: #fff7ed;
            padding: 1.2rem 1.3rem;
            color: #7c2d12;
        }

        .team-warning-icon {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #ffedd5;
            color: #ea580c;
        }

        .team-panel-card {
            border: 1px solid var(--team-border);
            background: var(--team-surface);
            border-radius: 18px;
            padding: 1.15rem 1.2rem;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
        }

        .team-create-card {
            background: linear-gradient(155deg, #ffffff 0%, #f8fbff 100%);
        }

        .team-panel-head h2 {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: .15rem;
        }

        .team-panel-head p {
            margin-bottom: 1rem;
            color: var(--team-muted);
            font-size: .92rem;
        }

        .team-input-label {
            font-size: .8rem;
            color: #334155;
            font-weight: 700;
            margin-bottom: .35rem;
            display: block;
        }

        .team-input {
            border-radius: 12px;
            border: 1px solid #dbe2ea;
            min-height: 42px;
        }

        .team-input:focus,
        .team-row-input:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .14);
        }

        .team-submit-btn {
            width: 100%;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid #1d4ed8;
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            font-weight: 700;
        }

        .team-submit-btn:hover {
            background: linear-gradient(180deg, #1d4ed8 0%, #1e40af 100%);
            color: #fff;
        }

        .team-table-wrap {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
        }

        .team-table thead th {
            background: #f8fafc;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: .72rem;
            font-weight: 800;
            border-bottom: 1px solid #e2e8f0;
            padding-top: .8rem;
            padding-bottom: .8rem;
            white-space: nowrap;
        }

        .team-table td {
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            padding-top: .9rem;
            padding-bottom: .9rem;
        }

        .team-user-cell {
            display: flex;
            align-items: center;
            gap: .75rem;
            min-width: 210px;
        }

        .team-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(145deg, #1d4ed8, #2563eb);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            letter-spacing: .02em;
            font-size: .9rem;
            box-shadow: 0 8px 14px rgba(37, 99, 235, .25);
            flex-shrink: 0;
        }

        .team-row-input {
            border-radius: 10px;
            border: 1px solid #dbe2ea;
            min-height: 35px;
        }

        .team-row-save-btn {
            border-radius: 10px;
            border: 1px solid #1d4ed8;
            background: #fff;
            color: #1d4ed8;
            font-weight: 700;
            min-height: 35px;
        }

        .team-row-save-btn:hover {
            background: #eff6ff;
            color: #1e40af;
            border-color: #1e40af;
        }

        .team-row-delete-btn {
            border-radius: 10px;
            border: 1px solid #fecaca;
            background: #fff5f5;
            color: #dc2626;
            min-height: 35px;
        }

        .team-row-delete-btn:hover {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        .team-row-self-badge {
            min-height: 35px;
            border-radius: 10px;
            border: 1px solid #dbeafe;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .74rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .team-toast-stack {
            z-index: 1080;
        }

        .team-toast {
            border-radius: 14px;
            overflow: hidden;
            min-width: 290px;
            max-width: 420px;
        }

        .team-toast-header {
            border-bottom: 0;
            color: #fff;
            font-weight: 700;
        }

        .team-toast-header.is-success {
            background: linear-gradient(180deg, #16a34a, #15803d);
        }

        .team-toast-header.is-danger {
            background: linear-gradient(180deg, #dc2626, #b91c1c);
        }

        .team-toast-body {
            background: #fff;
            color: #0f172a;
            font-size: .92rem;
            line-height: 1.35;
        }

        @media (max-width: 1199.98px) {
            .team-hero-stats {
                width: 100%;
                min-width: 0;
            }
        }

        @media (max-width: 991.98px) {
            .team-table td[colspan="4"] form {
                gap: .45rem !important;
            }
        }
    </style>
</x-app-layout>
