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
                            <div class="form-text text-muted">
                                Використовуйте <b>@{{ttn}}</b>, <b>@{{name}}</b>, <b>@{{price}}</b> для підстановки даних при копіюванні.
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
</x-app-layout>
