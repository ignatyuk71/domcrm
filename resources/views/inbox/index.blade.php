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
        .ib-conv-more { display: flex; justify-content: center; padding: 12px 0 16px; color: #8a8d91; }
        .ib-st-badge { font-size: .64rem; font-weight: 700; padding: 2px 7px; border-radius: 999px; line-height: 1.25; white-space: nowrap; flex-shrink: 0; }
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
        .ib-av .ch { position: absolute; right: 3px; bottom: 3px; width: 13px; height: 13px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; line-height: 1; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
        .ib-dot { width: 8px; height: 8px; border-radius: 50%; background: #2563eb; flex-shrink: 0; align-self: center; }

        .ib-thead { padding: 10px 18px; background: #fff; border-bottom: 1px solid #ecedf1; }
        .ib-th-name { font-weight: 700; font-size: 1.02rem; color: #050505; line-height: 1.25; }
        .ib-th-store { display: inline-flex; align-items: center; gap: 6px; margin-top: 3px; background: #f0f2f5; border-radius: 999px; padding: 2px 10px 2px 3px; font-size: .76rem; font-weight: 600; color: #050505; max-width: 100%; }
        .ib-th-store img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; display: block; flex-shrink: 0; }
        .ib-th-store i { font-size: .85rem; color: #65676b; margin-left: 5px; }
        .ib-th-store span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ib-status-wrap { display: inline-flex; align-items: center; gap: 7px; background: #f0f2f5; border-radius: 999px; padding: 6px 12px 6px 12px; flex-shrink: 0; }
        .ib-status-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--st, #adb5bd); flex-shrink: 0; }
        .ib-status-sel { border: none; background: transparent; outline: none; font-size: .84rem; font-weight: 600; color: #1c1e21; cursor: pointer; max-width: 170px; }
        .ib-thbtn { width: 36px; height: 36px; border-radius: 50%; border: none; background: #f0f2f5; color: #1c1e21; display: inline-flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; transition: background .12s; }
        .ib-thbtn:hover { background: #e4e6eb; }
        .ib-thbtn:disabled { opacity: .6; }
        .ib-th-pop { position: absolute; top: 42px; right: 0; background: #fff; border: 1px solid #e6e8ee; border-radius: 12px; box-shadow: 0 14px 36px rgba(16,24,40,.16); z-index: 60; min-width: 200px; padding: 6px; }
        .ib-th-pop button { display: flex; align-items: center; width: 100%; border: none; background: transparent; padding: 9px 12px; border-radius: 8px; font-size: .88rem; text-align: left; }
        .ib-th-pop button:hover { background: #f0f2f5; }
        .ib-th-pop button.danger { color: #dc3545; }
        .ib-spin { animation: ibspin 1s linear infinite; }
        @keyframes ibspin { to { transform: rotate(360deg); } }
        .ib-msgs { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; gap: 2px; background: #fff; }
        .ib-row { display: flex; margin-bottom: 1px; }
        .ib-row.out { justify-content: flex-end; }
        .ib-bub { max-width: 64%; padding: 8px 12px; font-size: .94rem; line-height: 1.38; white-space: pre-wrap; word-break: break-word; }
        .ib-bub.in  { background: #f0f0f0; color: #050505; border-radius: 18px; }
        .ib-bub.out { background: #0084ff; color: #fff; border-radius: 18px; }
        .ib-bub.media { background: transparent; padding: 0; }
        .ib-bub img { max-width: 230px; border-radius: 16px; display: block; }
        .ib-time-mini { font-size: .68rem; color: #8a8d91; margin: 2px 6px 8px; }
        .ib-time-mini.out { text-align: right; }

        .ib-composer { padding: 12px 16px 14px; background: #fff; border-top: 1px solid #ecedf1; position: relative; }
        .ib-box { display: flex; align-items: flex-start; gap: 12px; background: #fff; border: 1px solid #e3e6ea; border-radius: 16px; padding: 10px 14px; }
        .ib-box:focus-within { border-color: #cfd3da; }
        .ib-box-av { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #eef0f3; color: #9aa3af; font-size: 17px; margin-top: 2px; }
        .ib-box-av img { width: 100%; height: 100%; object-fit: cover; }
        .ib-box textarea { flex: 1; border: none; background: transparent; outline: none; font-size: 1rem; color: #1c1e21; padding: 7px 0; resize: none; min-height: 80px; max-height: 200px; overflow-y: auto; line-height: 1.4; font-family: inherit; }
        .ib-box textarea::placeholder { color: #8a8d91; }
        .ib-box-tools { display: flex; align-items: center; gap: 8px; flex-shrink: 0; align-self: flex-end; }
        .ib-tool { width: 34px; height: 34px; border-radius: 50%; border: none; background: transparent; color: #1c1e21; display: inline-flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; padding: 0; transition: background .12s; }
        .ib-tool:hover { background: #f0f1f4; }
        .ib-send-ic { color: #0084ff; }
        .ib-send-ic:hover { background: #eaf3ff; color: #0084ff; }
        .ib-send-ic .spinner-border { width: 17px; height: 17px; border-width: 2px; }
        .ib-pop { position: absolute; bottom: calc(100% - 4px); right: 14px; left: auto; background: #fff; border: 1px solid #e6e8ee; border-radius: 13px; box-shadow: 0 14px 36px rgba(16,24,40,.16); z-index: 50; padding: 9px; }
        .ib-attach-preview { padding: 4px 6px 12px; display: flex; flex-wrap: wrap; gap: 12px; }
        .ib-attach-item { position: relative; width: 68px; height: 68px; background: #f5f6f8; border: 1px solid #e3e6ea; border-radius: 12px; }
        .ib-attach-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 11px; display: block; }
        .ib-attach-file { width: 100%; height: 100%; border-radius: 11px; background: #e9ecf2; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #6b7280; }
        .ib-attach-spin { position: absolute; inset: 0; border-radius: 11px; background: rgba(233,236,242,.78); display: flex; align-items: center; justify-content: center; color: #65676b; }
        .ib-attach-x { position: absolute; top: -7px; right: -7px; width: 22px; height: 22px; border-radius: 50%; border: 2px solid #fff; background: #1c1e21; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; line-height: 1; }
        .ib-gallery { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 304px; max-height: 280px; overflow-y: auto; }
        .ib-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid transparent; }
        .ib-gallery img:hover { border-color: #0084ff; }
        .ib-modal { position: fixed; inset: 0; background: rgba(15,18,30,.55); z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .ib-modal-card { background: #fff; border-radius: 16px; width: min(880px, 96vw); max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.3); }
        .ib-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #ecedf1; }
        .ib-modal-title { font-weight: 700; font-size: 1.02rem; }
        .ib-modal-close { border: none; background: transparent; font-size: 17px; color: #6b7280; cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .ib-modal-close:hover { background: #f1f2f6; }
        .ib-modal-body { padding: 16px 18px; overflow-y: auto; }
        .ib-mgrid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .ib-mtile { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; cursor: pointer; border: 2px solid #eef0f3; background: #f7f8fa; }
        .ib-mtile img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .ib-mtile.sel { border-color: #0084ff; }
        .ib-mtile.sel::after { content: ''; position: absolute; inset: 0; background: rgba(0,132,255,.16); }
        .ib-mtile .num { position: absolute; top: 6px; right: 6px; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 11px; background: #0084ff; color: #fff; font-size: .74rem; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 1; }
        .ib-mtile:not(.sel) .num { display: none; }
        .ib-modal-foot { display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; border-top: 1px solid #ecedf1; gap: 12px; }
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
                    <div id="attach-preview" class="ib-attach-preview d-none"></div>
                    <form id="reply-form" class="ib-box">
                        <span class="ib-box-av" id="composer-av"><i class="bi bi-shop"></i></span>
                        <textarea id="reply-input" rows="1" placeholder="Відповідь у Messenger…"></textarea>
                        <div class="ib-box-tools">
                            <button type="button" class="ib-tool" title="Галерея зображень" onclick="openGalleryModal()"><i class="bi bi-bag"></i></button>
                            <button type="button" class="ib-tool" title="Фото / файл" onclick="document.getElementById('file-input').click()"><i class="bi bi-paperclip"></i></button>
                            <button type="button" class="ib-tool" title="Швидкі відповіді" onclick="toggleTpl()"><i class="bi bi-chat"></i></button>
                            <button type="button" class="ib-tool" title="Емодзі" onclick="toggleEmoji()"><i class="bi bi-emoji-smile"></i></button>
                            <button type="button" class="ib-tool" id="like-btn" title="Надіслати 👍" onclick="sendLike()"><i class="bi bi-hand-thumbs-up-fill"></i></button>
                            <button type="submit" class="ib-tool ib-send-ic d-none" id="send-btn" title="Надіслати"><i class="bi bi-send-fill"></i></button>
                        </div>
                        <input type="file" id="file-input" class="d-none" accept="image/*,application/pdf,.doc,.docx" multiple onchange="stageFile(this)">
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

        const CONV_PAGE = 25;
        let convsHasMore = false, convsLoadingMore = false;

        async function loadConversations() {
            try {
                // Оновлюємо стільки, скільки вже показано (мінімум одна пачка).
                const limit = Math.max(allConvs.length, CONV_PAGE);
                const res = await fetch('{{ route('inbox.conversations') }}?offset=0&limit=' + limit, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                allConvs = json.data || [];
                convsHasMore = !!json.has_more;
                renderConvList();
            } catch (e) {}
        }

        async function loadMoreConvs() {
            if (!convsHasMore || convsLoadingMore) return;
            convsLoadingMore = true;
            renderConvList(); // показати спінер унизу
            try {
                const res = await fetch('{{ route('inbox.conversations') }}?offset=' + allConvs.length + '&limit=' + CONV_PAGE, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                allConvs = allConvs.concat(json.data || []);
                convsHasMore = !!json.has_more;
            } catch (e) {}
            convsLoadingMore = false;
            renderConvList();
        }

        function renderConvList() {
            const el = document.getElementById('conv-list');
            let items = allConvs;
            if (filter !== 'all') items = items.filter(c => c.channel === filter);
            if (search) items = items.filter(c => (c.contact_name || '').toLowerCase().includes(search));
            if (!items.length) { el.innerHTML = '<div class="ib-empty">Нічого не знайдено</div>'; return; }
            el.innerHTML = items.map(c => {
                const st = chatStatuses.find(s => s.id === c.chat_status_id);
                const badge = st && !st.is_default
                    ? `<span class="ib-st-badge" style="background:${esc(st.color)}1f;color:${esc(st.color)}">${esc(st.name)}</span>`
                    : '';
                return `
                <div class="ib-conv ${c.id === activeId ? 'active' : ''} ${c.unread > 0 ? 'unread' : ''}" onclick="openConversation(${c.id})">
                    ${avatar(c.contact_name, c.avatar, c.channel, 48)}
                    <div class="meta">
                        <div class="d-flex align-items-center gap-1">
                            <span class="nm text-truncate">${esc(c.contact_name)}</span>
                            ${badge}
                            <span class="ib-time ms-auto">${esc(c.last_at_human || '')}</span>
                        </div>
                        <div class="pv text-truncate">${c.last_direction === 'out' ? 'Ви: ' : ''}${esc(c.last_text || '')}</div>
                        <div class="store text-truncate">${esc(c.store)}</div>
                    </div>
                    ${c.unread > 0 ? '<span class="ib-dot"></span>' : ''}
                </div>`;
            }).join('')
                + (convsLoadingMore ? '<div class="ib-conv-more"><span class="spinner-border spinner-border-sm"></span></div>' : '');
        }

        function setFilter(f, btn) {
            filter = f;
            document.querySelectorAll('.ib-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderConvList();
        }
        function onSearch(v) { search = (v || '').toLowerCase().trim(); renderConvList(); }

        let chatStatuses = [];
        async function loadChatStatuses() {
            try {
                const res = await fetch('{{ route('chatStatuses.list') }}', { headers: { 'Accept': 'application/json' } });
                chatStatuses = await res.json();
            } catch (e) {}
        }

        async function setChatStatus(val) {
            if (!activeId) return;
            const id = val ? parseInt(val, 10) : null;
            try {
                await fetch(`/api/inbox/conversations/${activeId}/status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ chat_status_id: id })
                });
                const conv = allConvs.find(x => x.id === activeId);
                if (conv) conv.chat_status_id = id;
                const st = chatStatuses.find(s => s.id === id);
                document.querySelector('.ib-status-wrap')?.style.setProperty('--st', st?.color || '#adb5bd');
                renderConvList();
            } catch (e) {}
        }

        function toggleThreadMenu(e) {
            e.stopPropagation();
            document.getElementById('th-menu')?.classList.toggle('d-none');
        }

        async function refreshConversation(btn) {
            if (!activeId) return;
            const ic = btn.querySelector('i');
            btn.disabled = true; ic.classList.add('ib-spin');
            try {
                await fetch(`/api/inbox/conversations/${activeId}/refresh`, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
                await openConversation(activeId);
                loadConversations();
            } catch (e) {}
            btn.disabled = false; ic.classList.remove('ib-spin');
        }

        async function clearChat() {
            document.getElementById('th-menu')?.classList.add('d-none');
            if (!activeId || !confirm('Очистити чат? Всі повідомлення цієї розмови буде видалено з CRM (у Facebook вони залишаться). Кнопка «оновити» зможе підтягнути їх знову.')) return;
            await fetch(`/api/inbox/conversations/${activeId}/clear`, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
            await openConversation(activeId);
            loadConversations();
        }

        async function deleteChat() {
            document.getElementById('th-menu')?.classList.add('d-none');
            if (!activeId || !confirm('Видалити чат повністю? Розмову, контакт і всі повідомлення буде видалено з бази. Якщо клієнт напише знову — чат зʼявиться як новий.')) return;
            await fetch(`/api/inbox/conversations/${activeId}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
            activeId = null;
            const t = document.getElementById('thread');
            t.classList.add('d-none'); t.classList.remove('d-flex');
            document.getElementById('thread-empty').classList.remove('d-none');
            document.getElementById('info')?.classList.add('d-none');
            document.getElementById('info-empty')?.classList.remove('d-none');
            loadConversations();
        }

        async function openConversation(id) {
            if (id !== activeId && !sendingNow) { staged = []; renderStaged(); }
            activeId = id;
            if (!chatStatuses.length) await loadChatStatuses();
            const res = await fetch(`/api/inbox/conversations/${id}/messages`, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const c = data.conversation;
            document.getElementById('thread-empty').classList.add('d-none');
            const t = document.getElementById('thread');
            t.classList.remove('d-none'); t.classList.add('d-flex');
            const curSt = chatStatuses.find(s => s.id === c.chat_status_id);
            const stOpts = '<option value="">— без статусу —</option>'
                + chatStatuses.map(s => `<option value="${s.id}" ${c.chat_status_id === s.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('');
            document.getElementById('thread-header').innerHTML = `
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2" style="min-width:0">
                        ${avatar(c.contact_name, c.avatar, c.channel, 44)}
                        <div class="ms-1" style="min-width:0">
                            <div class="ib-th-name">${esc(c.contact_name)}</div>
                            <div class="ib-th-store">${c.conn_id ? `<img src="/inbox/page-avatar/${c.conn_id}" onerror="this.remove()">` : '<i class="bi bi-shop"></i>'}<span>${esc(c.store)}</span></div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <div class="ib-status-wrap" style="--st:${curSt?.color || '#adb5bd'}">
                            <span class="ib-status-dot"></span>
                            <select class="ib-status-sel" onchange="setChatStatus(this.value)">${stOpts}</select>
                        </div>
                        <button type="button" class="ib-thbtn" onclick="refreshConversation(this)" title="Підтягнути історію з Facebook"><i class="bi bi-arrow-clockwise"></i></button>
                        <div class="position-relative">
                            <button type="button" class="ib-thbtn" onclick="toggleThreadMenu(event)" title="Дії"><i class="bi bi-three-dots"></i></button>
                            <div id="th-menu" class="ib-th-pop d-none">
                                <button type="button" onclick="clearChat()"><i class="bi bi-eraser me-2"></i>Очистити чат</button>
                                <button type="button" class="danger" onclick="deleteChat()"><i class="bi bi-trash me-2"></i>Видалити чат</button>
                            </div>
                        </div>
                    </div>
                </div>`;
            document.getElementById('reply-input').placeholder = 'Відповідь у ' + chLabel(c.channel) + '…';
            const av = document.getElementById('composer-av');
            av.innerHTML = '<i class="bi bi-shop"></i>';
            if (c.conn_id) {
                const img = new Image();
                img.onload = () => { av.innerHTML = ''; av.appendChild(img); };
                img.src = '/inbox/page-avatar/' + c.conn_id;
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
                    ${avatar(c.contact_name, c.avatar, c.channel, 82)}
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
            box.innerHTML = messages.map((m, i) => {
                const out = m.direction === 'out';
                const atts = (m.attachments || []).map(a => a.url ? `<img src="${esc(a.url)}">` : '').join('');
                const media = atts && !m.text ? ' media' : '';
                const next = messages[i + 1];
                const showTime = !next || next.direction !== m.direction;
                const time = showTime ? `<div class="ib-time-mini ${out ? 'out' : ''}">${esc(m.sent_at_human || '')}</div>` : '';
                return `<div class="ib-row ${out ? 'out' : ''}"><div class="ib-bub ${out ? 'out' : 'in'}${media}">${m.text ? esc(m.text) : ''}${atts}</div></div>${time}`;
            }).join('');
            appendPending();
            box.scrollTop = box.scrollHeight;
        }

        function showErr(m) { const e = document.getElementById('reply-error'); e.textContent = m; e.classList.remove('d-none'); }

        // Оптимістична відправка тексту: бульбашка зʼявляється миттєво,
        // POST іде у фоні; при помилці — позначка «не надіслано».
        let optSeq = 0, pendingTexts = [];

        function optBubbleHtml(p) {
            return `<div class="ib-row out" data-opt="${p.key}"><div class="ib-bub out">${esc(p.text)}</div></div>`
                + (p.failed ? '<div class="ib-time-mini out" style="color:#dc3545">не надіслано — спробуйте ще раз</div>' : '');
        }

        function appendPending() {
            const mine = pendingTexts.filter(p => p.convId === activeId);
            if (!mine.length) return;
            const box = document.getElementById('thread-messages');
            mine.forEach(p => box.insertAdjacentHTML('beforeend', optBubbleHtml(p)));
        }

        function sendTextOptimistic(text, convId) {
            if (!convId || !text) return;
            const p = { key: ++optSeq, convId, text, failed: false };
            pendingTexts.push(p);
            if (convId === activeId) {
                const box = document.getElementById('thread-messages');
                box.insertAdjacentHTML('beforeend', optBubbleHtml(p));
                box.scrollTop = box.scrollHeight;
            }
            fetch(`/api/inbox/conversations/${convId}/send`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ text })
            })
                .then(r => r.json().then(d => ({ ok: r.ok && d.ok, d })).catch(() => ({ ok: false, d: {} })))
                .catch(() => ({ ok: false, d: {} }))
                .then(({ ok, d }) => {
                    if (ok) {
                        pendingTexts = pendingTexts.filter(x => x.key !== p.key);
                        loadConversations();
                        return;
                    }
                    p.failed = true;
                    const el = document.querySelector(`[data-opt="${p.key}"]`);
                    if (el && p.convId === activeId) {
                        el.insertAdjacentHTML('afterend', '<div class="ib-time-mini out" style="color:#dc3545">не надіслано — спробуйте ще раз</div>');
                        el.parentElement.scrollTop = el.parentElement.scrollHeight;
                    }
                });
        }

        let staged = [], sendingNow = false;
        const replyTa = document.getElementById('reply-input');
        function autoGrow() {
            replyTa.style.height = 'auto';
            replyTa.style.height = Math.min(replyTa.scrollHeight, 200) + 'px';
            const has = replyTa.value.trim().length > 0 || staged.length > 0 || sendingNow;
            document.getElementById('like-btn').classList.toggle('d-none', has);
            document.getElementById('send-btn').classList.toggle('d-none', !has);
        }
        replyTa.addEventListener('input', autoGrow);
        replyTa.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('reply-form').requestSubmit();
            }
        });

        document.getElementById('reply-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sendingNow) return;
            const input = document.getElementById('reply-input');
            const text = input.value.trim();
            if (!staged.length && !text) return;
            document.getElementById('reply-error').classList.add('d-none');
            const convId = activeId;

            // Лише текст — миттєва бульбашка, нічого не блокуємо.
            if (!staged.length) {
                input.value = '';
                sendTextOptimistic(text, convId);
                input.focus(); autoGrow();
                return;
            }

            // Є вкладення — черга зі спінерами, кнопка залочена до кінця.
            sendingNow = true;
            input.disabled = true;
            const sBtn = document.getElementById('send-btn');
            sBtn.disabled = true; sBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            let okAll = true;
            while (staged.length) {
                const item = staged[0];
                item.sending = true; renderStaged();
                const ok = item.kind === 'file'
                    ? await uploadAndSendFile(item.file, convId)
                    : await sendGalleryImage(item.id, convId);
                if (!ok) { item.sending = false; okAll = false; renderStaged(); break; }
                staged.shift(); renderStaged();
            }
            if (okAll && convId === activeId) { await openConversation(convId); }
            if (okAll && text) { sendTextOptimistic(text, convId); input.value = ''; }
            sendingNow = false;
            input.disabled = false; sBtn.disabled = false; sBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
            input.focus(); autoGrow();
        });

        function sendLike() { if (!activeId || sendingNow) return; sendTextOptimistic('👍', activeId); }

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
        function insertText(t) { const i = document.getElementById('reply-input'); i.value += t; i.focus(); autoGrow(); }

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
            autoGrow();
        }

        function renderStaged() {
            const box = document.getElementById('attach-preview');
            if (!staged.length) { box.classList.add('d-none'); box.innerHTML = ''; autoGrow(); return; }
            box.classList.remove('d-none');
            box.innerHTML = staged.map((s, i) => `
                <div class="ib-attach-item">
                    ${s.url ? `<img src="${s.url}">` : '<span class="ib-attach-file"><i class="bi bi-file-earmark-text"></i></span>'}
                    ${s.sending
                        ? '<span class="ib-attach-spin"><span class="spinner-border spinner-border-sm"></span></span>'
                        : `<button type="button" class="ib-attach-x" onclick="removeStaged(${i})" title="Прибрати"><i class="bi bi-x"></i></button>`}
                </div>`).join('');
            autoGrow();
        }

        function removeStaged(i) {
            staged.splice(i, 1);
            renderStaged();
        }

        function stageFile(input) {
            if (!input.files.length) return;
            for (const f of input.files) {
                const item = { kind: 'file', file: f, url: null };
                staged.push(item);
                if (f.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = ev => { item.url = ev.target.result; renderStaged(); };
                    reader.readAsDataURL(f);
                }
            }
            document.getElementById('reply-error').classList.add('d-none');
            input.value = '';
            renderStaged();
        }

        async function uploadAndSendFile(file, convId) {
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await fetch(`/api/inbox/conversations/${convId}/send-attachment`, {
                    method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Не вдалося надіслати');
                return true;
            } catch (err) { showErr(err.message); return false; }
        }

        async function sendGalleryImage(id, convId) {
            try {
                const res = await fetch(`/api/inbox/conversations/${convId}/send-gallery`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Не вдалося надіслати');
                return true;
            } catch (err) { showErr(err.message); return false; }
        }

        // --- Галерея у модальному вікні з мультивибором ---
        let galleryItems = [], gallerySelected = [];
        function ensureGalleryModal() {
            if (document.getElementById('gallery-modal')) return;
            const el = document.createElement('div');
            el.id = 'gallery-modal';
            el.className = 'ib-modal d-none';
            el.innerHTML = `
                <div class="ib-modal-card">
                    <div class="ib-modal-head">
                        <div class="ib-modal-title">Галерея — оберіть фото</div>
                        <button type="button" class="ib-modal-close" onclick="closeGalleryModal()"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="ib-modal-body"><div id="gallery-modal-grid" class="ib-mgrid"></div></div>
                    <div class="ib-modal-foot">
                        <div class="text-muted small" id="gallery-count">Нічого не вибрано</div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light btn-sm" onclick="closeGalleryModal()">Скасувати</button>
                            <button type="button" class="btn btn-primary btn-sm" id="gallery-send-btn" onclick="addSelectedGallery()" disabled>Додати</button>
                        </div>
                    </div>
                </div>`;
            el.addEventListener('click', ev => { if (ev.target === el) closeGalleryModal(); });
            document.body.appendChild(el);
        }
        async function openGalleryModal() {
            if (!activeId) { showErr('Спершу відкрийте діалог'); return; }
            ensureGalleryModal();
            gallerySelected = [];
            updateGalleryFooter();
            document.getElementById('gallery-modal').classList.remove('d-none');
            const grid = document.getElementById('gallery-modal-grid');
            grid.innerHTML = '<div class="text-muted small p-3" style="grid-column:1/-1">Завантаження…</div>';
            try {
                const res = await fetch('/api/saved-files', { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                galleryItems = (json.data || json || []).filter(f => f.type === 'image' && f.url);
                grid.innerHTML = galleryItems.length
                    ? galleryItems.map(it => `<div class="ib-mtile" data-id="${it.id}" onclick="toggleGalleryTile(${it.id})"><img src="${esc(it.url)}" loading="lazy"><span class="num"></span></div>`).join('')
                    : '<div class="text-muted small p-3" style="grid-column:1/-1">Галерея порожня. Додайте фото в розділі «Галерея».</div>';
            } catch (e) { grid.innerHTML = '<div class="text-danger small p-3" style="grid-column:1/-1">Помилка завантаження</div>'; }
        }
        function closeGalleryModal() { document.getElementById('gallery-modal')?.classList.add('d-none'); }
        function toggleGalleryTile(id) {
            const i = gallerySelected.indexOf(id);
            if (i >= 0) gallerySelected.splice(i, 1); else gallerySelected.push(id);
            document.querySelectorAll('#gallery-modal-grid .ib-mtile').forEach(tile => {
                const pos = gallerySelected.indexOf(Number(tile.dataset.id));
                tile.classList.toggle('sel', pos >= 0);
                tile.querySelector('.num').textContent = pos >= 0 ? (pos + 1) : '';
            });
            updateGalleryFooter();
        }
        function updateGalleryFooter() {
            const n = gallerySelected.length;
            const c = document.getElementById('gallery-count');
            if (c) c.textContent = n ? ('Вибрано: ' + n) : 'Нічого не вибрано';
            const btn = document.getElementById('gallery-send-btn');
            if (btn) { btn.disabled = n === 0; btn.textContent = n ? ('Додати (' + n + ')') : 'Додати'; }
        }
        function addSelectedGallery() {
            if (!gallerySelected.length) return;
            for (const id of gallerySelected) {
                const it = galleryItems.find(g => g.id === id);
                if (it) staged.push({ kind: 'gallery', id: it.id, url: it.url });
            }
            closeGalleryModal();
            renderStaged();
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
            if (!e.target.closest('.ib-thbtn') && !e.target.closest('.ib-th-pop')) {
                document.getElementById('th-menu')?.classList.add('d-none');
            }
        });

        document.getElementById('conv-list').addEventListener('scroll', function () {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 120) loadMoreConvs();
        });

        loadChatStatuses().then(loadConversations);
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
