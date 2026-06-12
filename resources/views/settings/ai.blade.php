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
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Пауза після оператора, год</label>
                        <input id="ai-op-pause" type="number" class="form-control" min="0" max="48" value="6">
                        <div class="form-text">Людина написала в розмову → ШІ мовчить у ній стільки годин. 0 — вимкнено.</div>
                    </div>
                </div>
                <div id="test-result" class="small mt-2 d-none"></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-chat-square-quote me-1 text-primary"></i>Коментарі — автовідповідь у директ</h2>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="cm-enabled">
                        <label class="form-check-label small text-secondary" for="cm-enabled">Увімкнено</label>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-6 col-md-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="cm-fb" checked>
                            <label class="form-check-label small" for="cm-fb"><i class="bi bi-messenger"></i> Facebook</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="cm-ig" checked>
                            <label class="form-check-label small" for="cm-ig"><i class="bi bi-instagram"></i> Instagram</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">На які коментарі</label>
                        <select id="cm-mode" class="form-select form-select-sm">
                            <option value="keywords">Лише з тригер-словами</option>
                            <option value="all">На всі коментарі</option>
                        </select>
                        <input id="cm-keywords" class="form-control form-control-sm mt-2" placeholder="ціна, скільки, хочу, +">
                    </div>
                    <div class="col-12 col-md-7">
                        <label class="form-label small text-secondary mb-1">Відкривач, коли модель з поста не розпізнано</label>
                        <textarea id="cm-opener" class="form-control" rows="2"></textarea>
                        <div class="form-text">Якщо лінійку видно з тексту поста — агент сам назве її й ціну. Одна відповідь на коментар (ліміт Meta).</div>
                    </div>
                </div>
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
            document.getElementById('ai-op-pause').value = data.global.operator_pause_hours ?? 6;
            const cm = data.global.comment_settings || {};
            document.getElementById('cm-enabled').checked = !!cm.enabled;
            document.getElementById('cm-fb').checked = cm.facebook !== false;
            document.getElementById('cm-ig').checked = cm.instagram !== false;
            document.getElementById('cm-mode').value = cm.mode || 'keywords';
            document.getElementById('cm-keywords').value = cm.keywords || '';
            document.getElementById('cm-opener').value = cm.opener || '';
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
                        <div class="row g-2 align-items-end mt-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label small text-secondary mb-1">Графік роботи агента</label>
                                <select class="form-select form-select-sm" onchange="storesData[${i}].schedule.mode=this.value; renderStores()">
                                    <option value="always" ${(s.schedule?.mode || 'always') === 'always' ? 'selected' : ''}>Цілодобово</option>
                                    <option value="window" ${s.schedule?.mode === 'window' ? 'selected' : ''}>За графіком (вікно активності)</option>
                                </select>
                            </div>
                            ${s.schedule?.mode === 'window' ? `
                            <div class="col-6 col-md-2">
                                <label class="form-label small text-secondary mb-1">Активний з</label>
                                <input type="time" class="form-control form-control-sm" value="${esc(s.schedule?.from || '20:00')}" onchange="storesData[${i}].schedule.from=this.value">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small text-secondary mb-1">до</label>
                                <input type="time" class="form-control form-control-sm" value="${esc(s.schedule?.to || '09:00')}" onchange="storesData[${i}].schedule.to=this.value">
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="form-text">Поза вікном агент мовчить; щойно вікно відкриється — сам добере невідповіджені (якщо людина так і не відповіла).</div>
                            </div>` : ''}
                        </div>
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
                        operator_pause_hours: parseInt(document.getElementById('ai-op-pause').value || '6', 10),
                        comment_settings: {
                            enabled: document.getElementById('cm-enabled').checked,
                            facebook: document.getElementById('cm-fb').checked,
                            instagram: document.getElementById('cm-ig').checked,
                            mode: document.getElementById('cm-mode').value,
                            keywords: document.getElementById('cm-keywords').value.trim(),
                            opener: document.getElementById('cm-opener').value.trim(),
                        },
                        stores: storesData.map(s => ({ meta_connection_id: s.meta_connection_id, enabled: !!s.enabled, system_prompt: s.system_prompt || null, schedule: s.schedule || null })),
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
