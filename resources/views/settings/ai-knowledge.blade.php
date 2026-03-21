<x-app-layout>
    <style>
        .kb-page {
            max-width: 1480px;
        }

        .kb-card {
            border: 1px solid #d9e1f0;
            border-radius: 16px;
            box-shadow: 0 10px 24px -20px rgba(15, 23, 42, 0.35);
            background: #fff;
        }

        .kb-stat {
            border: 1px solid #d9e1f0;
            border-radius: 14px;
            background: #fff;
            padding: .95rem 1rem;
        }

        .kb-stat .label {
            color: #5b6d8b;
            font-size: .77rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .kb-stat .value {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.1;
            margin-top: .35rem;
        }

        .kb-section-title {
            font-size: 1.15rem;
            font-weight: 800;
            margin: 0;
            color: #162039;
        }

        .kb-section-subtitle {
            margin: .35rem 0 0;
            color: #60708d;
            font-size: .95rem;
        }

        .kb-table th {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #60708d;
            border-top: 0;
            white-space: nowrap;
        }

        .kb-table td {
            vertical-align: middle;
        }

        .kb-badge {
            padding: .34rem .62rem;
            font-size: .73rem;
            border-radius: 999px;
            font-weight: 700;
        }
    </style>

    <div class="py-3 py-md-4 px-3 px-md-4">
        <div class="container-fluid kb-page">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-1 fw-bold text-dark">База знань AI</h1>
                    <p class="text-muted mb-0">Робоча адмінка для таблиць `chat_ai_topics`, `chat_ai_topic_keywords`, `chat_ai_topic_products`, `chat_ai_topic_media`, `chat_ai_response_rules`.</p>
                </div>
                <a href="{{ route('settings.ai.index') }}" class="btn btn-outline-secondary">Повернутись у Система AI</a>
            </div>

            @if(session('success'))
                <div class="alert alert-success mb-3">{{ session('success') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger mb-3">
                    <div class="fw-bold mb-1">Є помилки у формі:</div>
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="row g-3 mb-4">
                <div class="col-6 col-lg-2">
                    <div class="kb-stat">
                        <div class="label">AI статус</div>
                        <div class="value">{{ $settings->enabled ? 'ON' : 'OFF' }}</div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="kb-stat">
                        <div class="label">Теми</div>
                        <div class="value">{{ $stats['topics_total'] }}</div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="kb-stat">
                        <div class="label">Ключові слова</div>
                        <div class="value">{{ $stats['keywords_total'] }}</div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="kb-stat">
                        <div class="label">Товари</div>
                        <div class="value">{{ $stats['linked_products_total'] }}</div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="kb-stat">
                        <div class="label">Медіа</div>
                        <div class="value">{{ $stats['media_total'] }}</div>
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="kb-stat">
                        <div class="label">Сценарії</div>
                        <div class="value">{{ $stats['rules_total'] }}</div>
                    </div>
                </div>
            </section>

            <section class="kb-card p-3 p-md-4 mb-4" id="topics">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="kb-section-title">Теми (`chat_ai_topics`)</h2>
                        <p class="kb-section-subtitle">Групи контексту для AI: домашні, резинові, дитячі тощо.</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.ai.knowledge.topics.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-12 col-xl-3">
                        <input type="text" name="name" class="form-control" placeholder="Назва теми" required>
                    </div>
                    <div class="col-12 col-xl-5">
                        <input type="text" name="instruction" class="form-control" placeholder="Інструкція для AI (коротко)">
                    </div>
                    <div class="col-6 col-xl-2">
                        <input type="number" name="priority" class="form-control" placeholder="Пріоритет" value="100" min="0" max="10000" required>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" checked id="new-topic-active">
                            <label class="form-check-label" for="new-topic-active">Активна</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Додати тему</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table kb-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Назва</th>
                                <th>Інструкція</th>
                                <th>Пріоритет</th>
                                <th>Статус</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topics as $topic)
                                <tr>
                                    <td>{{ $topic->id }}</td>
                                    <td colspan="4">
                                        <form method="POST" action="{{ route('settings.ai.knowledge.topics.update', $topic) }}" class="row g-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-12 col-xl-3">
                                                <input type="text" name="name" class="form-control form-control-sm" value="{{ $topic->name }}" required>
                                            </div>
                                            <div class="col-12 col-xl-5">
                                                <input type="text" name="instruction" class="form-control form-control-sm" value="{{ $topic->instruction }}">
                                            </div>
                                            <div class="col-6 col-xl-2">
                                                <input type="number" name="priority" class="form-control form-control-sm" value="{{ $topic->priority }}" min="0" max="10000" required>
                                            </div>
                                            <div class="col-6 col-xl-2">
                                                <div class="form-check form-switch mt-1">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $topic->is_active ? 'checked' : '' }}>
                                                    <label class="form-check-label small">Активна</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Оновити</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('settings.ai.knowledge.topics.destroy', $topic) }}" onsubmit="return confirm('Видалити тему?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Теми ще не додані.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="kb-card p-3 p-md-4 mb-4" id="keywords">
                <h2 class="kb-section-title mb-1">Ключові слова (`chat_ai_topic_keywords`)</h2>
                <p class="kb-section-subtitle mb-3">Позитивні/негативні слова, за якими AI визначає контекст.</p>

                <form method="POST" action="{{ route('settings.ai.knowledge.keywords.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-12 col-xl-3">
                        <select name="topic_id" class="form-select" required>
                            <option value="">Оберіть тему</option>
                            @foreach($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-xl-4">
                        <input type="text" name="phrase" class="form-control" placeholder="Фраза" required>
                    </div>
                    <div class="col-6 col-xl-2">
                        <select name="match_type" class="form-select" required>
                            <option value="positive">positive</option>
                            <option value="negative">negative</option>
                        </select>
                    </div>
                    <div class="col-6 col-xl-1">
                        <input type="number" name="weight" class="form-control" value="100" min="1" max="10000" required>
                    </div>
                    <div class="col-6 col-xl-1">
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                        </div>
                    </div>
                    <div class="col-6 col-xl-1">
                        <button type="submit" class="btn btn-primary w-100">+</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table kb-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Тема</th>
                                <th>Фраза</th>
                                <th>Тип</th>
                                <th>Вага</th>
                                <th>Статус</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($keywords as $keyword)
                                <tr>
                                    <td>{{ $keyword->id }}</td>
                                    <td>{{ $keyword->topic?->name }}</td>
                                    <td colspan="4">
                                        <form method="POST" action="{{ route('settings.ai.knowledge.keywords.update', $keyword) }}" class="row g-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-12 col-xl-3">
                                                <select name="topic_id" class="form-select form-select-sm" required>
                                                    @foreach($topics as $topic)
                                                        <option value="{{ $topic->id }}" {{ (int) $keyword->topic_id === (int) $topic->id ? 'selected' : '' }}>
                                                            {{ $topic->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12 col-xl-4">
                                                <input type="text" name="phrase" class="form-control form-control-sm" value="{{ $keyword->phrase }}" required>
                                            </div>
                                            <div class="col-4 col-xl-2">
                                                <select name="match_type" class="form-select form-select-sm" required>
                                                    <option value="positive" {{ $keyword->match_type === 'positive' ? 'selected' : '' }}>positive</option>
                                                    <option value="negative" {{ $keyword->match_type === 'negative' ? 'selected' : '' }}>negative</option>
                                                </select>
                                            </div>
                                            <div class="col-4 col-xl-1">
                                                <input type="number" name="weight" class="form-control form-control-sm" value="{{ $keyword->weight }}" min="1" max="10000" required>
                                            </div>
                                            <div class="col-4 col-xl-2">
                                                <div class="form-check form-switch mt-1">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $keyword->is_active ? 'checked' : '' }}>
                                                    <label class="form-check-label small">Активне</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Оновити</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('settings.ai.knowledge.keywords.destroy', $keyword) }}" onsubmit="return confirm('Видалити ключове слово?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Ключових слів ще немає.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="kb-card p-3 p-md-4 mb-4" id="topic-products">
                <h2 class="kb-section-title mb-1">Привʼязані товари (`chat_ai_topic_products`)</h2>
                <p class="kb-section-subtitle mb-3">Які товари AI використовує в кожній темі.</p>

                <form method="POST" action="{{ route('settings.ai.knowledge.topicProducts.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-12 col-xl-3">
                        <select name="topic_id" class="form-select" required>
                            <option value="">Оберіть тему</option>
                            @foreach($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-xl-6">
                        <select name="product_id" class="form-select" required>
                            <option value="">Оберіть товар</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">
                                    {{ $product->title }} @if($product->sku) ({{ $product->sku }}) @endif @if(!is_null($product->sale_price)) — {{ $product->sale_price }} грн @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4 col-xl-1">
                        <input type="number" name="sort_order" class="form-control" value="0" min="0" max="10000" required>
                    </div>
                    <div class="col-4 col-xl-1">
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                        </div>
                    </div>
                    <div class="col-4 col-xl-1">
                        <button type="submit" class="btn btn-primary w-100">+</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table kb-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Тема</th>
                                <th>Товар</th>
                                <th>Порядок</th>
                                <th>Статус</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topicProducts as $item)
                                <tr>
                                    <td>{{ $item->id }}</td>
                                    <td>{{ $item->topic?->name }}</td>
                                    <td>{{ $item->product?->title }}</td>
                                    <td colspan="2">
                                        <form method="POST" action="{{ route('settings.ai.knowledge.topicProducts.update', $item) }}" class="row g-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-12 col-xl-4">
                                                <select name="topic_id" class="form-select form-select-sm" required>
                                                    @foreach($topics as $topic)
                                                        <option value="{{ $topic->id }}" {{ (int) $item->topic_id === (int) $topic->id ? 'selected' : '' }}>
                                                            {{ $topic->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12 col-xl-5">
                                                <select name="product_id" class="form-select form-select-sm" required>
                                                    @foreach($products as $product)
                                                        <option value="{{ $product->id }}" {{ (int) $item->product_id === (int) $product->id ? 'selected' : '' }}>
                                                            {{ $product->title }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-4 col-xl-1">
                                                <input type="number" name="sort_order" class="form-control form-control-sm" value="{{ $item->sort_order }}" min="0" max="10000" required>
                                            </div>
                                            <div class="col-4 col-xl-2">
                                                <div class="form-check form-switch mt-1">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $item->is_active ? 'checked' : '' }}>
                                                    <label class="form-check-label small">Активне</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Оновити</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('settings.ai.knowledge.topicProducts.destroy', $item) }}" onsubmit="return confirm('Видалити привʼязку товару?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Привʼязаних товарів ще немає.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="kb-card p-3 p-md-4 mb-4" id="media">
                <h2 class="kb-section-title mb-1">Медіа тем (`chat_ai_topic_media`)</h2>
                <p class="kb-section-subtitle mb-3">Колажі, палітри, size chart, promo та звичайні зображення для тем.</p>

                <form method="POST" action="{{ route('settings.ai.knowledge.media.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-12 col-xl-3">
                        <select name="topic_id" class="form-select" required>
                            <option value="">Оберіть тему</option>
                            @foreach($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-xl-3">
                        <select name="saved_file_id" class="form-select">
                            <option value="">Файл з галереї (необовʼязково)</option>
                            @foreach($savedFiles as $file)
                                <option value="{{ $file->id }}">{{ $file->filename }} @if($file->type) ({{ $file->type }}) @endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-xl-2">
                        <input type="text" name="label" class="form-control" placeholder="Назва медіа" required>
                    </div>
                    <div class="col-6 col-xl-2">
                        <select name="media_type" class="form-select" required>
                            <option value="image">image</option>
                            <option value="size_chart">size_chart</option>
                            <option value="palette">palette</option>
                            <option value="promo">promo</option>
                            <option value="collage">collage</option>
                        </select>
                    </div>
                    <div class="col-6 col-xl-2">
                        <input type="url" name="url" class="form-control" placeholder="URL (необовʼязково)">
                    </div>
                    <div class="col-4 col-xl-1">
                        <input type="number" name="sort_order" class="form-control" value="0" min="0" max="10000" required>
                    </div>
                    <div class="col-4 col-xl-1">
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                        </div>
                    </div>
                    <div class="col-4 col-xl-1">
                        <button type="submit" class="btn btn-primary w-100">+</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table kb-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Тема</th>
                                <th>Медіа</th>
                                <th>Тип</th>
                                <th>Порядок</th>
                                <th>Статус</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topicMedia as $media)
                                <tr>
                                    <td>{{ $media->id }}</td>
                                    <td>{{ $media->topic?->name }}</td>
                                    <td>
                                        <div class="small fw-semibold">{{ $media->label }}</div>
                                        <div class="small text-muted">file: {{ $media->saved_file_id ?: '—' }}</div>
                                        <div class="small text-truncate" style="max-width: 260px;">{{ $media->url ?: ($media->savedFile?->url ?: '—') }}</div>
                                    </td>
                                    <td colspan="3">
                                        <form method="POST" action="{{ route('settings.ai.knowledge.media.update', $media) }}" class="row g-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-12 col-xl-3">
                                                <select name="topic_id" class="form-select form-select-sm" required>
                                                    @foreach($topics as $topic)
                                                        <option value="{{ $topic->id }}" {{ (int) $media->topic_id === (int) $topic->id ? 'selected' : '' }}>
                                                            {{ $topic->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12 col-xl-3">
                                                <select name="saved_file_id" class="form-select form-select-sm">
                                                    <option value="">Без файлу</option>
                                                    @foreach($savedFiles as $file)
                                                        <option value="{{ $file->id }}" {{ (int) $media->saved_file_id === (int) $file->id ? 'selected' : '' }}>
                                                            {{ $file->filename }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12 col-xl-2">
                                                <input type="text" name="label" class="form-control form-control-sm" value="{{ $media->label }}" required>
                                            </div>
                                            <div class="col-6 col-xl-2">
                                                <select name="media_type" class="form-select form-select-sm" required>
                                                    @foreach(['image','size_chart','palette','promo','collage'] as $type)
                                                        <option value="{{ $type }}" {{ $media->media_type === $type ? 'selected' : '' }}>{{ $type }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-6 col-xl-2">
                                                <input type="url" name="url" class="form-control form-control-sm" value="{{ $media->url }}">
                                            </div>
                                            <div class="col-4 col-xl-1">
                                                <input type="number" name="sort_order" class="form-control form-control-sm" value="{{ $media->sort_order }}" min="0" max="10000" required>
                                            </div>
                                            <div class="col-4 col-xl-2">
                                                <div class="form-check form-switch mt-1">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $media->is_active ? 'checked' : '' }}>
                                                    <label class="form-check-label small">Активне</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Оновити</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('settings.ai.knowledge.media.destroy', $media) }}" onsubmit="return confirm('Видалити медіа?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Медіа ще не додано.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="kb-card p-3 p-md-4" id="rules">
                <h2 class="kb-section-title mb-1">Сценарії (`chat_ai_response_rules`)</h2>
                <p class="kb-section-subtitle mb-3">Що робить AI в типових кейсах: ціна, розмір, фото, handoff, конфлікт.</p>

                <form method="POST" action="{{ route('settings.ai.knowledge.rules.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-12 col-xl-2">
                        <input type="text" name="code" class="form-control" placeholder="code (price_request)" required>
                    </div>
                    <div class="col-12 col-xl-3">
                        <input type="text" name="title" class="form-control" placeholder="Назва правила" required>
                    </div>
                    <div class="col-12 col-xl-4">
                        <input type="text" name="instruction" class="form-control" placeholder="Інструкція для AI" required>
                    </div>
                    <div class="col-4 col-xl-1">
                        <input type="number" name="priority" class="form-control" value="100" min="0" max="10000" required>
                    </div>
                    <div class="col-4 col-xl-1">
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                        </div>
                    </div>
                    <div class="col-4 col-xl-1">
                        <button type="submit" class="btn btn-primary w-100">+</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table kb-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Code</th>
                                <th>Правило</th>
                                <th>Пріоритет</th>
                                <th>Статус</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rules as $rule)
                                <tr>
                                    <td>{{ $rule->id }}</td>
                                    <td><code>{{ $rule->code }}</code></td>
                                    <td colspan="3">
                                        <form method="POST" action="{{ route('settings.ai.knowledge.rules.update', $rule) }}" class="row g-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-12 col-xl-2">
                                                <input type="text" name="code" class="form-control form-control-sm" value="{{ $rule->code }}" required>
                                            </div>
                                            <div class="col-12 col-xl-3">
                                                <input type="text" name="title" class="form-control form-control-sm" value="{{ $rule->title }}" required>
                                            </div>
                                            <div class="col-12 col-xl-4">
                                                <input type="text" name="instruction" class="form-control form-control-sm" value="{{ $rule->instruction }}" required>
                                            </div>
                                            <div class="col-4 col-xl-1">
                                                <input type="number" name="priority" class="form-control form-control-sm" value="{{ $rule->priority }}" min="0" max="10000" required>
                                            </div>
                                            <div class="col-4 col-xl-2">
                                                <div class="form-check form-switch mt-1">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $rule->is_active ? 'checked' : '' }}>
                                                    <label class="form-check-label small">Активне</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Оновити</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('settings.ai.knowledge.rules.destroy', $rule) }}" onsubmit="return confirm('Видалити сценарій?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Сценарії ще не додані.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
