<x-app-layout>
    <div class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase fw-bold mb-2">Налаштування CRM</span>
                <h1 class="h3 fw-bold mb-1">Facebook та Instagram</h1>
                <p class="text-secondary mb-0">Підключення сторінок Facebook (і звʼязаних акаунтів Instagram) для листування в чаті.</p>
            </div>
            <a href="{{ route('settings.meta.connect') }}" class="btn btn-primary fw-semibold">
                <i class="bi bi-facebook me-1"></i> Підключити Facebook
            </a>
        </div>

        @include('partials.flash-toasts')

        @unless($configured)
            <div class="alert alert-warning border-0 shadow-sm rounded-4">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Не задані <code>META_APP_ID</code> / <code>META_APP_SECRET</code> у <code>.env</code>. Підключення не працюватиме, поки їх не вкажеш.
            </div>
        @endunless

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-gear me-1 text-primary"></i>Що вказати в налаштуваннях Meta-застосунку</h2>

                <label class="form-label fw-semibold small text-secondary mb-1">Valid OAuth Redirect URI</label>
                <div class="input-group input-group-sm mb-3">
                    <input type="text" class="form-control bg-light font-monospace" value="{{ $redirectUri }}" id="redir" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyVal('redir', this)"><i class="bi bi-clipboard"></i></button>
                </div>

                <label class="form-label fw-semibold small text-secondary mb-1">Webhook Callback URL</label>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" class="form-control bg-light font-monospace" value="{{ url('/api/meta/webhook') }}" id="wh" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyVal('wh', this)"><i class="bi bi-clipboard"></i></button>
                </div>
                <p class="text-secondary small mb-0">Verify token — значення <code>META_VERIFY_TOKEN</code> з <code>.env</code>.</p>
            </div>
        </div>

        <div class="row g-3">
            @forelse($connections as $c)
                <div class="col-12 col-xxl-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <i class="bi bi-facebook fs-4 text-primary"></i>
                                <span class="fw-bold fs-5">{{ $c->page_name }}</span>
                                @if($c->status === 'active')
                                    <span class="badge bg-success-subtle text-success-emphasis">Активна</span>
                                @elseif($c->status === 'error')
                                    <span class="badge bg-danger-subtle text-danger-emphasis">Помилка</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Відключена</span>
                                @endif
                            </div>

                            <ul class="list-unstyled small text-secondary mb-3">
                                <li><i class="bi bi-hash"></i> Page ID: <span class="font-monospace">{{ $c->page_id }}</span></li>
                                @if($c->ig_username)
                                    <li><i class="bi bi-instagram"></i> Instagram: <span class="font-monospace">{{ '@'.$c->ig_username }}</span></li>
                                @else
                                    <li class="text-muted"><i class="bi bi-instagram"></i> Instagram не звʼязаний</li>
                                @endif
                                <li>
                                    <i class="bi bi-broadcast"></i> Вебхук:
                                    @if($c->webhook_subscribed)
                                        <span class="text-success fw-semibold">підписано</span>
                                    @else
                                        <span class="text-warning fw-semibold">не підписано</span>
                                    @endif
                                </li>
                            </ul>

                            @if($c->last_error)
                                <div class="alert alert-warning py-2 px-3 small mb-3">{{ $c->last_error }}</div>
                            @endif

                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('settings.meta.test', $c) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-shield-check me-1"></i>Перевірити</button>
                                </form>
                                <form method="POST" action="{{ route('settings.meta.disconnect', $c) }}" onsubmit="return confirm('Відключити сторінку «{{ $c->page_name }}»?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Відключити</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-5 text-center text-secondary">
                            <i class="bi bi-facebook fs-1 d-block mb-2 opacity-50"></i>
                            Ще немає підключених сторінок. Натисни «Підключити Facebook» угорі.
                        </div>
                    </div>
                </div>
            @endforelse
        </div>
    </div>

    <script>
        function copyVal(id, btn) {
            const el = document.getElementById(id);
            navigator.clipboard.writeText(el.value).then(() => {
                const old = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i>';
                setTimeout(() => { btn.innerHTML = old; }, 1200);
            });
        }
    </script>
</x-app-layout>
