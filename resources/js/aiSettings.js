const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

export function initAiSettings(root, notify) {
    const el = id => root.querySelector('#' + id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let stores = [];
    let openaiModels = [];

    function describeOpenaiModel() {
        const model = openaiModels.find(option => option.id === el('openai-model').value);
        el('openai-model-description').textContent = model?.description || 'Збережена модель поза добіркою. Перед використанням перевірте її доступність і сумісність із Responses API та інструментами.';
    }

    function renderOpenaiModels(models, selected) {
        const select = el('openai-model');
        openaiModels = models || [];
        const addOption = (value, label) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.append(option);
        };
        select.replaceChildren();
        openaiModels.forEach(model => addOption(model.id, model.label));
        // Збережена модель залишається обраною, навіть якщо її немає в добірці.
        if (!openaiModels.some(model => model.id === selected)) {
            addOption(selected, selected + ' — збережена модель');
        }
        select.value = selected;
        select.disabled = false;
        describeOpenaiModel();
    }

    async function request(url, body) {
        const response = await fetch(url, body === undefined
            ? { headers: { Accept: 'application/json' } }
            : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: JSON.stringify(body) });
        let data;
        try { data = await response.json(); } catch {
            throw new Error(`Сервер повернув помилку HTTP ${response.status}. Повторіть спробу.`);
        }
        if (!response.ok || data.ok === false) {
            const error = new Error(data.error || data.message || 'Не вдалося виконати запит.');
            error.fields = data.errors || {};
            throw error;
        }
        return data;
    }

    function clearErrors() {
        root.querySelectorAll('[data-error-field].is-invalid').forEach(field => {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
        });
    }

    function failure(error, context, retry, aliases = {}) {
        const fields = error.fields || {};
        Object.keys(fields).forEach(name => {
            const key = aliases[name] || name;
            root.querySelectorAll('[data-error-field]').forEach(field => {
                if (field.dataset.errorField === key) {
                    field.classList.add('is-invalid');
                    field.setAttribute('aria-invalid', 'true');
                }
            });
        });
        notify({ context, title: 'Не вдалося виконати дію', type: 'error', messages: Object.keys(fields).length ? Object.values(fields).flat() : [error.message], actionLabel: 'Повторити', onAction: retry });
    }

    function renderStores() {
        el('ai-stores').innerHTML = stores.length ? stores.map((store, i) => `
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h6 fw-bold mb-0"><i class="bi bi-shop me-1 text-primary"></i>${esc(store.page_name)}</h2>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="st-en-${i}" ${store.enabled ? 'checked' : ''} data-store="${i}" data-field="enabled">
                            <label class="form-check-label small text-secondary" for="st-en-${i}">Агент увімкнений</label>
                        </div>
                    </div>
                    <label class="form-label small text-secondary" for="st-prompt-${i}">Інструкція агента (system prompt)</label>
                    <textarea id="st-prompt-${i}" class="form-control" rows="6" data-store="${i}" data-field="system_prompt" data-error-field="stores.${i}.system_prompt" placeholder="Тон спілкування, доставка, оплата, знижки…">${esc(store.system_prompt || '')}</textarea>
                    <div class="row g-2 align-items-end mt-2">
                        <div class="col-12 col-md-4">
                            <label class="form-label small text-secondary" for="st-mode-${i}">Графік роботи агента</label>
                            <select id="st-mode-${i}" class="form-select form-select-sm" data-store="${i}" data-field="schedule.mode">
                                <option value="always" ${store.schedule.mode === 'always' ? 'selected' : ''}>Цілодобово</option>
                                <option value="window" ${store.schedule.mode === 'window' ? 'selected' : ''}>За графіком (вікно активності)</option>
                            </select>
                        </div>
                        ${store.schedule.mode === 'window' ? `
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-secondary" for="st-from-${i}">Активний з</label>
                            <input id="st-from-${i}" type="time" class="form-control form-control-sm" value="${esc(store.schedule.from)}" data-store="${i}" data-field="schedule.from" data-error-field="stores.${i}.schedule.from">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-secondary" for="st-to-${i}">до</label>
                            <input id="st-to-${i}" type="time" class="form-control form-control-sm" value="${esc(store.schedule.to)}" data-store="${i}" data-field="schedule.to" data-error-field="stores.${i}.schedule.to">
                        </div>
                        <div class="col-12 col-md-4"><div class="form-text">Поза вікном агент мовчить; щойно вікно відкриється — добере невідповіджені.</div></div>` : ''}
                    </div>
                </div>
            </div>`).join('') : '<div class="text-secondary p-3">Немає підключених магазинів. Спершу підключіть сторінку в «Facebook та Instagram».</div>';
    }

    function updateStore(event) {
        const field = event.target;
        if (field.dataset.store === undefined) return;
        const store = stores[Number(field.dataset.store)];
        const key = field.dataset.field;
        if (key.startsWith('schedule.')) store.schedule[key.split('.')[1]] = field.value;
        else store[key] = key === 'enabled' ? field.checked : field.value;
        if (key === 'schedule.mode' && event.type === 'change') renderStores();
    }
    el('ai-stores').addEventListener('input', updateStore);
    el('ai-stores').addEventListener('change', updateStore);

    async function load() {
        try {
            const data = await request(root.dataset.urlData);
            const global = data.global;
            const model = global.model || 'claude-sonnet-4-6';
            // Підтримуємо вже збережені моделі, навіть якщо їх немає у списку.
            if (!Array.from(el('ai-model').options).some(option => option.value === model)) {
                const option = document.createElement('option');
                option.value = model;
                option.textContent = model;
                el('ai-model').append(option);
            }
            el('ai-model').value = model;
            el('ai-provider').value = global.provider || 'claude';
            el('ai-current-provider').textContent = global.provider === 'openai' ? 'ChatGPT API (OpenAI)' : 'Claude API';
            renderOpenaiModels(global.openai_models, global.openai_model || el('openai-model').value);
            el('ai-debounce').value = global.debounce_seconds ?? 10;
            el('ai-catalog-mode').value = global.catalog_mode || 'all';
            el('ai-op-pause').value = global.operator_pause_minutes ?? 3;
            el('ai-followup').value = global.follow_up_hours ?? 3;
            el('key-hint').textContent = global.has_key ? '— збережено ' + global.key_hint : '';
            el('openai-key-hint').textContent = global.openai_has_key ? '— збережено ' + global.openai_key_hint : '';
            const comments = global.comment_settings || {};
            el('cm-enabled').checked = !!comments.enabled;
            el('cm-fb').checked = comments.facebook !== false;
            el('cm-ig').checked = comments.instagram !== false;
            el('cm-opener').value = comments.opener || '';
            stores = (data.stores || []).map(store => ({ ...store, schedule: { mode: 'always', from: '20:00', to: '09:00', ...store.schedule } }));
            renderStores();
            el('ai-save').disabled = false;
        } catch (error) { failure(error, 'load', load); }
    }

    async function save() {
        const button = el('ai-save');
        button.disabled = true;
        clearErrors();
        const payload = {
            api_key: el('ai-key').value.trim() || null,
            model: el('ai-model').value,
            provider: el('ai-provider').value,
            openai_api_key: el('openai-key').value.trim() || null,
            openai_model: el('openai-model').value.trim(),
            debounce_seconds: el('ai-debounce').valueAsNumber,
            catalog_mode: el('ai-catalog-mode').value,
            operator_pause_minutes: el('ai-op-pause').valueAsNumber,
            follow_up_hours: el('ai-followup').valueAsNumber,
            comment_settings: { enabled: el('cm-enabled').checked, facebook: el('cm-fb').checked, instagram: el('cm-ig').checked, opener: el('cm-opener').value.trim() },
            stores: stores.map(store => ({ meta_connection_id: store.meta_connection_id, enabled: !!store.enabled, system_prompt: store.system_prompt || null, schedule: store.schedule })),
        };
        try {
            await request(root.dataset.urlSave, payload);
            [['ai-key', 'key-hint', payload.api_key], ['openai-key', 'openai-key-hint', payload.openai_api_key]].forEach(([id, hint, key]) => {
                if (!key) return;
                el(hint).textContent = '— збережено •••' + key.slice(-4);
                if (el(id).value.trim() === key) el(id).value = '';
            });
            el('ai-current-provider').textContent = payload.provider === 'openai' ? 'ChatGPT API (OpenAI)' : 'Claude API';
            notify({ context: 'save', type: 'success', title: 'Налаштування збережено', messages: ['Активний API: ' + (payload.provider === 'openai' ? 'ChatGPT API (OpenAI)' : 'Claude API') + '.'] });
        } catch (error) { failure(error, 'save', save); }
        button.disabled = false;
    }

    async function test(button) {
        const provider = button.dataset.testProvider;
        const isOpenai = provider === 'openai';
        const label = button.innerHTML;
        button.disabled = true;
        button.textContent = 'Перевіряю…';
        try {
            await request(root.dataset.urlTest, { provider, api_key: el(isOpenai ? 'openai-key' : 'ai-key').value.trim(), model: el(isOpenai ? 'openai-model' : 'ai-model').value });
            notify({ context: 'test-' + provider, type: 'success', title: 'Підключення працює', messages: [isOpenai ? 'Ключ OpenAI дійсний, модель доступна.' : 'Ключ Claude дійсний.'] });
        } catch (error) { failure(error, 'test-' + provider, () => test(button), { api_key: isOpenai ? 'openai_api_key' : 'api_key', model: isOpenai ? 'openai_model' : 'model' }); }
        button.innerHTML = label;
        button.disabled = false;
    }

    el('ai-save').addEventListener('click', save);
    el('openai-model').addEventListener('change', describeOpenaiModel);
    root.querySelectorAll('[data-test-provider]').forEach(button => button.addEventListener('click', () => test(button)));
    return { ready: load(), save };
}
