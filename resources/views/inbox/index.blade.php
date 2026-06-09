<x-app-layout>
    <style>
        .ib-wrap { height: calc(100vh - 64px); overflow: hidden; background: #f3f4f8; font-size: 14px; }
        .ib-col { display: flex; flex-direction: column; min-height: 0; }
        .ib-list  { width: 25%; min-width: 280px; background: #fff; border-right: 1px solid #ecedf1; }
        .ib-thread{ width: 50%; background: #f3f4f8; }
        .ib-info  { width: 25%; min-width: 270px; background: #fff; border-left: 1px solid #ecedf1; }
        @media (max-width: 1300px) { .ib-info { display: none !important; } .ib-list { width: 34%; } .ib-thread { width: 66%; } }
        @media (max-width: 900px)  { .ib-list { width: 100%; } .ib-thread { display: none !important; } }

        .ib-head { padding: 14px 16px 10px; border-bottom: 1px solid #f0f1f4; }
        .ib-title { font-weight: 800; font-size: 1.05rem; color: #0f172a; letter-spacing: -.3px; }
        .ib-iconbtn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #ecedf1; background: #fff; color: #64748b; display: inline-flex; align-items: center; justify-content: center; transition: .15s; }
        .ib-iconbtn:hover { background: #f5f6ff; color: #4f46e5; border-color: #dfe1f5; }

        .ib-search { position: relative; padding: 0 14px 8px; }
        .ib-search input { width: 100%; border: 1px solid #ecedf1; background: #f6f7f9; border-radius: 9px; padding: 7px 12px 7px 34px; font-size: .85rem; outline: none; }
        .ib-search input:focus { background: #fff; border-color: #c7cbf0; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
        .ib-search .bi-search { position: absolute; left: 26px; top: 8px; color: #94a3b8; font-size: .85rem; }

        .ib-filters { display: flex; gap: 5px; padding: 0 14px 10px; }
        .ib-chip { font-size: .76rem; padding: 4px 11px; border-radius: 18px; background: #f1f2f6; color: #64748b; border: none; cursor: pointer; transition: .15s; font-weight: 500; }
        .ib-chip:hover { background: #e9eaf0; }
        .ib-chip.active { background: #4f46e5; color: #fff; }

        .ib-convs { flex: 1; overflow-y: auto; }
        .ib-conv { display: flex; gap: 10px; padding: 9px 14px; cursor: pointer; position: relative; transition: background .12s; }
        .ib-conv:hover { background: #f7f8fa; }
        .ib-conv.active { background: #f1f2ff; }
        .ib-conv.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(#6366f1,#8b5cf6); }
        .ib-conv .meta { flex: 1; min-width: 0; }
        .ib-conv .nm { font-weight: 600; color: #1e293b; font-size: .86rem; }
        .ib-conv.unread .nm { font-weight: 800; color: #0f172a; }
        .ib-conv .pv { color: #6b7280; font-size: .8rem; line-height: 1.25; }
        .ib-conv.unread .pv { color: #334155; font-weight: 500; }
        .ib-conv .store { color: #aab2c0; font-size: .68rem; }
        .ib-time { color: #9aa3b2; font-size: .7rem; white-space: nowrap; }

        .ib-av { position: relative; flex-shrink: 0; }
        .ib-av .circle { border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: linear-gradient(135deg,#818cf8,#a78bfa); overflow: hidden; }
        .ib-av .circle img { width: 100%; height: 100%; object-fit: cover; }
        .ib-av .ch { position: absolute; right: -1px; bottom: -1px; width: 17px; height: 17px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; box-shadow: 0 0 0 1.5px #fff; }
        .ib-dot { width: 8px; height: 8px; border-radius: 50%; background: #2563eb; flex-shrink: 0; align-self: center; }

        .ib-thead { padding: 12px 18px; background: #fff; border-bottom: 1px solid #ecedf1; }
        .ib-msgs { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 3px; }
        .ib-row { display: flex; margin-bottom: 5px; }
        .ib-row.out { justify-content: flex-end; }
        .ib-bub { max-width: 70%; padding: 8px 13px; font-size: .88rem; line-height: 1.4; white-space: pre-wrap; word-break: break-word; box-shadow: 0 1px 2px rgba(16,24,40,.05); }
        .ib-bub.in  { background: #fff; color: #1e293b; border-radius: 15px 15px 15px 4px; }
        .ib-bub.out { background: linear-gradient(135deg,#6366f1,#8b5cf6); color: #fff; border-radius: 15px 15px 4px 15px; }
        .ib-bub .t { font-size: .66rem; margin-top: 3px; opacity: .7; }
        .ib-bub img { max-width: 210px; border-radius: 10px; margin-top: 4px; display: block; }

        .ib-composer { padding: 12px 16px 14px; background: #fff; border-top: 1px solid #ecedf1; position: relative; }
        .ib-box { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid #e3e6ea; border-radius: 16px; padding: 12px 16px; }
        .ib-box:focus-within { border-color: #cfd3da; }
        .ib-box-av { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #eef0f3; color: #9aa3af; font-size: 17px; }
        .ib-box-av img { width: 100%; height: 100%; object-fit: cover; }
        .ib-box input { flex: 1; border: none; background: transparent; outline: none; font-size: 1rem; color: #1c1e21; padding: 4px 0; }
        .ib-box input::placeholder { color: #8a8d91; }
        .ib-box-tools { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .ib-tool { width: 34px; height: 34px; border-radius: 50%; border: none; background: transparent; color: #1c1e21; display: inline-flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; padding: 0; transition: background .12s; }
        .ib-tool:hover { background: #f0f1f4; }
        .ib-pop { position: absolute; bottom: 56px; right: 14px; left: auto; background: #fff; border: 1px solid #e6e8ee; border-radius: 13px; box-shadow: 0 14px 36px rgba(16,24,40,.16); z-index: 50; padding: 9px; }
        .ib-emoji-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; width: 280px; }
        .ib-emoji-grid button { border: none; background: transparent; font-size: 1.25rem; padding: 4px; border-radius: 7px; cursor: pointer; }
        .ib-emoji-grid button:hover { background: #f1f2f6; }
        .ib-tpl { width: 320px; max-height: 290px; overflow-y: auto; }
        .ib-tpl-item { padding: 8px 10px; border-radius: 8px; cursor: pointer; }
        .ib-tpl-item:hover { background: #f5f6ff; }
        .ib-tpl-item .tt { font-weight: 600; font-size: .82rem; color: #0f172a; }
        .ib-tpl-item .bd { font-size: .74rem; color: #94a3b8; }

        /* права панель — блоки як у FB */
        .ib-iblock { padding: 14px 16px; border-bottom: 1px solid #f0f1f4; }
        .ib-block-title { font-weight: 700; font-size: .9rem; color: #0f172a; margin-bottom: 8px; }
        .ib-info .btn { font-size: .82rem; }

        .ib-empty { margin: auto; text-align: center; color: #94a3b8; padding: 24px; }
        .ib-empty i { font-size: 2.4rem; opacity: .35; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #d8dae2; border-radius: 8px; }
    </style>

    <div class="ib-wrap d-flex">

        {{-- 25% --}}
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
            <div id="conv-list" class="ib-convs"><div class="ib-empty">Завантаження…</div></div>
        </div>

        {{-- 50% --}}
        <div class="ib-col ib-thread">
            <div id="thread-empty" class="ib-empty m-auto"><i class="bi bi-chat-left-text d-block mb-2"></i>Обери діалог зліва</div>
            <div id="thread" class="d-none ib-col h-100">
                <div id="thread-header" class="ib-thead"></div>
                <div id="thread-messages" class="ib-msgs"></div>
                <div class="ib-composer">
                    <div id="emoji-pop" class="ib-pop d-none"><div class="ib-emoji-grid"></div></div>
                    <div id="tpl-pop" class="ib-pop d-none"><div class="ib-tpl"><div class="text-muted small p-2">Завантаження…</div></div></div>
                    <form id="reply-form" class="ib-box">
                        <span class="ib-box-av" id="composer-av"><i class="bi bi-shop"></i></span>
                        <input id="reply-input" placeholder="Відповідь у Messenger…" autocomplete="off">
                        <div class="ib-box-tools">
                            <button type="button" class="ib-tool" title="Товари / шаблони" onclick="toggleTpl()"><i class="bi bi-bag"></i></button>
                            <button type="button" class="ib-tool" title="Фото / файл" onclick="document.getElementById('file-input').click()"><i class="bi bi-paperclip"></i></button>
                            <button type="button" class="ib-tool" title="Швидкі відповіді" onclick="toggleTpl()"><i class="bi bi-chat"></i></button>
                            <button type="button" class="ib-tool" title="Емодзі" onclick="toggleEmoji()"><i class="bi bi-emoji-smile"></i></button>
                            <button type="button" class="ib-tool" title="Надіслати 👍" onclick="sendLike()"><i class="bi bi-hand-thumbs-up-fill"></i></button>
                        </div>
                        <input type="file" id="file-input" class="d-none" accept="image/*,application/pdf,.doc,.docx" onchange="sendFile(this)">
                    </form>
                    <div id="reply-error" class="text-danger small mt-2 d-none px-2"></div>
                </div>
            </div>
        </div>

        {{-- 25% --}}
        <div class="ib-col ib-info">
            <div id="info-empty" class="ib-empty m-auto"><i class="bi bi-person-circle d-block mb-2"></i>Інформація про контакт</div>
            <div id="info" class="d-none" style="overflow-y:auto"></div>
        </div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        let activeId = null, allConvs = [], filter = 'all', search = '', tplItems = [], tplLoaded = false;

        const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
        const chLabel = (ch) => ch === 'instagram' ? 'Instagram' : 'Messenger';
        const chIcon = (ch) => ch === 'instagram'
            ? '<i class="bi bi-instagram" style="color:#E1306C"></i>'
            : '<i class="bi bi-messenger" style="color:#0084FF"></i>';

        function avatar(name, url, ch, size = 42) {
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
                <div class="ib-conv ${c.id === activeId ? 'active' : ''} ${c.unread > 0 ? 'unread' : ''}" onclick="openConversation(${c.id})">
                    ${avatar(c.contact_name, c.avatar, c.channel, 42)}
                    <div class="meta">
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="nm text-truncate">${esc(c.contact_name)}</span>
                            <span class="ib-time ms-2">${esc(c.last_at_human || '')}</span>
                        </div>
                        <div class="pv text-truncate">${c.last_direction === 'out' ? 'Ви: ' : ''}${esc(c.last_text || '')}</div>
                        <div class="store text-truncate">${esc(c.store)}</div>
                    </div>
                    ${c.unread > 0 ? '<span class="ib-dot"></span>' : ''}
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
                <div class="d-flex align-items-center gap-2">
                    ${avatar(c.contact_name, c.avatar, c.channel, 38)}
                    <div>
                        <div class="fw-bold" style="font-size:.95rem">${esc(c.contact_name)}</div>
                        <small class="text-muted" style="font-size:.75rem">${chIcon(c.channel)} ${chLabel(c.channel)} · ${esc(c.store)}</small>
                    </div>
                </div>`;
            document.getElementById('reply-input').placeholder = 'Відповідь у ' + chLabel(c.channel) + '…';
            const av = document.getElementById('composer-av');
            av.innerHTML = '<i class="bi bi-shop"></i>';
            if (c.store_id) {
                const img = new Image();
                img.onload = () => { av.innerHTML = ''; av.appendChild(img); };
                img.src = 'https://graph.facebook.com/' + c.store_id + '/picture?type=square&width=80&height=80';
            }
            renderMessages(data.messages);
            renderInfo(c);
            renderConvList();
        }

        function renderInfo(c) {
            document.getElementById('info-empty').classList.add('d-none');
            const box = document.getElementById('info');
            box.classList.remove('d-none');
            box.innerHTML = `
                <div class="text-center" style="padding:18px 16px 16px; border-bottom:1px solid #f0f1f4">
                    ${avatar(c.contact_name, c.avatar, c.channel, 76)}
                    <div class="fw-bold mt-2" style="font-size:.98rem">${esc(c.contact_name)}</div>
                    <div class="text-muted" style="font-size:.76rem">${chLabel(c.channel)} · ${esc(c.store)}</div>
                </div>
                <div class="ib-iblock">
                    <div class="ib-block-title">Контактні дані</div>
                    <div class="text-muted" style="font-size:.8rem; line-height:1.5">Телефон, email і привʼязаний клієнт CRM зʼявляться тут згодом.</div>
                    <button class="btn btn-sm btn-light w-100 mt-2 border" disabled><i class="bi bi-plus-lg me-1"></i>Додати подробиці</button>
                </div>
                <div class="ib-iblock">
                    <div class="ib-block-title">Дії</div>
                    <button class="btn btn-sm btn-light w-100 mb-2 border text-start" disabled><i class="bi bi-person-plus me-2"></i>Привʼязати клієнта CRM</button>
                    <button class="btn btn-sm btn-light w-100 mb-2 border text-start" disabled><i class="bi bi-bag me-2"></i>Створити замовлення</button>
                    <button class="btn btn-sm btn-light w-100 border text-start" disabled><i class="bi bi-bookmark-star me-2"></i>Позначити як лід</button>
                </div>
                <div class="ib-iblock" style="border-bottom:none">
                    <div class="ib-block-title">Статус замовлення</div>
                    <select class="form-select form-select-sm" disabled><option>Виберіть варіант</option></select>
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

        function showErr(m) { const e = document.getElementById('reply-error'); e.textContent = m; e.classList.remove('d-none'); }

        async function sendMessage(text) {
            if (!activeId || !text) return;
            const res = await fetch(`/api/inbox/conversations/${activeId}/send`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ text })
            });
            const data = await res.json();
            if (!res.ok || !data.ok) { showErr(data.error || 'Помилка відправки'); return; }
            await openConversation(activeId);
        }

        document.getElementById('reply-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = document.getElementById('reply-input');
            const text = input.value.trim();
            if (!text) return;
            document.getElementById('reply-error').classList.add('d-none');
            input.disabled = true;
            await sendMessage(text);
            input.value = ''; input.disabled = false; input.focus();
        });

        function sendLike() { sendMessage('👍'); }

        const EMOJIS = ['😊','😂','❤️','👍','🙏','🔥','😍','🎉','👌','✅','🤝','😉','🙂','😅','💪','👋','📦','🚚','💰','❓','😎','🤔','🥰','👏','💯','🙌','😢','🤗'];
        function toggleEmoji() {
            document.getElementById('tpl-pop').classList.add('d-none');
            const p = document.getElementById('emoji-pop');
            if (!p.dataset.filled) {
                p.querySelector('.ib-emoji-grid').innerHTML = EMOJIS.map(e => `<button type="button" onclick="insertText('${e}')">${e}</button>`).join('');
                p.dataset.filled = '1';
            }
            p.classList.toggle('d-none');
        }
        function insertText(t) { const i = document.getElementById('reply-input'); i.value += t; i.focus(); }

        async function toggleTpl() {
            document.getElementById('emoji-pop').classList.add('d-none');
            const p = document.getElementById('tpl-pop');
            p.classList.toggle('d-none');
            if (!tplLoaded && !p.classList.contains('d-none')) {
                try {
                    const res = await fetch('{{ route('templates.list') }}', { headers: { 'Accept': 'application/json' } });
                    tplItems = (await res.json()).data || [];
                    p.querySelector('.ib-tpl').innerHTML = tplItems.length
                        ? tplItems.map((t, i) => `<div class="ib-tpl-item" onclick="useTpl(${i})"><div class="tt">${esc(t.title)}</div><div class="bd text-truncate">${esc(t.content)}</div></div>`).join('')
                        : '<div class="text-muted small p-2">Немає шаблонів. Додай у розділі «Шаблони».</div>';
                    tplLoaded = true;
                } catch (e) { p.querySelector('.ib-tpl').innerHTML = '<div class="text-danger small p-2">Помилка завантаження</div>'; }
            }
        }
        function useTpl(i) {
            document.getElementById('reply-input').value = tplItems[i].content;
            document.getElementById('tpl-pop').classList.add('d-none');
            document.getElementById('reply-input').focus();
        }

        async function sendFile(input) {
            if (!activeId || !input.files.length) return;
            const fd = new FormData();
            fd.append('file', input.files[0]);
            input.value = '';
            document.getElementById('reply-error').classList.add('d-none');
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/send-attachment`, {
                    method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Не вдалося надіслати');
                await openConversation(activeId);
            } catch (err) { showErr(err.message); }
        }

        async function syncHistory(btn) {
            const prev = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            try { await fetch('{{ route('inbox.sync') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } }); await loadConversations(); }
            catch (e) {} finally { btn.disabled = false; btn.innerHTML = prev; }
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.ib-tool') && !e.target.closest('.ib-pop')) {
                document.getElementById('emoji-pop')?.classList.add('d-none');
                document.getElementById('tpl-pop')?.classList.add('d-none');
            }
        });

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
