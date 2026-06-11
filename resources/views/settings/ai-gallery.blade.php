<x-app-layout>
    <div class="container-fluid px-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase fw-bold mb-2">Налаштування CRM</span>
                <h1 class="h3 fw-bold mb-1"><i class="bi bi-images me-2 text-primary"></i>Галерея ШІ</h1>
                <p class="text-secondary mb-0">Колажі та фото кольорів, які агент надсилає клієнтам. Група = модельна лінійка.</p>
            </div>
            <button class="btn btn-primary fw-semibold" onclick="createGroup()"><i class="bi bi-plus-lg me-1"></i>Нова група</button>
        </div>

        <div id="gallery-groups"><div class="text-muted">Завантаження…</div></div>
    </div>

    <style>
        .aig-chip { display: inline-flex; align-items: center; gap: 6px; background: #f1f3f6; border: 1px solid #e6e8ee; border-radius: 999px; padding: 4px 12px; font-size: .82rem; }
        .aig-chip .x { border: none; background: none; color: #94a3b8; padding: 0; line-height: 1; cursor: pointer; }
        .aig-chip .x:hover { color: #dc3545; }
        .aig-nophoto { font-size: .64rem; }
        .aig-photo { border: 1px solid #e6e8ee; border-radius: 12px; overflow: hidden; background: #fff; position: relative; width: 200px; display: flex; flex-direction: column; }
        .aig-photo img { width: 100%; height: 140px; object-fit: cover; display: block; background: #f5f6f8; }
        .aig-photo .ft { display: flex; align-items: center; justify-content: space-between; padding: 5px 8px; border-top: 1px solid #f0f2f5; }
        .aig-photo .tbadge { position: absolute; top: 6px; left: 6px; font-size: .66rem; }
        .aig-marks { border-top: 1px solid #f0f2f5; padding: 8px 10px 10px; display: flex; flex-direction: column; gap: 5px; font-size: .84rem; flex: 1; }
        .aig-marks .hint { font-size: .68rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .aig-marks label { display: flex; align-items: center; gap: 7px; margin: 0; cursor: pointer; }
        .aig-mini { width: 24px; height: 24px; border: none; background: #f1f3f6; border-radius: 6px; color: #475467; font-size: .75rem; display: inline-flex; align-items: center; justify-content: center; }
        .aig-mini:hover { background: #e2e5ea; }
        .aig-mini.danger:hover { background: #fde8e8; color: #dc3545; }
        .aig-search { position: relative; }
        .aig-drop { position: absolute; top: 100%; left: 0; right: 0; z-index: 30; background: #fff; border: 1px solid #e6e8ee; border-radius: 10px; box-shadow: 0 12px 30px rgba(16,24,40,.14); max-height: 260px; overflow-y: auto; }
        .aig-drop button { display: block; width: 100%; text-align: left; border: none; background: none; padding: 8px 12px; font-size: .85rem; }
        .aig-drop button:hover { background: #f5f6ff; }
        .aig-checks label { font-size: .85rem; margin-right: 14px; }
    </style>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        const BASE = '{{ url('settings/ai-gallery') }}';
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        let groups = [], openId = null;

        async function api(method, url, body, isForm = false) {
            const opts = { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } };
            if (body) {
                if (isForm) { opts.body = body; }
                else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
            }
            const res = await fetch(url, opts);
            if (!res.ok) throw new Error('Помилка запиту');
            return res.json();
        }

        async function loadData() {
            groups = await api('GET', BASE + '/data');
            render();
        }

        function photoType(p) {
            if (!p.product_ids.length) return ['не розмічено', 'text-bg-warning'];
            return p.product_ids.length === 1 ? ['колір', 'text-bg-success'] : ['колаж', 'text-bg-primary'];
        }

        function render() {
            const box = document.getElementById('gallery-groups');
            if (!groups.length) {
                box.innerHTML = '<div class="alert alert-info border-0 shadow-sm rounded-4">Груп ще немає. Створи першу — наприклад «Вуличні пухнасті тапки».</div>';
                return;
            }
            box.innerHTML = groups.map(g => {
                const open = g.id === openId;
                return `
                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body p-4 ${open ? 'pb-3' : 'py-3'}">
                        <div class="d-flex align-items-center justify-content-between" style="cursor:pointer" onclick="toggleGroup(${g.id})">
                            <h2 class="h6 fw-bold mb-0"><i class="bi bi-collection me-2 text-primary"></i>${esc(g.name)}</h2>
                            <div class="d-flex align-items-center gap-3">
                                <span class="small text-secondary">${g.products.length} товарів · ${g.photos.length} фото</span>
                                <i class="bi bi-chevron-${open ? 'up' : 'down'} text-secondary"></i>
                            </div>
                        </div>
                        ${open ? renderGroupBody(g) : ''}
                    </div>
                </div>`;
            }).join('');
        }

        function renderGroupBody(g) {
            const chips = g.products.map(p => `
                <span class="aig-chip" title="${esc(p.label)}">${esc(p.short)}
                    ${p.has_photo ? '' : '<span class="badge text-bg-warning aig-nophoto">без фото</span>'}
                    <button class="x" title="Прибрати з групи" onclick="event.stopPropagation(); detachProduct(${g.id}, ${p.id})"><i class="bi bi-x-lg"></i></button>
                </span>`).join('');

            const photos = g.photos.map(p => {
                const [label, cls] = photoType(p);
                return `
                <div class="aig-photo">
                    <span class="badge ${cls} tbadge">${label}</span>
                    <img src="${esc(p.url)}" loading="lazy" alt="">
                    <div class="aig-marks">
                        <span class="hint">Хто на фото — відміть:</span>
                        ${g.products.length ? g.products.map(pr => `
                            <label><input type="checkbox" class="form-check-input m-0" ${p.product_ids.includes(pr.id) ? 'checked' : ''}
                                onchange="toggleMark(${p.id}, ${pr.id}, this.checked)"> ${esc(pr.short)}</label>`).join('')
                            : '<span class="small text-secondary">Спершу додай товари в групу</span>'}
                    </div>
                    <div class="ft">
                        <span class="d-flex gap-1">
                            <button class="aig-mini" title="Вище" onclick="movePhoto(${p.id}, 'up')"><i class="bi bi-arrow-up"></i></button>
                            <button class="aig-mini" title="Нижче" onclick="movePhoto(${p.id}, 'down')"><i class="bi bi-arrow-down"></i></button>
                        </span>
                        <button class="aig-mini danger" title="Видалити фото" onclick="deletePhoto(${p.id})"><i class="bi bi-trash"></i></button>
                    </div>
                </div>`;
            }).join('');

            return `
                <hr class="my-3">
                <div class="small text-secondary fw-semibold mb-2">Товари групи</div>
                <div class="aig-search mb-2" style="max-width:420px">
                    <input class="form-control form-control-sm" placeholder="Додати товар: пошук по SKU або назві…"
                        oninput="searchProducts(${g.id}, this)" onclick="event.stopPropagation()">
                    <div id="drop-${g.id}" class="aig-drop d-none"></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">${chips || '<span class="small text-secondary">Поки порожньо.</span>'}</div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small text-secondary fw-semibold">Фото групи <span class="text-muted fw-normal">(№1 — головний колаж, його агент шле першим)</span></span>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="document.getElementById('file-${g.id}').click()"><i class="bi bi-upload me-1"></i>Завантажити фото</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="renameGroup(${g.id})" title="Перейменувати"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteGroup(${g.id})" title="Видалити групу"><i class="bi bi-trash"></i></button>
                    </div>
                    <input type="file" id="file-${g.id}" class="d-none" accept="image/*" multiple onchange="uploadPhotos(${g.id}, this)">
                </div>
                <div class="d-flex flex-wrap gap-3">${photos || '<span class="small text-secondary">Фото ще не завантажені.</span>'}</div>`;
        }

        function toggleGroup(id) { openId = openId === id ? null : id; render(); }

        async function createGroup() {
            const name = prompt('Назва групи (модельна лінійка):');
            if (!name || !name.trim()) return;
            const res = await api('POST', BASE + '/groups', { name: name.trim() });
            openId = res.id;
            loadData();
        }

        async function renameGroup(id) {
            const g = groups.find(x => x.id === id);
            const name = prompt('Нова назва групи:', g?.name || '');
            if (!name || !name.trim()) return;
            await api('PATCH', BASE + '/groups/' + id, { name: name.trim() });
            loadData();
        }

        async function deleteGroup(id) {
            if (!confirm('Видалити групу разом з її фото?')) return;
            await api('DELETE', BASE + '/groups/' + id);
            openId = null;
            loadData();
        }

        let searchTimer = null;
        function searchProducts(groupId, input) {
            clearTimeout(searchTimer);
            const q = input.value.trim();
            const drop = document.getElementById('drop-' + groupId);
            if (q.length < 2) { drop.classList.add('d-none'); return; }
            searchTimer = setTimeout(async () => {
                const items = await api('GET', BASE + '/product-search?q=' + encodeURIComponent(q));
                const g = groups.find(x => x.id === groupId);
                const inGroup = new Set((g?.products || []).map(p => p.id));
                const list = items.filter(i => !inGroup.has(i.id));
                drop.innerHTML = list.length
                    ? list.map(i => `<button onclick="attachProduct(${groupId}, ${i.id})">${esc(i.label)}</button>`).join('')
                    : '<div class="small text-secondary p-2">Нічого не знайдено.</div>';
                drop.classList.remove('d-none');
            }, 250);
        }

        async function attachProduct(groupId, productId) {
            await api('POST', BASE + '/groups/' + groupId + '/products', { product_id: productId });
            loadData();
        }

        async function detachProduct(groupId, productId) {
            await api('DELETE', BASE + '/groups/' + groupId + '/products/' + productId);
            loadData();
        }

        async function uploadPhotos(groupId, input) {
            if (!input.files.length) return;
            const fd = new FormData();
            for (const f of input.files) fd.append('files[]', f);
            await api('POST', BASE + '/groups/' + groupId + '/photos', fd, true);
            input.value = '';
            loadData();
        }

        async function toggleMark(photoId, productId, checked) {
            const g = groups.find(x => x.id === openId);
            const p = g?.photos.find(x => x.id === photoId);
            if (!p) return;
            const ids = new Set(p.product_ids);
            checked ? ids.add(productId) : ids.delete(productId);
            await api('PATCH', BASE + '/photos/' + photoId, { product_ids: [...ids] });
            loadData();
        }

        async function movePhoto(photoId, dir) {
            await api('PATCH', BASE + '/photos/' + photoId, { move: dir });
            loadData();
        }

        async function deletePhoto(photoId) {
            if (!confirm('Видалити це фото?')) return;
            await api('DELETE', BASE + '/photos/' + photoId);
            loadData();
        }

        document.addEventListener('click', () => document.querySelectorAll('.aig-drop').forEach(d => d.classList.add('d-none')));

        loadData();
    </script>
    @endpush
</x-app-layout>
