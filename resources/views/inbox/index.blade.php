<x-app-layout>
    <div class="d-flex" style="height: calc(100vh - 64px); overflow: hidden;">

        {{-- ЛІВА ПАНЕЛЬ: список діалогів --}}
        <div class="border-end bg-white d-flex flex-column" style="width: 340px; min-width: 300px;">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-chat-dots-fill text-primary me-1"></i>Чат</h2>
                <div class="d-flex gap-1">
                    <button onclick="syncHistory(this)" class="btn btn-sm btn-outline-primary" title="Імпортувати історію з Facebook/Instagram"><i class="bi bi-cloud-download"></i></button>
                    <button onclick="loadConversations()" class="btn btn-sm btn-light" title="Оновити"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>
            <div id="conv-list" class="flex-grow-1 overflow-auto">
                <div class="text-center text-muted p-4">Завантаження…</div>
            </div>
        </div>

        {{-- ЦЕНТР: діалог --}}
        <div class="flex-grow-1 d-flex flex-column bg-light position-relative">
            <div id="thread-empty" class="m-auto text-center text-muted">
                <i class="bi bi-chat-left-text fs-1 d-block mb-2 opacity-50"></i>
                Обери діалог зліва
            </div>
            <div id="thread" class="d-none flex-column h-100 w-100">
                <div id="thread-header" class="p-3 border-bottom bg-white"></div>
                <div id="thread-messages" class="flex-grow-1 overflow-auto p-3"></div>
                <div class="p-3 border-top bg-white">
                    <form id="reply-form" class="d-flex gap-2">
                        <input id="reply-input" class="form-control" placeholder="Напишіть відповідь…" autocomplete="off">
                        <button class="btn btn-primary px-3" type="submit"><i class="bi bi-send"></i></button>
                    </form>
                    <div id="reply-error" class="text-danger small mt-1 d-none"></div>
                </div>
            </div>
        </div>

        {{-- ПРАВА ПАНЕЛЬ: інформація про контакт --}}
        <div class="border-start bg-white d-none d-lg-flex flex-column" style="width: 300px;" id="info-panel">
            <div id="info-empty" class="m-auto text-center text-muted p-3">
                <i class="bi bi-person-circle fs-1 d-block mb-2 opacity-50"></i>
                Інформація про контакт
            </div>
            <div id="info" class="d-none overflow-auto"></div>
        </div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        let activeId = null;

        const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

        function channelLabel(ch) { return ch === 'instagram' ? 'Instagram' : 'Messenger'; }

        function avatarHtml(name, url, channel, size = 46) {
            const letter = esc((name || '?').trim().charAt(0).toUpperCase() || '?');
            const chIcon = channel === 'instagram'
                ? '<i class="bi bi-instagram" style="color:#E1306C"></i>'
                : '<i class="bi bi-messenger" style="color:#0084FF"></i>';
            const img = url
                ? `<img src="${esc(url)}" onerror="this.style.display='none'" style="position:absolute;inset:0;width:${size}px;height:${size}px;border-radius:50%;object-fit:cover">`
                : '';
            return `<span class="position-relative flex-shrink-0 d-inline-flex align-items-center justify-content-center" style="width:${size}px;height:${size}px;border-radius:50%;background:#6366f1;color:#fff;font-weight:600">
                ${letter}${img}
                <span class="position-absolute d-flex align-items-center justify-content-center" style="bottom:-3px;right:-3px;width:18px;height:18px;background:#fff;border-radius:50%;font-size:11px;box-shadow:0 0 0 1px #e5e7eb">${chIcon}</span>
            </span>`;
        }

        async function loadConversations() {
            try {
                const res = await fetch('{{ route('inbox.conversations') }}', { headers: { 'Accept': 'application/json' } });
                const items = await res.json();
                const el = document.getElementById('conv-list');
                if (!items.length) { el.innerHTML = '<div class="text-center text-muted p-4">Поки немає діалогів</div>'; return; }
                el.innerHTML = items.map(c => `
                    <div class="d-flex gap-2 p-2 px-3 border-bottom align-items-center ${c.id === activeId ? 'bg-primary-subtle' : ''}" style="cursor:pointer" onclick="openConversation(${c.id})">
                        ${avatarHtml(c.contact_name, c.avatar, c.channel, 46)}
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold text-truncate">${esc(c.contact_name)}</span>
                                <small class="text-muted text-nowrap ms-2">${esc(c.last_at_human || '')}</small>
                            </div>
                            <div class="small text-muted text-truncate">${c.last_direction === 'out' ? 'Ви: ' : ''}${esc(c.last_text || '')}</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-secondary text-truncate">${esc(c.store)}</small>
                                ${c.unread > 0 ? `<span class="badge bg-danger rounded-pill">${c.unread}</span>` : ''}
                            </div>
                        </div>
                    </div>`).join('');
            } catch (e) { /* ignore poll errors */ }
        }

        async function syncHistory(btn) {
            const prev = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            try {
                const res = await fetch('{{ route('inbox.sync') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
                await res.json();
                await loadConversations();
            } catch (e) { /* ignore */ } finally {
                btn.disabled = false;
                btn.innerHTML = prev;
            }
        }

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
                    ${avatarHtml(c.contact_name, c.avatar, c.channel, 40)}
                    <div>
                        <div class="fw-bold">${esc(c.contact_name)}</div>
                        <small class="text-muted">${channelLabel(c.channel)} • ${esc(c.store)}</small>
                    </div>
                </div>`;
            renderMessages(data.messages);
            renderInfo(c);
            loadConversations();
        }

        function renderInfo(c) {
            document.getElementById('info-empty').classList.add('d-none');
            const box = document.getElementById('info');
            box.classList.remove('d-none');
            box.innerHTML = `
                <div class="p-4 text-center border-bottom">
                    ${avatarHtml(c.contact_name, c.avatar, c.channel, 72)}
                    <div class="fw-bold mt-2">${esc(c.contact_name)}</div>
                    <div class="small text-muted">${channelLabel(c.channel)} • ${esc(c.store)}</div>
                </div>
                <div class="p-3">
                    <div class="text-uppercase text-muted fw-bold mb-2" style="font-size:.7rem; letter-spacing:.5px">Контакт</div>
                    <div class="text-muted small">Тут зʼявляться деталі: телефон, привʼязаний клієнт CRM, замовлення, нотатки.</div>
                </div>`;
        }

        function renderMessages(messages) {
            const box = document.getElementById('thread-messages');
            box.innerHTML = messages.map(m => {
                const out = m.direction === 'out';
                const atts = (m.attachments || []).map(a => a.url
                    ? `<div><img src="${a.url}" style="max-width:220px;border-radius:8px" class="mt-1"></div>` : '').join('');
                return `<div class="d-flex mb-2 ${out ? 'justify-content-end' : 'justify-content-start'}">
                    <div class="p-2 px-3 rounded-3 shadow-sm ${out ? 'bg-primary text-white' : 'bg-white border'}" style="max-width:70%; white-space:pre-wrap; word-break:break-word">${m.text ? esc(m.text) : ''}${atts}<div class="small ${out ? 'text-white-50' : 'text-muted'} mt-1" style="font-size:.7rem">${esc(m.sent_at_human || '')}</div></div>
                </div>`;
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
