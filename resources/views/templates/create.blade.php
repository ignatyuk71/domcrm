<x-app-layout>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Створити новий шаблон</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('templates.store') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="title" class="form-label">Назва (для менеджера)</label>
                            <input type="text" class="form-control @error('title') is-invalid @enderror"
                                   id="title" name="title" value="{{ old('title') }}" placeholder="Напр: ТТН Нова Пошта" required>
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="content" class="form-label">Текст повідомлення</label>
                            <textarea class="form-control @error('content') is-invalid @enderror"
                                      id="content" name="content" rows="6" required>{{ old('content') }}</textarea>
                            <div class="form-text text-muted d-flex align-items-center gap-2">
                                <span>Можна використовувати змінні для автопідстановки.</span>
                                <button type="button" class="btn btn-link p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#templateVarsModal">
                                    Показати список
                                </button>
                            </div>
                            @error('content') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sort_order" class="form-label">Пріоритет (Сортування)</label>
                                <input type="number" class="form-control" name="sort_order" value="0">
                                <div class="form-text">Більше число = вище у списку.</div>
                            </div>

                            <div class="col-md-6 mb-3 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                                    <label class="form-check-label" for="is_active">
                                        Активний шаблон
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('templates.index') }}" class="btn btn-light">Назад</a>
                            <button type="submit" class="btn btn-primary">Зберегти шаблон</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="templateVarsModal" tabindex="-1" aria-labelledby="templateVarsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateVarsModalLabel">Доступні змінні</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success py-1 px-2 small mb-3 d-none" data-copy-notice>
                    Скопійовано
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{name}}</code></div>
                                <div class="text-muted small">Імʼя клієнта</div>
                                <div class="small">Приклад: <strong>Іван</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{name}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{phone}}</code></div>
                                <div class="text-muted small">Телефон клієнта</div>
                                <div class="small">Приклад: <strong>+380991234567</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{phone}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{ttn}}</code></div>
                                <div class="text-muted small">Номер ТТН</div>
                                <div class="small">Приклад: <strong>20451360182751</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{ttn}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{order_id}}</code></div>
                                <div class="text-muted small">Номер замовлення</div>
                                <div class="small">Приклад: <strong>#351</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{order_id}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{amount}}</code></div>
                                <div class="text-muted small">Сума замовлення</div>
                                <div class="small">Приклад: <strong>949 грн</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{amount}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{price}}</code></div>
                                <div class="text-muted small">Сума замовлення (те саме)</div>
                                <div class="small">Приклад: <strong>949 грн</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{price}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{storage_days}}</code></div>
                                <div class="text-muted small">Кількість днів зберігання</div>
                                <div class="small">Приклад: <strong>6</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{storage_days}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{storage_days_label}}</code></div>
                                <div class="text-muted small">Кількість днів з суфіксом “дн.”</div>
                                <div class="small">Приклад: <strong>6 дн.</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{storage_days_label}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{city}}</code></div>
                                <div class="text-muted small">Місто доставки</div>
                                <div class="small">Приклад: <strong>Львів</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{city}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{warehouse}}</code></div>
                                <div class="text-muted small">Відділення/адреса</div>
                                <div class="small">Приклад: <strong>Відділення №4</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{warehouse}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{delivery_status}}</code></div>
                                <div class="text-muted small">Поточний статус доставки</div>
                                <div class="small">Приклад: <strong>Прибуло у відділення</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{delivery_status}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{order_date}}</code></div>
                                <div class="text-muted small">Дата створення</div>
                                <div class="small">Приклад: <strong>03.02.2026</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{order_date}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{status}}</code></div>
                                <div class="text-muted small">Статус замовлення</div>
                                <div class="small">Приклад: <strong>В обробці</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{status}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{items_count}}</code></div>
                                <div class="text-muted small">Кількість позицій</div>
                                <div class="small">Приклад: <strong>3</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{items_count}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{items_list}}</code></div>
                                <div class="text-muted small">Список товарів</div>
                                <div class="small">Приклад: <strong>Тапочки × 2, Шкарпетки × 1</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{items_list}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{payment_method}}</code></div>
                                <div class="text-muted small">Спосіб оплати</div>
                                <div class="small">Приклад: <strong>Післяплата</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{payment_method}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><code>@{{prepay_amount}}</code></div>
                                <div class="text-muted small">Сума передоплати</div>
                                <div class="small">Приклад: <strong>0 грн</strong></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template-var="@{{prepay_amount}}" title="Копіювати">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('templateVarsModal');
    if (!modal) return;

    const notice = modal.querySelector('[data-copy-notice]');
    const buttons = modal.querySelectorAll('[data-template-var]');
    let hideTimeout = null;

    const showNotice = (message) => {
        if (!notice) return;
        notice.textContent = message;
        notice.classList.remove('d-none');
        if (hideTimeout) {
            clearTimeout(hideTimeout);
        }
        hideTimeout = setTimeout(() => {
            notice.classList.add('d-none');
        }, 1500);
    };

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.getAttribute('data-template-var') || '';
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
                showNotice('Скопійовано');
            } catch (error) {
                console.error('Не вдалося скопіювати', error);
                showNotice('Не вдалося скопіювати');
            }
        });
    });
});
</script>
</x-app-layout>
