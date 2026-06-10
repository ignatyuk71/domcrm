<x-app-layout>
    <div class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase fw-bold mb-2">Налаштування CRM</span>
                <h1 class="h3 fw-bold mb-1">Статуси чату</h1>
                <p class="text-secondary mb-0">Етапи діалогів у чаті (Messenger / Instagram). Статус за замовчуванням отримує кожна нова розмова.</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div id="cs-list" class="vstack gap-2"><div class="text-muted">Завантаження…</div></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-plus-circle me-1 text-primary"></i>Додати статус</h2>
                <form id="cs-add" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label small text-secondary mb-1">Назва</label>
                        <input name="name" class="form-control" required maxlength="100" placeholder="Напр., Чекає відповіді">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small text-secondary mb-1">Колір</label>
                        <input name="color" type="color" class="form-control form-control-color w-100" value="#0084ff">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-secondary mb-1">Іконка (bootstrap-icons)</label>
                        <input name="icon" class="form-control" maxlength="100" placeholder="bi-hourglass-split">
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label small text-secondary mb-1">Порядок</label>
                        <input name="sort_order" type="number" class="form-control" value="0" min="0" max="1000">
                    </div>
                    <div class="col-6 col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Додати</button>
                    </div>
                </form>
                <div id="cs-error" class="text-danger small mt-2 d-none"></div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        const API = '{{ route('settings.chatStatuses.store') }}';

        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

        async function loadStatuses() {
            const res = await fetch('{{ route('chatStatuses.list') }}', { headers: { 'Accept': 'application/json' } });
            const items = await res.json();
            const box = document.getElementById('cs-list');
            if (!items.length) { box.innerHTML = '<div class="text-muted">Поки немає статусів.</div>'; return; }
            box.innerHTML = items.map(s => `
                <form class="row g-2 align-items-center border rounded-3 p-2 m-0" onsubmit="return saveStatus(event, ${s.id})">
                    <div class="col-12 col-md-4 d-flex align-items-center gap-2">
                        <i class="bi ${esc(s.icon || 'bi-circle-fill')}" style="color:${esc(s.color || '#888')};font-size:1.1rem"></i>
                        <input name="name" class="form-control form-control-sm" value="${esc(s.name)}" required maxlength="100">
                    </div>
                    <div class="col-4 col-md-2">
                        <input name="color" type="color" class="form-control form-control-color form-control-sm w-100" value="${esc(s.color || '#888888')}">
                    </div>
                    <div class="col-8 col-md-2">
                        <input name="icon" class="form-control form-control-sm" value="${esc(s.icon || '')}" maxlength="100" placeholder="bi-...">
                    </div>
                    <div class="col-4 col-md-1">
                        <input name="sort_order" type="number" class="form-control form-control-sm" value="${s.sort_order ?? 0}" min="0" max="1000">
                    </div>
                    <div class="col-8 col-md-3 d-flex align-items-center justify-content-end gap-2">
                        <div class="form-check m-0 me-auto">
                            <input class="form-check-input" type="radio" name="is_default_radio" ${s.is_default ? 'checked' : ''} onchange="makeDefault(${s.id})" title="За замовчуванням">
                            <label class="form-check-label small text-secondary">типовий</label>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" title="Зберегти"><i class="bi bi-check-lg"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" ${s.is_default ? 'disabled' : ''} onclick="removeStatus(${s.id})" title="Видалити"><i class="bi bi-trash"></i></button>
                    </div>
                </form>`).join('');
        }

        function showErr(m) { const e = document.getElementById('cs-error'); e.textContent = m; e.classList.remove('d-none'); setTimeout(() => e.classList.add('d-none'), 4000); }

        function formData(form, extra = {}) {
            return JSON.stringify(Object.assign({
                name: form.name.value.trim(),
                color: form.color.value,
                icon: form.icon.value.trim() || null,
                sort_order: parseInt(form.sort_order.value || '0', 10),
            }, extra));
        }

        async function saveStatus(e, id) {
            e.preventDefault();
            const keepDefault = e.target.querySelector('input[type="radio"]').checked;
            const res = await fetch(`${API}/${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: formData(e.target, { is_default: keepDefault })
            });
            if (!res.ok) showErr('Не вдалося зберегти'); else loadStatuses();
            return false;
        }

        async function makeDefault(id) {
            const form = [...document.querySelectorAll('#cs-list form')].find(f => f.getAttribute('onsubmit')?.includes(`, ${id})`));
            if (form) {
                await fetch(`${API}/${id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: formData(form, { is_default: true })
                });
            }
            loadStatuses();
        }

        async function removeStatus(id) {
            if (!confirm('Видалити статус? Розмови з ним залишаться без статусу.')) return;
            const res = await fetch(`${API}/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) showErr(data.error || 'Не вдалося видалити'); else loadStatuses();
        }

        document.getElementById('cs-add').addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: formData(e.target)
            });
            if (!res.ok) { showErr('Не вдалося додати'); return; }
            e.target.reset();
            loadStatuses();
        });

        loadStatuses();
    </script>
    @endpush
</x-app-layout>
