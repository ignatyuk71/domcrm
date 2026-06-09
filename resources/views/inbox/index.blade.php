<x-app-layout>
    <div class="d-flex" style="height: calc(100vh - 64px); overflow: hidden;">

        {{-- ЛІВА ПАНЕЛЬ: список діалогів --}}
        <div class="border-end bg-white d-flex flex-column" style="width: 360px; min-width: 280px;">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-chat-dots-fill text-primary me-1"></i>Чат</h2>
                <button onclick="loadConversations()" class="btn btn-sm btn-light" title="Оновити"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <div id="conv-list" class="flex-grow-1 overflow-auto">
                <div class="text-center text-muted p-4">Завантаження…</div>
            </div>
        </div>

        {{-- ПРАВА ПАНЕЛЬ: діалог --}}
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
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        let activeId = null;

        const channelIcon = (ch) => ch === 'instagram'
            ? '<i class="bi bi-instagram text-danger"></i>'
            : '<i class="bi bi-messenger text-primary"></i>';

        const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

        async function loadConversations() {
            try {
                const res = await fetch('{{ route('inbox.conversations') }}', { headers: { 'Accept': 'application/json' } });
                const items = await res.json();
                const el = document.getElementById('conv-list');
                if (!items.length) { el.innerHTML = '<div class="text-center text-muted p-4">Поки немає діалогів</div>'; return; }
                el.innerHTML = items.map(c => `
                    <div class="p-3 border-bottom ${c.id === activeId ? 'bg-primary-subtle' : ''}" style="cursor:pointer" onclick="openConversation(${c.id})">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="fw-semibold text-truncate" style="max-width:200px">${esc(c.contact_name)}</div>
                            <small class="text-muted text-nowrap ms-2">${esc(c.last_at_human || '')}</small>
                        </div>
                        <div class="small text-muted d-flex align-items-center gap-1 mt-1">
                            ${channelIcon(c.channel)}
                            <span class="text-truncate" style="max-width:180px">${c.last_direction === 'out' ? 'Ви: ' : ''}${esc(c.last_text || '')}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="badge bg-light text-secondary border fw-normal">${esc(c.store)}</span>
                            ${c.unread > 0 ? `<span class="badge bg-danger rounded-pill">${c.unread}</span>` : ''}
                        </div>
                    </div>`).join('');
            } catch (e) { /* ignore poll errors */ }
        }

        async function openConversation(id) {
            activeId = id;
            const res = await fetch(`/api/inbox/conversations/${id}/messages`, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            document.getElementById('thread-empty').classList.add('d-none');
            const t = document.getElementById('thread');
            t.classList.remove('d-none'); t.classList.add('d-flex');
            document.getElementById('thread-header').innerHTML = `
                <div class="fw-bold">${esc(data.conversation.contact_name)}</div>
                <small class="text-muted">${channelIcon(data.conversation.channel)} ${esc(data.conversation.store)}</small>`;
            renderMessages(data.messages);
            loadConversations();
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
