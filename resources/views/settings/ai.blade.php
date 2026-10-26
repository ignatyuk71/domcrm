<x-app-layout>
    <div class="container-fluid px-4 py-4" data-ai-settings
         data-url-data="{{ route('settings.ai.data') }}" data-url-save="{{ route('settings.ai.save') }}"
         data-url-test="{{ route('settings.ai.test') }}">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase fw-bold mb-2">Налаштування CRM</span>
                <h1 class="h3 fw-bold mb-1"><i class="bi bi-robot me-2 text-primary"></i>AI-агент</h1>
                <p class="text-secondary mb-0">Один активний API для всього AI-агента: Claude або ChatGPT.</p>
            </div>
            <button id="ai-save" class="btn btn-primary fw-semibold" disabled><i class="bi bi-check-lg me-1"></i>Зберегти</button>
        </div>

        <div data-page-toast></div>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">Перемикач API</h2>
                <label for="ai-provider" class="form-label small text-secondary">Активний API для всієї CRM</label>
                <select id="ai-provider" class="form-select" data-error-field="provider">
                    <option value="claude">Claude API</option>
                    <option value="openai">ChatGPT API (OpenAI)</option>
                </select>
                <div class="form-text">Зараз працює: <strong id="ai-current-provider">завантаження…</strong>. Виберіть API та натисніть «Зберегти» — усі нові відповіді агента використовуватимуть тільки його.</div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-key me-1 text-primary"></i>Підключення Claude API</h2>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label small text-secondary mb-1">Ключ API <span id="key-hint" class="text-success fw-semibold"></span></label>
                        <input id="ai-key" data-error-field="api_key" type="password" class="form-control" placeholder="sk-ant-..." autocomplete="off">
                        <div class="form-text">Ключ зберігається зашифрованим. Поле порожнє — ключ не зміниться.</div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small text-secondary mb-1">Модель</label>
                        <select id="ai-model" data-error-field="model" class="form-select">
                            <option value="claude-sonnet-5">Claude Sonnet 5 — новий, швидкий і вигідний</option>
                            <option value="claude-sonnet-4-6">Claude Sonnet 4.6 — баланс</option>
                            <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5 — швидкий/дешевий</option>
                            <option value="claude-opus-4-8">Claude Opus 4.8 — найрозумніший</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small text-secondary mb-1">Пауза, сек</label>
                        <input id="ai-debounce" data-error-field="debounce_seconds" type="number" class="form-control" min="0" max="60" value="10" title="Скільки чекати, поки клієнт допише (відповідь одна на всю чергу повідомлень)">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button class="btn btn-outline-primary" data-test-provider="claude"><i class="bi bi-plug me-1"></i>Перевірити</button>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label small text-secondary mb-1">Каталог для агента</label>
                        <select id="ai-catalog-mode" data-error-field="catalog_mode" class="form-select">
                            <option value="all">Вся база товарів (вітрина + інші товари)</option>
                            <option value="showcase">Лише вітрина — продає тільки те, що в Галереї ШІ</option>
                        </select>
                        <div class="form-text">«Лише вітрина»: чого нема в групах Галереї ШІ — того для агента не існує.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Пауза після оператора, хв</label>
                        <input id="ai-op-pause" data-error-field="operator_pause_minutes" type="number" class="form-control" min="0" max="1440" value="3">
                        <div class="form-text">Людина написала в розмову → ШІ мовчить у ній стільки хвилин і продовжує сам. 0 — вимкнено.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Нагадування мовчазним, год</label>
                        <input id="ai-followup" data-error-field="follow_up_hours" type="number" class="form-control" min="0" max="48" value="3">
                        <div class="form-text">Клієнт написав, але замовк → через стільки годин ШІ шле ОДИН теплий пінг. 0 — вимкнено.</div>
                    </div>
                </div>
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
                    <div class="col-12 col-md-10">
                        <label class="form-label small text-secondary mb-1">Відкривач, коли модель з поста не розпізнано</label>
                        <textarea id="cm-opener" data-error-field="comment_settings.opener" class="form-control" rows="2"></textarea>
                        <div class="form-text">Бот відповідає в директ на КОЖЕН коментар. Якщо лінійку видно з тексту поста — сам назве її й ціну, інакше — цей відкривач. Одна відповідь на коментар (ліміт Meta).</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-key me-1 text-primary"></i>Підключення ChatGPT API (OpenAI)</h2>
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md-5">
                        <label for="openai-key" class="form-label small text-secondary">Ключ OpenAI <span id="openai-key-hint" class="fw-semibold"></span></label>
                        <input id="openai-key" type="password" class="form-control" placeholder="sk-..." autocomplete="off" data-error-field="openai_api_key">
                        <div class="form-text">Вставте секретний API-ключ із <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">кабінету OpenAI</a>. Зберігається зашифрованим. Порожнє поле зберігає наявний ключ.</div>
                    </div>
                    <div class="col-12 col-md-5">
                        <label for="openai-model" class="form-label small text-secondary">Модель OpenAI</label>
                        <select id="openai-model" class="form-select" data-error-field="openai_model" aria-describedby="openai-model-description openai-model-access" disabled>
                            <option value="{{ \App\Models\AiSetting::DEFAULT_OPENAI_MODEL }}">Завантаження моделей…</option>
                        </select>
                        <div id="openai-model-description" class="form-text"></div>
                        <div id="openai-model-access" class="form-text">«Перевірити» перевіряє ключ і доступ до обраної моделі у вашому API-проєкті.</div>
                    </div>
                    <div class="col-12 col-md-2">
                        <button class="btn btn-outline-primary mt-md-4" data-test-provider="openai"><i class="bi bi-plug me-1"></i>Перевірити</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div id="ai-stores"><div class="text-muted">Завантаження…</div></div>

    </div>

</x-app-layout>
