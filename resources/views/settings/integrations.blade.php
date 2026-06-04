<x-app-layout>
    <div class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase fw-bold mb-2">Налаштування CRM</span>
                <h1 class="h3 fw-bold mb-1">Інтеграції із сайтами</h1>
                <p class="text-secondary mb-0">Підключення зовнішніх сайтів, з яких замовлення автоматично потрапляють у CRM.</p>
            </div>
        </div>

        @include('partials.flash-toasts')

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-link-45deg fs-5 text-primary"></i>
                    <h2 class="h6 fw-bold mb-0">Адреса прийому замовлень (endpoint)</h2>
                </div>
                <p class="text-secondary small mb-2">Сайт надсилає замовлення сюди методом <code>POST</code> (JSON). Авторизація — заголовок <code>X-Api-Key</code>, а якщо заданий секрет — ще й підпис <code>X-Signature</code> = HMAC-SHA256 тіла запиту.</p>
                <div class="input-group">
                    <input type="text" class="form-control bg-light font-monospace" value="{{ $intakeUrl }}" id="intake-url" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyVal('intake-url', this)"><i class="bi bi-clipboard"></i></button>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xxl-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold mb-1">Новий сайт</h2>
                        <p class="text-secondary small mb-3">Додайте підключення — API-ключ і секрет згенеруються автоматично.</p>
                        <form method="POST" action="{{ route('settings.integrations.store') }}">
                            @csrf
                            <label class="form-label fw-semibold small">Назва</label>
                            <input name="name" type="text" class="form-control mb-3" placeholder="Напр. Основний сайт" value="{{ old('name') }}" required>

                            <label class="form-label fw-semibold small">Платформа</label>
                            <select name="adapter" class="form-select mb-3" required>
                                <option value="custom">Власний сайт (PHP)</option>
                                <option value="shopify">Shopify</option>
                                <option value="opencart">OpenCart</option>
                                <option value="prom">Prom.ua</option>
                            </select>

                            <label class="form-label fw-semibold small">Режим</label>
                            <select name="mode" class="form-select mb-4" required>
                                <option value="push">PUSH — сайт надсилає сам (webhook)</option>
                                <option value="pull">PULL — CRM тягне за розкладом</option>
                            </select>

                            <button type="submit" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-plus-circle me-1"></i> Додати інтеграцію</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xxl-8">
                @forelse($sources as $source)
                    @php $cfg = $source->pull_config ?? []; @endphp
                    <div class="card border-0 shadow-sm rounded-4 mb-3">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                <span class="fw-bold fs-5">{{ $source->name }}</span>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis text-uppercase">{{ $source->adapter }}</span>
                                <span class="badge {{ $source->mode === 'pull' ? 'bg-info-subtle text-info-emphasis' : 'bg-primary-subtle text-primary-emphasis' }} text-uppercase">{{ $source->mode }}</span>
                                @if($source->is_enabled)
                                    <span class="badge bg-success-subtle text-success-emphasis">Активна</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger-emphasis">Вимкнена</span>
                                @endif
                            </div>

                            <label class="form-label fw-semibold small text-secondary mb-1">API-ключ (заголовок X-Api-Key)</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control bg-light font-monospace" value="{{ $source->api_key }}" id="key-{{ $source->id }}" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyVal('key-{{ $source->id }}', this)"><i class="bi bi-clipboard"></i></button>
                            </div>

                            <label class="form-label fw-semibold small text-secondary mb-1">Секрет (для підпису X-Signature)</label>
                            <div class="input-group input-group-sm mb-3">
                                <input type="password" class="form-control bg-light font-monospace" value="{{ $source->api_secret }}" id="sec-{{ $source->id }}" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleVal('sec-{{ $source->id }}', this)"><i class="bi bi-eye"></i></button>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyVal('sec-{{ $source->id }}', this)"><i class="bi bi-clipboard"></i></button>
                            </div>

                            <form method="POST" action="{{ route('settings.integrations.update', $source) }}" class="row g-2 align-items-end">
                                @csrf
                                @method('PATCH')
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Назва</label>
                                    <input name="name" type="text" class="form-control form-control-sm" value="{{ $source->name }}" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Платформа</label>
                                    <select name="adapter" class="form-select form-select-sm">
                                        @foreach(['custom' => 'Власний', 'shopify' => 'Shopify', 'opencart' => 'OpenCart', 'prom' => 'Prom.ua'] as $v => $l)
                                            <option value="{{ $v }}" @selected($source->adapter === $v)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-semibold mb-1">Режим</label>
                                    <select name="mode" class="form-select form-select-sm">
                                        <option value="push" @selected($source->mode === 'push')>PUSH</option>
                                        <option value="pull" @selected($source->mode === 'pull')>PULL</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Статус</label>
                                    <select name="is_enabled" class="form-select form-select-sm">
                                        <option value="1" @selected($source->is_enabled)>Активна</option>
                                        <option value="0" @selected(!$source->is_enabled)>Вимкнена</option>
                                    </select>
                                </div>

                                @if($source->mode === 'pull')
                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">API-ключ платформи (для PULL, напр. Prom)</label>
                                        <input name="prom_api_key" type="text" class="form-control form-control-sm font-monospace" value="{{ $cfg['api_key'] ?? '' }}" placeholder="ключ доступу до API сайту">
                                    </div>
                                @endif

                                <div class="col-12 mt-2">
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Зберегти</button>
                                </div>
                            </form>

                            <hr class="my-3">
                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('settings.integrations.regenerate', $source) }}" onsubmit="return confirm('Перевипустити ключі? Старі перестануть працювати.')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1"></i>Перевипустити ключі</button>
                                </form>
                                <form method="POST" action="{{ route('settings.integrations.destroy', $source) }}" onsubmit="return confirm('Видалити інтеграцію «{{ $source->name }}»?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Видалити</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-5 text-center text-secondary">
                            <i class="bi bi-hdd-network fs-1 d-block mb-2 opacity-50"></i>
                            Ще немає підключених сайтів. Додайте перший зліва.
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <script>
        function copyVal(id, btn) {
            const el = document.getElementById(id);
            const prevType = el.type;
            el.type = 'text';
            navigator.clipboard.writeText(el.value).then(() => {
                el.type = prevType;
                const old = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i>';
                setTimeout(() => { btn.innerHTML = old; }, 1200);
            }).catch(() => { el.type = prevType; });
        }

        function toggleVal(id, btn) {
            const el = document.getElementById(id);
            el.type = el.type === 'password' ? 'text' : 'password';
            btn.innerHTML = el.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        }
    </script>
</x-app-layout>
