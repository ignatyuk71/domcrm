<x-app-layout>
    <style>
        .ib-wrap { height: calc(100vh - 64px); overflow: hidden; background: #f3f4f8; }
        .ib-col { display: flex; flex-direction: column; min-height: 0; }
        .ib-list  { width: 25%; min-width: 300px; background: #fff; border-right: 1px solid #ecedf1; }
        .ib-thread{ width: 45%; background: #f3f4f8; }
        .ib-info  { width: 30%; min-width: 280px; background: #fff; border-left: 1px solid #ecedf1; }
        @media (max-width: 1300px) { .ib-info { display: none !important; } .ib-list { width: 36%; } .ib-thread { width: 64%; } }
        @media (max-width: 900px)  { .ib-list { width: 100%; } .ib-thread { display: none !important; } }

        .ib-head { padding: 16px 18px 12px; border-bottom: 1px solid #f0f1f4; }
        .ib-title { font-weight: 800; font-size: 1.15rem; color: #0f172a; letter-spacing: -.3px; }
        .ib-iconbtn { width: 34px; height: 34px; border-radius: 9px; border: 1px solid #ecedf1; background: #fff; color: #64748b; display: inline-flex; align-items: center; justify-content: center; transition: .15s; }
        .ib-iconbtn:hover { background: #f5f6ff; color: #4f46e5; border-color: #dfe1f5; }

        .ib-search { position: relative; padding: 0 16px 10px; }
        .ib-search input { width: 100%; border: 1px solid #ecedf1; background: #f7f8fa; border-radius: 10px; padding: 9px 12px 9px 36px; font-size: .9rem; outline: none; transition: .15s; }
        .ib-search input:focus { background: #fff; border-color: #c7cbf0; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
        .ib-search .bi-search { position: absolute; left: 28px; top: 9px; color: #94a3b8; }

        .ib-filters { display: flex; gap: 6px; padding: 0 16px 12px; }
        .ib-chip { font-size: .8rem; padding: 5px 13px; border-radius: 20px; background: #f1f2f6; color: #64748b; border: none; cursor: pointer; transition: .15s; font-weight: 500; }
        .ib-chip:hover { background: #e9eaf0; }
        .ib-chip.active { background: #4f46e5; color: #fff; }

        .ib-convs { flex: 1; overflow-y: auto; }
        .ib-conv { display: flex; gap: 12px; padding: 12px 16px; cursor: pointer; position: relative; transition: background .12s; border-bottom: 1px solid #f6f7f9; }
        .ib-conv:hover { background: #fafbfd; }
        .ib-conv.active { background: #f4f5ff; }
        .ib-conv.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(#6366f1,#8b5cf6); }
        .ib-conv .meta { flex: 1; min-width: 0; }
        .ib-conv .nm { font-weight: 600; color: #0f172a; font-size: .92rem; }
        .ib-conv .pv { color: #64748b; font-size: .84rem; }
        .ib-conv .store { color: #94a3b8; font-size: .72rem; }
        .ib-time { color: #94a3b8; font-size: .72rem; white-space: nowrap; }

        .ib-av { position: relative; flex-shrink: 0; }
        .ib-av .circle { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: linear-gradient(135deg,#818cf8,#a78bfa); overflow: hidden; }
        .ib-av .circle img { width: 100%; height: 100%; object-fit: cover; }
        .ib-av .ch { position: absolute; right: -2px; bottom: -2px; width: 19px; height: 19px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; box-shadow: 0 0 0 1.5px #fff; }
        .ib-unread { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: #ef4444; color: #fff; font-size: .72rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }

        .ib-thead { padding: 14px 20px; background: #fff; border-bottom: 1px solid #ecedf1; }
        .ib-msgs { flex: 1; overflow-y: auto; padding: 22px 26px; display: flex; flex-direction: column; gap: 4px; }
        .ib-row { display: flex; margin-bottom: 6px; }
        .ib-row.out { justify-content: flex-end; }
        .ib-bub { max-width: 72%; padding: 9px 14px; font-size: .9rem; line-height: 1.4; white-space: pre-wrap; word-break: break-word; box-shadow: 0 1px 2px rgba(16,24,40,.05); }
        .ib-bub.in  { background: #fff; color: #1e293b; border-radius: 16px 16px 16px 5px; }
        .ib-bub.out { background: linear-gradient(135deg,#6366f1,#8b5cf6); color: #fff; border-radius: 16px 16px 5px 16px; }
        .ib-bub .t { font-size: .68rem; margin-top: 4px; opacity: .7; }
        .ib-bub img { max-width: 220px; border-radius: 10px; margin-top: 4px; display: block; }

        .ib-composer { padding: 14px 18px; background: #fff; border-top: 1px solid #ecedf1; }
        .ib-composer .box { display: flex; align-items: center; gap: 8px; background: #f5f6fa; border: 1px solid #ecedf1; border-radius: 24px; padding: 4px 6px 4px 16px; }
        .ib-composer input { flex: 1; border: none; background: transparent; outline: none; font-size: .92rem; padding: 8px 0; }
        .ib-send { width: 40px; height: 40px; border-radius: 50%; border: none; background: linear-gradient(135deg,#6366f1,#8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; transition: .15s; }
        .ib-send:hover { filter: brightness(1.08); transform: scale(1.05); }
        .ib-send:disabled { opacity: .5; }

        .ib-empty { margin: auto; text-align: center; color: #94a3b8; padding: 24px; }
        .ib-empty i { font-size: 2.6rem; opacity: .35; }
        .ib-sec-title { text-transform: uppercase; font-size: .68rem; letter-spacing: .6px; color: #94a3b8; font-weight: 700; }
        .ib-convs::-webkit-scrollbar, .ib-msgs::-webkit-scrollbar, .ib-info-body::-webkit-scrollbar { width: 7px; }
        .ib-convs::-webkit-scrollbar-thumb, .ib-msgs::-webkit-scrollbar-thumb, .ib-info-body::-webkit-scrollbar-thumb { background: #d8dae2; border-radius: 8px; }
    </style>

    <div class="ib-wrap d-flex">

        {{-- 25% — список діалогів --}}
        <div class="ib-col ib-list">
            <div class="ib-head d-flex align-items-center justify-content-between">
                <span class="ib-title"><i class="bi bi-chat-dots-fill text-primary me-1"></i>Чат</span>
                <div class="d-flex gap-2">
                    <button onclick="syncHistory(this)" class="ib-iconbtn" title="Імпортувати історію"><i class="bi bi-cloud-arrow-down"></i></button>
                    <button onclick="loadConversations()" class="ib-iconbtn" title="Оновити"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>
            <div class="ib-search">
                <i class="bi bi-search"></i>
                <input id="search" placeholder="Пошук за іменем…" autocomplete="off" oninput="onSearch(this.value)">
            </div>
            <div class="ib-filters">
                <button class="ib-chip active" data-f="all" onclick="setFilter('all', this)">Усі</button>
                <button class="ib-chip" data-f="facebook" onclick="setFilter('facebook', this)"><i class="bi bi-messenger"></i> Messenger</button>
                <button class="ib-chip" data-f="instagram" onclick="setFilter('instagram', this)"><i class="bi bi-instagram"></i> Instagram</button>
            </div>
            <div id="conv-list" class="ib-convs">
                <div class="ib-empty">Завантаження…</div>
            </div>
        </div>

        {{-- 45% — діалог --}}
        <div class="ib-col ib-thread">
            <div id="thread-empty" class="ib-empty m-auto">
                <i class="bi bi-chat-left-text d-block mb-2"></i>
                Обери діалог зліва
            </div>
            <div id="thread" class="d-none ib-col h-100">
                <div id="thread-header" class="ib-thead"></div>
                <div id="thread-messages" class="ib-msgs"></div>
                <div class="ib-composer">
                    <form id="reply-form" class="box">
                        <input id="reply-input" placeholder="Напишіть відповідь…" autocomplete="off">
                        <button class="ib-send" type="submit"><i class="bi bi-send-fill"></i></button>
                    </form>
                    <div id="reply-error" class="text-danger small mt-2 d-none px-2"></div>
                </div>
            </div>
        </div>

        {{-- 30% — інформація --}}
        <div class="ib-col ib-info">
            <div id="info-empty" class="ib-empty m-auto">
                <i class="bi bi-person-circle d-block mb-2"></i>
                Інформація про контакт
            </div>
            <div id="info" class="d-none ib-info-body" style="overflow-y:auto"></div>
        </div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        let activeId = null, allConvs = [], filter = 'all', search = '';

        const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
        const chLabel = (ch) => ch === 'instagram' ? 'Instagram' : 'Messenger';
        const chIcon = (ch) => ch === 'instagram'
            ? '<i class="bi bi-instagram" style="color:#E1306C"></i>'
            : '<i class="bi bi-messenger" style="color:#0084FF"></i>';

        function avatar(name, url, ch, size = 48) {
            const letter = esc((name || '?').trim().charAt(0).toUpperCase() || '?');
            const inner = url ? `<img src="${esc(url)}" onerror="this.remove()">` : letter;
            return `<span class="ib-av"><span class="circle" style="width:${size}px;height:${size}px;font-size:${size/2.4}px">${inner}</span><span class="ch">${chIcon(ch)}</span></span>`;
        }

        async function loadConversations() {
            try {
                const res = await fetch('{{ route('inbox.conversations') }}', { headers: { 'Accept': 'application/json' } });
                allConvs = await res.json();
                renderConvList();
            } catch (e) {}
        }

        function renderConvList() {
            const el = document.getElementById('conv-list');
            let items = allConvs;
            if (filter !== 'all') items = items.filter(c => c.channel === filter);
            if (search) items = items.filter(c => (c.contact_name || '').toLowerCase().includes(search));
            if (!items.length) { el.innerHTML = '<div class="ib-empty">Нічого не знайдено</div>'; return; }
            el.innerHTML = items.map(c => `
                <div class="ib-conv ${c.id === activeId ? 'active' : ''}" onclick="openConversation(${c.id})">
                    ${avatar(c.contact_name, c.avatar, c.channel, 48)}
                    <div class="meta">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="nm text-truncate">${esc(c.contact_name)}</span>
                            <span class="ib-time ms-2">${esc(c.last_at_human || '')}</span>
                        </div>
                        <div class="pv text-truncate">${c.last_direction === 'out' ? '<span class="text-secondary">Ви: </span>' : ''}${esc(c.last_text || '')}</div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="store text-truncate">${esc(c.store)}</span>
                            ${c.unread > 0 ? `<span class="ib-unread">${c.unread}</span>` : ''}
                        </div>
                    </div>
                </div>`).join('');
        }

        function setFilter(f, btn) {
            filter = f;
            document.querySelectorAll('.ib-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderConvList();
        }
        function onSearch(v) { search = (v || '').toLowerCase().trim(); renderConvList(); }

        async function openConversation(id) {
            activeId = id;
            const res = await fetch(`/api/inbox/conversations/${id}/messages`, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const c = data.conversation;

            document.getElementById('thread-empty').classList.add('d-none');
            const t = document.getElementById('thread');
            t.classList.remove('d-none'); t.classList.add('d-flex');
            document.getElementById('thread-header').innerHTML = `
                <div class="d-flex align-items-center gap-3">
                    ${avatar(c.contact_name, c.avatar, c.channel, 44)}
                    <div>
                        <div class="fw-bold" style="font-size:1.02rem">${esc(c.contact_name)}</div>
                        <small class="text-muted">${chIcon(c.channel)} ${chLabel(c.channel)} · ${esc(c.store)}</small>
                    </div>
                </div>`;
            renderMessages(data.messages);
            renderInfo(c);
            renderConvList();
        }

        function renderInfo(c) {
            document.getElementById('info-empty').classList.add('d-none');
            const box = document.getElementById('info');
            box.classList.remove('d-none');
            box.innerHTML = `
                <div class="text-center p-4 border-bottom">
                    ${avatar(c.contact_name, c.avatar, c.channel, 88)}
                    <div class="fw-bold mt-3" style="font-size:1.05rem">${esc(c.contact_name)}</div>
                    <div class="d-flex justify-content-center gap-2 mt-2">
                        <span class="badge rounded-pill" style="background:#eef0ff;color:#4f46e5">${chLabel(c.channel)}</span>
                        <span class="badge rounded-pill bg-light text-secondary border">${esc(c.store)}</span>
                    </div>
                </div>
                <div class="p-4">
                    <div class="ib-sec-title mb-2">Контакт</div>
                    <div class="text-muted small mb-4">Телефон, привʼязаний клієнт CRM, замовлення, нотатки — зʼявляться тут згодом.</div>
                    <div class="ib-sec-title mb-2">Дії</div>
                    <button class="btn btn-sm btn-outline-secondary w-100 mb-2" disabled><i class="bi bi-person-plus me-1"></i>Привʼязати клієнта</button>
                    <button class="btn btn-sm btn-outline-secondary w-100" disabled><i class="bi bi-bag me-1"></i>Створити замовлення</button>
                </div>`;
        }

        function renderMessages(messages) {
            const box = document.getElementById('thread-messages');
            box.innerHTML = messages.map(m => {
                const out = m.direction === 'out';
                const atts = (m.attachments || []).map(a => a.url ? `<img src="${esc(a.url)}">` : '').join('');
                return `<div class="ib-row ${out ? 'out' : ''}"><div class="ib-bub ${out ? 'out' : 'in'}">${m.text ? esc(m.text) : ''}${atts}<div class="t">${esc(m.sent_at_human || '')}</div></div></div>`;
            }).join('');
            box.scrollTop = box.scrollHeight;
        }

        document.getElementById('reply-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!activeId) return;
            const input = document.getElementById('reply-input');
            const text = input.value.trim();
            if (!text) return;
            const errEl = document.getElementById('reply-error'); errEl.classList.add('d-none');
            input.disabled = true;
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/send`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ text })
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Помилка відправки');
                input.value = '';
                await openConversation(activeId);
            } catch (err) {
                errEl.textContent = err.message; errEl.classList.remove('d-none');
            } finally {
                input.disabled = false; input.focus();
            }
        });

        async function syncHistory(btn) {
            const prev = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            try {
                await fetch('{{ route('inbox.sync') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
                await loadConversations();
            } catch (e) {} finally {
                btn.disabled = false; btn.innerHTML = prev;
            }
        }

        loadConversations();
        setInterval(() => {
            loadConversations();
            if (activeId) {
                fetch(`/api/inbox/conversations/${activeId}/messages`, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json()).then(d => renderMessages(d.messages)).catch(() => {});
            }
        }, 6000);
    </script>
    @endpush
</x-app-layout>
