<x-app-layout>
    <div class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase fw-bold mb-2">Налаштування CRM</span>
                <h1 class="h3 fw-bold mb-1"><i class="bi bi-robot me-2 text-primary"></i>AI-агент</h1>
                <p class="text-secondary mb-0">Підключення Claude та інструкції для кожного магазину. Сам агент вмикається поетапно.</p>
            </div>
            <button class="btn btn-primary fw-semibold" onclick="saveAll(this)"><i class="bi bi-check-lg me-1"></i>Зберегти</button>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-key me-1 text-primary"></i>Підключення Claude API</h2>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label small text-secondary mb-1">Ключ API <span id="key-hint" class="text-success fw-semibold"></span></label>
                        <input id="ai-key" type="password" class="form-control" placeholder="sk-ant-..." autocomplete="off">
                        <div class="form-text">Ключ зберігається зашифрованим. Поле порожнє — ключ не зміниться.</div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small text-secondary mb-1">Модель</label>
                        <select id="ai-model" class="form-select">
                            <option value="claude-sonnet-4-6">Claude Sonnet 4.6 — баланс</option>
                            <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5 — швидкий/дешевий</option>
                            <option value="claude-opus-4-8">Claude Opus 4.8 — найрозумніший</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small text-secondary mb-1">Пауза, сек</label>
                        <input id="ai-debounce" type="number" class="form-control" min="0" max="60" value="10" title="Скільки чекати, поки клієнт допише (відповідь одна на всю чергу повідомлень)">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="testKey(this)"><i class="bi bi-plug me-1"></i>Перевірити</button>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label small text-secondary mb-1">Каталог для агента</label>
                        <select id="ai-catalog-mode" class="form-select">
                            <option value="all">Вся база товарів (вітрина + інші товари)</option>
                            <option value="showcase">Лише вітрина — продає тільки те, що в Галереї ШІ</option>
                        </select>
                        <div class="form-text">«Лише вітрина»: чого нема в групах Галереї ШІ — того для агента не існує.</div>
                    </div>
                </div>
                <div id="test-result" class="small mt-2 d-none"></div>
            </div>
        </div>

        <div id="ai-stores"><div class="text-muted">Завантаження…</div></div>

        <div id="save-result" class="small mt-2 d-none"></div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        let storesData = [];

        async function loadData() {
            const res = await fetch('{{ route('settings.ai.data') }}', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            document.getElementById('ai-model').value = data.global.model || 'claude-sonnet-4-6';
            document.getElementById('ai-debounce').value = data.global.debounce_seconds ?? 10;
            document.getElementById('ai-catalog-mode').value = data.global.catalog_mode || 'all';
            document.getElementById('key-hint').textContent = data.global.has_key ? ('— збережено ' + data.global.key_hint) : '';
            storesData = data.stores || [];
            renderStores();
        }

        function renderStores() {
            const box = document.getElementById('ai-stores');
            if (!storesData.length) {
                box.innerHTML = '<div class="alert alert-warning border-0 shadow-sm rounded-4">Немає підключених магазинів. Спершу підключіть сторінку в «Facebook та Instagram».</div>';
                return;
            }
            box.innerHTML = storesData.map((s, i) => `
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h6 fw-bold mb-0"><i class="bi bi-shop me-1 text-primary"></i>${esc(s.page_name)}</h2>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="st-en-${i}" ${s.enabled ? 'checked' : ''} onchange="storesData[${i}].enabled=this.checked">
                                <label class="form-check-label small text-secondary" for="st-en-${i}">Агент увімкнений</label>
                            </div>
                        </div>
                        <label class="form-label small text-secondary mb-1">Інструкція агента (system prompt)</label>
                        <textarea class="form-control" rows="7" placeholder="Хто він, як спілкується, що можна і чого не можна. Напишемо разом на наступному кроці." oninput="storesData[${i}].system_prompt=this.value">${esc(s.system_prompt || '')}</textarea>
                    </div>
                </div>`).join('');
        }

        async function saveAll(btn) {
            btn.disabled = true;
            const out = document.getElementById('save-result');
            out.classList.add('d-none');
            try {
                const res = await fetch('{{ route('settings.ai.save') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        api_key: document.getElementById('ai-key').value.trim() || null,
                        model: document.getElementById('ai-model').value,
                        debounce_seconds: parseInt(document.getElementById('ai-debounce').value || '10', 10),
                        catalog_mode: document.getElementById('ai-catalog-mode').value,
                        stores: storesData.map(s => ({ meta_connection_id: s.meta_connection_id, enabled: !!s.enabled, system_prompt: s.system_prompt || null })),
                    })
                });
                if (!res.ok) throw new Error('Не вдалося зберегти');
                document.getElementById('ai-key').value = '';
                out.className = 'small mt-2 text-success';
                out.textContent = '✓ Збережено';
                loadData();
            } catch (e) {
                out.className = 'small mt-2 text-danger';
                out.textContent = e.message;
            }
            out.classList.remove('d-none');
            btn.disabled = false;
        }

        async function testKey(btn) {
            btn.disabled = true;
            const out = document.getElementById('test-result');
            out.className = 'small mt-2 text-secondary';
            out.textContent = 'Перевіряю…';
            out.classList.remove('d-none');
            try {
                const res = await fetch('{{ route('settings.ai.test') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ api_key: document.getElementById('ai-key').value.trim() })
                });
                const data = await res.json();
                if (data.ok) {
                    out.className = 'small mt-2 text-success';
                    out.textContent = '✓ Зʼєднання працює, ключ дійсний';
                } else {
                    out.className = 'small mt-2 text-danger';
                    out.textContent = '✗ ' + (data.error || 'Ключ не підійшов');
                }
            } catch (e) {
                out.className = 'small mt-2 text-danger';
                out.textContent = '✗ Помилка перевірки';
            }
            btn.disabled = false;
        }

        loadData();
    </script>
    @endpush
</x-app-layout>
