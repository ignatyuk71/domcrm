<x-app-layout>
    <style>
        .kb-page { height: calc(100vh - 64px); display: flex; flex-direction: column; background: #f3f4f8; font-size: 14px; }
        .kb-head { padding: 13px 22px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
                   background: #fff; border-bottom: 1px solid #ecedf1; }
        .kb-title { font-weight: 800; font-size: 1.12rem; color: #0f172a; letter-spacing: -.3px; display: flex; align-items: center; gap: 9px; }
        .kb-title i { color: #6366f1; }
        .kb-actions { display: flex; align-items: center; gap: 14px; }
        .kb-hint { color: #94a3b8; font-size: .82rem; }
        .kb-hint i { color: #b6bbe6; }
        .kb-btn { border: 1px solid #ecedf1; background: #fff; color: #475569; border-radius: 9px; padding: 7px 14px;
                  font-size: .85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: .15s; }
        .kb-btn:hover { background: #f5f6ff; color: #4f46e5; border-color: #dfe1f5; }

        .kb-board { flex: 1; min-height: 0; display: flex; gap: 14px; padding: 16px 22px 18px; overflow-x: auto; overflow-y: hidden; align-items: stretch; }
        .kb-col { flex: 0 0 296px; width: 296px; display: flex; flex-direction: column; min-height: 0;
                  background: #eceef3; border-radius: 16px; transition: background .12s, outline .12s; outline: 2px solid transparent; }
        .kb-col.drop { background: #e7e9ff; outline: 2px dashed #b9c0ff; }
        .kb-col-head { padding: 13px 15px 11px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .kb-dot { width: 9px; height: 9px; border-radius: 50%; flex: none; box-shadow: 0 0 0 3px rgba(0,0,0,.04); }
        .kb-col-name { font-weight: 700; color: #1e293b; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kb-count { margin-left: auto; background: #fff; color: #64748b; font-size: .78rem; font-weight: 700;
                    min-width: 24px; text-align: center; padding: 2px 8px; border-radius: 999px; flex: none; }
        .kb-col-body { flex: 1; min-height: 0; overflow-y: auto; padding: 2px 10px 12px; display: flex; flex-direction: column; gap: 9px; }
        .kb-col-body::-webkit-scrollbar { width: 7px; }
        .kb-col-body::-webkit-scrollbar-thumb { background: #d4d8e2; border-radius: 4px; }

        .kb-card { background: #fff; border: 1px solid #eceef3; border-radius: 13px; padding: 11px 12px; cursor: grab;
                   box-shadow: 0 1px 2px rgba(16,24,40,.04); transition: box-shadow .15s, transform .12s, border-color .15s; position: relative; }
        .kb-card:hover { box-shadow: 0 6px 18px rgba(16,24,40,.10); transform: translateY(-1px); border-color: #dfe1f5; }
        .kb-card.dragging { opacity: .45; cursor: grabbing; }
        .kb-card-top { display: flex; align-items: center; gap: 9px; }
        .kb-av { width: 32px; height: 32px; border-radius: 50%; flex: none; position: relative; overflow: hidden;
                 background: linear-gradient(135deg,#818cf8,#6366f1); color: #fff; font-weight: 700; font-size: .85rem;
                 display: flex; align-items: center; justify-content: center; }
        .kb-av img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .kb-name { font-weight: 700; color: #0f172a; font-size: .87rem; line-height: 1.25; min-width: 0; flex: 1;
                   overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .kb-ch { flex: none; font-size: .95rem; line-height: 1; }
        .kb-ai { font-size: .68rem; font-weight: 700; color: #6d28d9; background: #f3eefe; padding: 1px 6px; border-radius: 6px; margin-left: 4px; vertical-align: middle; }
        .kb-snip { color: #64748b; font-size: .82rem; line-height: 1.35; margin-top: 7px;
                   display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .kb-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 9px; }
        .kb-chip { display: inline-flex; align-items: center; gap: 4px; font-size: .73rem; font-weight: 600;
                   background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 7px; }
        .kb-chip i { font-size: .78rem; }
        .kb-chip-red { background: #fdeaea; color: #c0392b; }
        .kb-chip-green { background: #e7f6ec; color: #1e7e44; }
        .kb-card-bot { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; }
        .kb-time { color: #9aa0a6; font-size: .76rem; }
        .kb-open { color: #6366f1; font-size: .78rem; font-weight: 600; opacity: 0; transition: .15s; display: inline-flex; align-items: center; }
        .kb-card:hover .kb-open { opacity: 1; }
        .kb-empty { color: #b6bcc7; font-size: .82rem; text-align: center; padding: 18px 0; }

        .kb-loading { display: flex; align-items: center; justify-content: center; width: 100%; color: #94a3b8; gap: 9px; }
        .kb-spin { width: 22px; height: 22px; border: 3px solid #dfe3ec; border-top-color: #6366f1; border-radius: 50%; animation: kbspin .7s linear infinite; }
        @keyframes kbspin { to { transform: rotate(360deg); } }

        @media (max-width: 700px) { .kb-head { padding: 12px 14px; } .kb-hint { display: none; } .kb-board { padding: 14px; } }
    </style>

    <div class="kb-page">
        <div class="kb-head">
            <div class="kb-title"><i class="bi bi-kanban-fill"></i> Дошка замовлень</div>
            <div class="kb-actions">
                <span class="kb-hint"><i class="bi bi-arrows-move"></i> Перетягни картку, щоб змінити статус</span>
                <button class="kb-btn" onclick="loadBoard()"><i class="bi bi-arrow-clockwise"></i> Оновити</button>
            </div>
        </div>
        <div class="kb-board" id="kb-board">
            <div class="kb-loading"><span class="kb-spin"></span> Завантаження…</div>
        </div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        const BOARD = document.getElementById('kb-board');
        let dragId = null, dragFrom = null, dragged = false;

        function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

        function chIcon(ch) {
            return ch === 'instagram'
                ? '<i class="bi bi-instagram kb-ch" style="color:#E1306C"></i>'
                : '<i class="bi bi-messenger kb-ch" style="color:#0084ff"></i>';
        }

        function avatar(name, url) {
            const ch = (name || '?').trim().charAt(0).toUpperCase();
            const img = url ? `<img src="${esc(url)}" onerror="this.remove()">` : '';
            return `<div class="kb-av">${img}<span>${esc(ch)}</span></div>`;
        }

        function renderCard(c, statusKey) {
            const phone = c.phone ? `<span class="kb-chip"><i class="bi bi-telephone"></i> ${esc(c.phone)}</span>` : '';
            const iban = c.needs_iban ? `<span class="kb-chip kb-chip-red"><i class="bi bi-bank"></i> Потрібен IBAN</span>` : '';
            const handled = c.handled ? `<span class="kb-chip kb-chip-green"><i class="bi bi-check2-circle"></i> оформлено</span>` : '';
            const ai = c.is_ai_order ? '<span class="kb-ai">ШІ</span>' : '';
            return `
                <div class="kb-card" draggable="true" data-id="${c.id}"
                     ondragstart="cardDrag(event, ${c.id}, '${statusKey}')" ondragend="cardDragEnd(this)"
                     onclick="openChat(${c.id})">
                    <div class="kb-card-top">
                        ${avatar(c.name, c.avatar)}
                        <span class="kb-name">${esc(c.name)}${ai}</span>
                        ${chIcon(c.channel)}
                    </div>
                    ${c.snippet ? `<div class="kb-snip">${esc(c.snippet)}</div>` : ''}
                    ${(phone || iban || handled) ? `<div class="kb-chips">${phone}${iban}${handled}</div>` : ''}
                    <div class="kb-card-bot">
                        <span class="kb-time">${esc(c.time || '')}</span>
                        <span class="kb-open">Відкрити чат <i class="bi bi-arrow-right-short"></i></span>
                    </div>
                </div>`;
        }

        function renderCol(col) {
            const key = col.id == null ? '' : col.id;
            const cards = col.cards.length
                ? col.cards.map(c => renderCard(c, key)).join('')
                : '<div class="kb-empty">Порожньо</div>';
            return `
                <div class="kb-col" data-status="${key}" data-count="${col.count}"
                     ondragover="colOver(event, this)" ondragleave="colLeave(this)" ondrop="colDrop(event, this)">
                    <div class="kb-col-head">
                        <span class="kb-dot" style="background:${esc(col.color)}"></span>
                        <span class="kb-col-name">${esc(col.name)}</span>
                        <span class="kb-count">${col.count}</span>
                    </div>
                    <div class="kb-col-body">${cards}</div>
                </div>`;
        }

        async function loadBoard() {
            BOARD.innerHTML = '<div class="kb-loading"><span class="kb-spin"></span> Завантаження…</div>';
            try {
                const res = await fetch('{{ route('inbox.boardData') }}', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                BOARD.innerHTML = data.columns.map(renderCol).join('');
            } catch (e) {
                BOARD.innerHTML = '<div class="kb-loading">Не вдалося завантажити. <button class="kb-btn ms-2" onclick="loadBoard()">Спробувати ще</button></div>';
            }
        }

        function cardDrag(e, id, from) {
            dragId = id; dragFrom = String(from); dragged = true;
            e.dataTransfer.effectAllowed = 'move';
            e.currentTarget.classList.add('dragging');
        }
        function cardDragEnd(el) { el.classList.remove('dragging'); setTimeout(() => { dragged = false; }, 60); }

        function colOver(e, col) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; col.classList.add('drop'); }
        function colLeave(col) { col.classList.remove('drop'); }

        function bumpCount(statusKey, delta) {
            const col = BOARD.querySelector(`.kb-col[data-status="${statusKey}"]`);
            if (!col) return;
            const n = Math.max(0, parseInt(col.dataset.count || '0', 10) + delta);
            col.dataset.count = n;
            col.querySelector('.kb-count').textContent = n;
        }

        async function colDrop(e, col) {
            e.preventDefault();
            col.classList.remove('drop');
            const to = col.dataset.status;
            if (dragId == null || to === dragFrom) { dragId = null; return; }

            const card = BOARD.querySelector(`.kb-card[data-id="${dragId}"]`);
            const body = col.querySelector('.kb-col-body');
            if (card && body) {
                const empty = body.querySelector('.kb-empty');
                if (empty) empty.remove();
                card.setAttribute('ondragstart', `cardDrag(event, ${dragId}, '${to}')`);
                body.prepend(card);
            }
            bumpCount(dragFrom, -1);
            bumpCount(to, 1);

            const movedId = dragId, fromKey = dragFrom;
            dragId = null; dragFrom = null;
            try {
                await fetch(`/api/inbox/conversations/${movedId}/status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                    body: JSON.stringify({ chat_status_id: to === '' ? null : parseInt(to, 10) }),
                });
            } catch (err) {
                // відкат лічильників, якщо сервер не прийняв
                bumpCount(to, -1); bumpCount(fromKey, 1);
            }
        }

        function openChat(id) {
            if (dragged) return;
            window.location = '{{ route('inbox.index') }}?conv=' + id;
        }

        loadBoard();
    </script>
    @endpush
</x-app-layout>
