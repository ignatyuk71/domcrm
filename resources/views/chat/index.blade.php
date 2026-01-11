<x-app-layout>
    <div class="container-fluid py-4">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-semibold">
                        Клієнти
                    </div>
                    <div class="list-group list-group-flush" id="chatCustomerList">
                        @forelse ($customers as $customer)
                            <button
                                type="button"
                                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                data-customer-id="{{ $customer->id }}"
                                data-customer-name="{{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) }}"
                            >
                                <span class="text-truncate">
                                    {{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: 'Social User' }}
                                </span>
                                <span class="badge bg-light text-muted">
                                    {{ \Carbon\Carbon::parse($customer->last_msg)->diffForHumans() }}
                                </span>
                            </button>
                        @empty
                            <div class="list-group-item text-muted">
                                Повідомлень поки немає
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <div class="fw-semibold" id="chatActiveTitle">Оберіть клієнта</div>
                        <div class="text-muted small" id="chatActiveMeta"></div>
                    </div>
                    <div class="card-body" style="min-height: 360px;">
                        <div id="chatMessages" class="d-flex flex-column gap-3">
                            <div class="text-muted">Натисніть на клієнта, щоб побачити історію.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            (() => {
                const list = document.getElementById('chatCustomerList');
                const messagesRoot = document.getElementById('chatMessages');
                const title = document.getElementById('chatActiveTitle');
                const meta = document.getElementById('chatActiveMeta');
                const baseUrl = "{{ url('/chat') }}";

                if (!list) {
                    return;
                }

                const setLoading = () => {
                    messagesRoot.innerHTML = '';
                    const note = document.createElement('div');
                    note.className = 'text-muted';
                    note.textContent = 'Завантаження...';
                    messagesRoot.appendChild(note);
                };

                const setEmpty = () => {
                    messagesRoot.innerHTML = '';
                    const note = document.createElement('div');
                    note.className = 'text-muted';
                    note.textContent = 'Повідомлень немає.';
                    messagesRoot.appendChild(note);
                };

                const renderMessages = (items) => {
                    messagesRoot.innerHTML = '';
                    if (!items.length) {
                        setEmpty();
                        return;
                    }

                    items.forEach((item) => {
                        const wrap = document.createElement('div');
                        wrap.className = 'd-flex flex-column gap-1';

                        const text = document.createElement('div');
                        text.className = 'p-3 rounded-3 bg-light';
                        text.textContent = item.text || '[без тексту]';

                        const time = document.createElement('div');
                        time.className = 'text-muted small';
                        time.textContent = item.created_at || '';

                        wrap.appendChild(text);
                        wrap.appendChild(time);
                        messagesRoot.appendChild(wrap);
                    });
                };

                const setActive = (button) => {
                    [...list.querySelectorAll('button')].forEach((el) => {
                        el.classList.remove('active');
                    });
                    button.classList.add('active');
                };

                list.addEventListener('click', (event) => {
                    const button = event.target.closest('button[data-customer-id]');
                    if (!button) {
                        return;
                    }

                    const id = button.dataset.customerId;
                    const name = button.dataset.customerName || 'Social User';

                    setActive(button);
                    title.textContent = name;
                    meta.textContent = '';
                    setLoading();

                    fetch(`${baseUrl}/${id}`)
                        .then((response) => response.json())
                        .then((data) => {
                            renderMessages(Array.isArray(data) ? data : []);
                        })
                        .catch(() => {
                            messagesRoot.innerHTML = '';
                            const note = document.createElement('div');
                            note.className = 'text-danger';
                            note.textContent = 'Не вдалося завантажити повідомлення.';
                            messagesRoot.appendChild(note);
                        });
                });
            })();
        </script>
    @endpush
</x-app-layout>
