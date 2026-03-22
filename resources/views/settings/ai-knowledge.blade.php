<x-app-layout>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --kb-font: 'Plus Jakarta Sans', sans-serif;
            --kb-bg: #f5f7fb;
            --kb-surface: #ffffff;
            --kb-surface-soft: #f8faff;
            --kb-border: #e3e9f4;
            --kb-border-strong: #d4ddef;
            --kb-text: #182133;
            --kb-muted: #70809a;
            --kb-primary: #635bff;
            --kb-primary-soft: rgba(99, 91, 255, 0.10);
            --kb-success: #16a34a;
            --kb-success-soft: rgba(22, 163, 74, 0.10);
            --kb-warning: #c27a1a;
            --kb-warning-soft: rgba(194, 122, 26, 0.10);
            --kb-danger: #dc2626;
            --kb-danger-soft: rgba(220, 38, 38, 0.08);
            --kb-shadow: 0 18px 40px -30px rgba(15, 23, 42, 0.22);
            --kb-shadow-hover: 0 24px 48px -28px rgba(15, 23, 42, 0.28);
            --kb-radius-xl: 24px;
            --kb-radius-lg: 20px;
            --kb-radius-md: 16px;
            --kb-radius-sm: 12px;
        }

        body {
            font-family: var(--kb-font) !important;
            background:
                radial-gradient(circle at 0% 0%, rgba(99, 91, 255, 0.05), transparent 25%),
                radial-gradient(circle at 100% 0%, rgba(56, 189, 248, 0.04), transparent 20%),
                var(--kb-bg);
            color: var(--kb-text);
        }

        .kb-page {
            max-width: 1460px;
        }

        .kb-card,
        .kb-stat,
        .kb-step,
        .kb-topic-item,
        .kb-rule-item,
        .kb-empty-card,
        .kb-mini-card {
            background: var(--kb-surface);
            border: 1px solid var(--kb-border);
            border-radius: var(--kb-radius-lg);
            box-shadow: var(--kb-shadow);
        }

        .kb-stat:hover,
        .kb-topic-item:hover,
        .kb-rule-item:hover,
        .kb-empty-card:hover,
        .kb-mini-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--kb-shadow-hover);
        }

        .kb-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .kb-stat {
            padding: 1.15rem 1.2rem;
            transition: all .18s ease;
        }

        .kb-stat-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: .9rem;
        }

        .kb-stat-label {
            color: var(--kb-muted);
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: .76rem;
            font-weight: 800;
        }

        .kb-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--kb-primary-soft);
            color: var(--kb-primary);
        }

        .kb-stat-value {
            font-size: 2rem;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -.05em;
            margin-bottom: .35rem;
        }

        .kb-stat-copy {
            color: var(--kb-muted);
            font-size: .92rem;
        }

        .kb-section {
            padding: 1.35rem;
        }

        .kb-section-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .kb-section-title {
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: -.03em;
            margin: 0;
        }

        .kb-section-copy {
            color: var(--kb-muted);
            font-size: .94rem;
            line-height: 1.7;
            margin: .35rem 0 0;
            max-width: 760px;
        }

        .kb-badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: .55rem;
        }

        .kb-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            min-height: 34px;
            padding: .45rem .72rem;
            border-radius: 999px;
            font-size: .76rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .kb-badge.primary {
            background: var(--kb-primary-soft);
            color: var(--kb-primary);
        }

        .kb-badge.success {
            background: var(--kb-success-soft);
            color: var(--kb-success);
        }

        .kb-badge.warning {
            background: var(--kb-warning-soft);
            color: var(--kb-warning);
        }

        .kb-badge.muted {
            background: #eef2f8;
            color: #5f6f88;
        }

        .kb-topic-list,
        .kb-rule-list {
            display: flex;
            flex-direction: column;
            gap: .85rem;
        }

        .kb-topic-item,
        .kb-rule-item,
        .kb-mini-card {
            padding: 1rem;
            transition: all .18s ease;
        }

        .kb-topic-head,
        .kb-rule-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: .8rem;
        }

        .kb-topic-title,
        .kb-rule-title {
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: -.02em;
            margin: 0;
        }

        .kb-topic-copy,
        .kb-rule-copy {
            color: var(--kb-muted);
            font-size: .93rem;
            line-height: 1.75;
            margin: 0;
        }

        .kb-empty-card {
            padding: 1.8rem 1.3rem;
            border-style: dashed;
            background: linear-gradient(180deg, #fbfcff 0%, #f6f9ff 100%);
            text-align: center;
        }

        .kb-empty-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--kb-primary-soft);
            color: var(--kb-primary);
            font-size: 1.35rem;
            margin-bottom: .9rem;
        }

        .kb-empty-title {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -.03em;
            margin-bottom: .55rem;
        }

        .kb-empty-copy {
            color: var(--kb-muted);
            line-height: 1.75;
            margin: 0 auto 1rem;
            max-width: 540px;
        }

        .kb-action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: .55rem;
        }

        .kb-action-btn {
            border-radius: 12px;
            font-size: .86rem;
            font-weight: 700;
            padding: .54rem .9rem;
            border: 1px solid var(--kb-border-strong);
            background: #fff;
            color: var(--kb-text);
            transition: all .16s ease;
        }

        .kb-action-btn:hover,
        .kb-action-btn:focus {
            border-color: rgba(99, 91, 255, 0.45);
            color: var(--kb-primary);
            box-shadow: 0 10px 20px -18px rgba(99, 91, 255, 0.65);
        }

        .kb-action-btn.primary {
            background: var(--kb-primary);
            border-color: var(--kb-primary);
            color: #fff;
        }

        .kb-action-btn.primary:hover,
        .kb-action-btn.primary:focus {
            background: #5146ff;
            border-color: #5146ff;
            color: #fff;
        }

        .kb-modal .modal-content {
            border: 1px solid var(--kb-border);
            border-radius: 18px;
            box-shadow: var(--kb-shadow-hover);
        }

        .kb-modal .modal-header {
            border-bottom: 1px solid var(--kb-border);
            padding: 1rem 1.15rem;
        }

        .kb-modal .modal-title {
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .kb-modal .modal-body {
            padding: 1rem 1.15rem;
        }

        .kb-modal .modal-footer {
            border-top: 1px solid var(--kb-border);
            padding: .9rem 1.15rem 1rem;
        }

        .kb-modal .form-label {
            font-size: .78rem;
            font-weight: 700;
            color: var(--kb-muted);
            margin-bottom: .35rem;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .kb-modal .form-control,
        .kb-modal .form-select {
            border-radius: 12px;
            border-color: var(--kb-border-strong);
            min-height: 44px;
        }

        .kb-modal .form-control:focus,
        .kb-modal .form-select:focus {
            border-color: rgba(99, 91, 255, 0.45);
            box-shadow: 0 0 0 .22rem rgba(99, 91, 255, 0.12);
        }

        .kb-modal .form-check-label {
            color: var(--kb-text);
            font-size: .9rem;
        }

        .kb-data-list {
            display: flex;
            flex-direction: column;
            gap: .85rem;
            margin-top: 1rem;
        }

        .kb-data-item {
            border: 1px solid var(--kb-border);
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            border-radius: 16px;
            padding: 1rem;
            transition: all .18s ease;
        }

        .kb-data-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--kb-shadow-hover);
        }

        .kb-data-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: .85rem;
            margin-bottom: .75rem;
        }

        .kb-data-title {
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: -.02em;
            margin: 0;
        }

        .kb-data-subtitle {
            color: var(--kb-muted);
            font-size: .86rem;
            margin-top: .2rem;
        }

        .kb-data-copy {
            color: var(--kb-muted);
            font-size: .9rem;
            line-height: 1.65;
            margin: 0;
        }

        .kb-inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .kb-inline-btn {
            border-radius: 10px;
            padding: .48rem .78rem;
            font-size: .82rem;
            font-weight: 700;
        }

        .kb-list-section-label {
            color: var(--kb-muted);
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: .76rem;
            font-weight: 800;
            margin: 1.1rem 0 .65rem;
        }

        .kb-grid-two {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .kb-topic-browser {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .kb-topic-browser-item {
            width: 100%;
            text-align: left;
            border: 1px solid var(--kb-border);
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            padding: 1.05rem;
            transition: all .18s ease;
            box-shadow: var(--kb-shadow);
        }

        .kb-topic-browser-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--kb-shadow-hover);
            border-color: rgba(99, 91, 255, 0.35);
        }

        .kb-topic-browser-title {
            font-size: 1.02rem;
            font-weight: 800;
            letter-spacing: -.02em;
            margin-bottom: .4rem;
            color: var(--kb-text);
        }

        .kb-modal-section {
            margin-top: 1rem;
        }

        .kb-modal-section:first-child {
            margin-top: 0;
        }

        .kb-modal-section-title {
            font-size: .82rem;
            font-weight: 800;
            color: var(--kb-muted);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: .65rem;
        }

        @media (max-width: 1399.98px) {
            .kb-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {
            .kb-section {
                padding: 1.2rem;
            }

            .kb-grid-two {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }

            .kb-topic-browser {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }

        @media (max-width: 767.98px) {
            .kb-stats {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }
    </style>

    <div class="py-4 px-3 px-md-4">
        <div class="container-fluid kb-page">
            @if(session('success'))
                <div class="alert alert-success border-0 shadow-sm mb-4" role="alert">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
                    <div class="fw-semibold mb-1">Помилка збереження</div>
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="kb-stats mb-4">
                <article class="kb-stat">
                    <div class="kb-stat-head">
                        <div class="kb-stat-label">Теми</div>
                        <span class="kb-stat-icon"><i class="bi bi-diagram-3"></i></span>
                    </div>
                    <div class="kb-stat-value">{{ $stats['topics_total'] }}</div>
                    <div class="kb-stat-copy">Активних: {{ $stats['topics_active'] }}</div>
                </article>

                <article class="kb-stat">
                    <div class="kb-stat-head">
                        <div class="kb-stat-label">Ключові слова</div>
                        <span class="kb-stat-icon"><i class="bi bi-tag"></i></span>
                    </div>
                    <div class="kb-stat-value">{{ $stats['keywords_total'] }}</div>
                    <div class="kb-stat-copy">Позитивні та негативні</div>
                </article>

                <article class="kb-stat">
                    <div class="kb-stat-head">
                        <div class="kb-stat-label">Товари</div>
                        <span class="kb-stat-icon"><i class="bi bi-box-seam"></i></span>
                    </div>
                    <div class="kb-stat-value">{{ $stats['linked_products_total'] }}</div>
                    <div class="kb-stat-copy">Прив’язані до тем</div>
                </article>

                <article class="kb-stat">
                    <div class="kb-stat-head">
                        <div class="kb-stat-label">Сценарії</div>
                        <span class="kb-stat-icon"><i class="bi bi-chat-square-text"></i></span>
                    </div>
                    <div class="kb-stat-value">{{ $stats['rules_total'] }}</div>
                    <div class="kb-stat-copy">Активних: {{ $stats['rules_active'] }}</div>
                </article>
            </section>

            <div class="row g-4">
                <div class="col-12">
                    <section class="kb-card kb-section">
                        <div class="kb-section-head">
                            <div>
                                <h2 class="kb-section-title">Теми</h2>
                                <p class="kb-section-copy">Групи або контексти, з якими працює AI.</p>
                            </div>
                            <div class="kb-action-buttons">
                                <button type="button"
                                        class="btn kb-action-btn primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#topicCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Додати тему
                                </button>
                            </div>
                        </div>

                        @if($topics->isNotEmpty())
                            <div class="kb-topic-list">
                                @foreach($topics as $topic)
                                    <article class="kb-topic-item">
                                        <div class="kb-topic-head">
                                            <div>
                                                <h3 class="kb-topic-title">{{ $topic->name }}</h3>
                                                <div class="kb-badge-row mt-2">
                                                    <span class="kb-badge {{ $topic->is_active ? 'success' : 'muted' }}">
                                                        {{ $topic->is_active ? 'Активна' : 'Пауза' }}
                                                    </span>
                                                    <span class="kb-badge primary">Пріоритет {{ $topic->priority }}</span>
                                                </div>
                                            </div>
                                            <div class="kb-inline-actions">
                                                <button type="button"
                                                        class="btn btn-outline-secondary kb-inline-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#topicEditModal{{ $topic->id }}">
                                                    Редагувати
                                                </button>
                                                <form method="POST"
                                                      action="{{ route('settings.ai.knowledge.topics.destroy', $topic) }}"
                                                      onsubmit="return confirm('Видалити тему «{{ $topic->name }}»?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger kb-inline-btn">Видалити</button>
                                                </form>
                                            </div>
                                        </div>

                                        <p class="kb-topic-copy">
                                            {{ $topic->instruction ?: 'Інструкція ще не додана.' }}
                                        </p>
                                    </article>
                                @endforeach
                            </div>
                        @else
                            <div class="kb-empty-card">
                                <div class="kb-empty-icon">
                                    <i class="bi bi-diagram-3"></i>
                                </div>
                                <div class="kb-empty-title">Теми ще не створені</div>
                                <p class="kb-empty-copy">
                                    Почни з базових тем, щоб AI відразу розумів, що саме показувати клієнту.
                                </p>
                                <div class="kb-badge-row justify-content-center">
                                    @foreach($recommendedTopics as $topic)
                                        <span class="kb-badge primary">{{ $topic }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                </div>

                <div class="col-12 col-xl-6">
                    <section class="kb-card kb-section h-100">
                        <div class="kb-section-head">
                            <div>
                                <h2 class="kb-section-title">Ключові слова</h2>
                                <p class="kb-section-copy">Позитивні й негативні ключові слова для тем.</p>
                            </div>
                            <div class="kb-action-buttons">
                                <button type="button"
                                        class="btn kb-action-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#keywordCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Додати слово
                                </button>
                            </div>
                        </div>

                        @if($keywords->isNotEmpty())
                            <div class="kb-data-list">
                                @foreach($keywords as $keyword)
                                    <article class="kb-data-item">
                                        <div class="kb-data-head">
                                            <div>
                                                <h3 class="kb-data-title">{{ $keyword->phrase }}</h3>
                                                <div class="kb-data-subtitle">
                                                    Тема: {{ $keyword->topic?->name ?: 'Не знайдено' }}
                                                </div>
                                            </div>
                                            <div class="kb-inline-actions">
                                                <button type="button"
                                                        class="btn btn-outline-secondary kb-inline-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#keywordEditModal{{ $keyword->id }}">
                                                    Редагувати
                                                </button>
                                                <form method="POST"
                                                      action="{{ route('settings.ai.knowledge.keywords.destroy', $keyword) }}"
                                                      onsubmit="return confirm('Видалити ключове слово «{{ $keyword->phrase }}»?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger kb-inline-btn">Видалити</button>
                                                </form>
                                            </div>
                                        </div>
                                        <div class="kb-badge-row">
                                            <span class="kb-badge {{ $keyword->match_type === 'positive' ? 'success' : 'warning' }}">
                                                {{ $keyword->match_type === 'positive' ? 'Позитивний' : 'Негативний' }}
                                            </span>
                                            <span class="kb-badge primary">Вага {{ $keyword->weight }}</span>
                                            <span class="kb-badge {{ $keyword->is_active ? 'success' : 'muted' }}">
                                                {{ $keyword->is_active ? 'Активне' : 'Пауза' }}
                                            </span>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @else
                            <div class="kb-empty-card mt-3">
                                <div class="kb-empty-title">Ключових слів ще немає</div>
                                <p class="kb-empty-copy mb-0">Додай позитивні та негативні фрази, щоб AI точніше розумів тему.</p>
                            </div>
                        @endif
                    </section>
                </div>

                <div class="col-12 col-xl-6">
                    <section class="kb-card kb-section h-100">
                        <div class="kb-section-head">
                            <div>
                                <h2 class="kb-section-title">Прив’язані товари</h2>
                                <p class="kb-section-copy">Товари каталогу, які належать до конкретної теми.</p>
                            </div>
                            <div class="kb-action-buttons">
                                <button type="button"
                                        class="btn kb-action-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#topicProductCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Додати товар
                                </button>
                                <button type="button"
                                        class="btn kb-action-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#topicMediaCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Додати медіа
                                </button>
                            </div>
                        </div>

                        <div class="kb-list-section-label">Теми</div>
                        @if($topics->isNotEmpty())
                            <div class="kb-topic-browser">
                                @foreach($topics as $topic)
                                    <button type="button"
                                            class="kb-topic-browser-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#topicInventoryModal{{ $topic->id }}">
                                        <div class="kb-topic-browser-title">{{ $topic->name }}</div>
                                        <div class="kb-badge-row">
                                            <span class="kb-badge primary">{{ $topic->linked_products_count }} товарів</span>
                                            <span class="kb-badge muted">{{ $topic->linked_media_count }} медіа</span>
                                            <span class="kb-badge {{ $topic->is_active ? 'success' : 'muted' }}">
                                                {{ $topic->is_active ? 'Активна' : 'Пауза' }}
                                            </span>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <div class="kb-empty-card mt-0">
                                <div class="kb-empty-title">Тем ще немає</div>
                                <p class="kb-empty-copy mb-0">Спочатку створи теми, а потім прив’язуй до них товари та медіа.</p>
                            </div>
                        @endif
                    </section>
                </div>

                <div class="col-12">
                    <section class="kb-card kb-section">
                        <div class="kb-section-head">
                            <div>
                                <h2 class="kb-section-title">Правила відповіді</h2>
                                <p class="kb-section-copy">Правила поведінки AI для типових ситуацій.</p>
                            </div>
                            <div class="kb-action-buttons">
                                <button type="button"
                                        class="btn kb-action-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#ruleCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Додати правило
                                </button>
                            </div>
                        </div>

                        @if($rules->isNotEmpty())
                            <div class="kb-rule-list">
                                @foreach($rules as $rule)
                                    <article class="kb-rule-item">
                                        <div class="kb-rule-head">
                                            <div>
                                                <h3 class="kb-rule-title">{{ $rule->title }}</h3>
                                                <div class="small text-muted mt-1">{{ $rule->code }}</div>
                                            </div>
                                            <div class="kb-inline-actions">
                                                <button type="button"
                                                        class="btn btn-outline-secondary kb-inline-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#ruleEditModal{{ $rule->id }}">
                                                    Редагувати
                                                </button>
                                                <form method="POST"
                                                      action="{{ route('settings.ai.knowledge.rules.destroy', $rule) }}"
                                                      onsubmit="return confirm('Видалити правило «{{ $rule->title }}»?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger kb-inline-btn">Видалити</button>
                                                </form>
                                                <span class="kb-badge {{ $rule->is_active ? 'success' : 'muted' }}">
                                                    {{ $rule->is_active ? 'Активне' : 'Пауза' }}
                                                </span>
                                            </div>
                                        </div>

                                        <p class="kb-rule-copy">
                                            {{ \Illuminate\Support\Str::limit($rule->instruction, 150) }}
                                        </p>
                                    </article>
                                @endforeach
                            </div>
                        @else
                            <div class="kb-badge-row">
                                @foreach($recommendedRules as $rule)
                                    <span class="kb-badge muted">{{ $rule }}</span>
                                @endforeach
                            </div>
                        @endif
                    </section>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade kb-modal" id="topicCreateModal" tabindex="-1" aria-labelledby="topicCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('settings.ai.knowledge.topics.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="_form" value="topic_create">
                <input type="hidden" name="_modal_id" value="topicCreateModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="topicCreateModalLabel">Нова тема</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="topic-name" class="form-label">Назва теми</label>
                            <input type="text" class="form-control" id="topic-name" name="name" value="{{ old('_form') === 'topic_create' ? old('name') : '' }}" required>
                        </div>
                        <div class="col-12">
                            <label for="topic-instruction" class="form-label">Інструкція для AI</label>
                            <textarea class="form-control" id="topic-instruction" name="instruction" rows="4" placeholder="Коротко: коли ця тема підходить, що показувати, чого уникати.">{{ old('_form') === 'topic_create' ? old('instruction') : '' }}</textarea>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="topic-priority" class="form-label">Пріоритет</label>
                            <input type="number" class="form-control" id="topic-priority" name="priority" min="0" max="10000" value="{{ old('_form') === 'topic_create' ? old('priority', 100) : 100 }}" required>
                        </div>
                        <div class="col-12 col-md-8 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="topic-active" name="is_active" value="1" @checked(old('_form') === 'topic_create' ? old('is_active') : true)>
                                <label class="form-check-label ms-2" for="topic-active">Тема активна</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">Зберегти тему</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade kb-modal" id="keywordCreateModal" tabindex="-1" aria-labelledby="keywordCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('settings.ai.knowledge.keywords.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="_form" value="keyword_create">
                <input type="hidden" name="_modal_id" value="keywordCreateModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="keywordCreateModalLabel">Нове ключове слово</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                </div>
                <div class="modal-body">
                    @if($topics->isEmpty())
                        <div class="alert alert-warning mb-0">Спочатку додай хоча б одну тему.</div>
                    @else
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="keyword-topic" class="form-label">Тема</label>
                                <select class="form-select" id="keyword-topic" name="topic_id" required>
                                    <option value="">Оберіть тему</option>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}" @selected(old('_form') === 'keyword_create' && (string) old('topic_id') === (string) $topic->id)>
                                            {{ $topic->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="keyword-phrase" class="form-label">Фраза</label>
                                <input type="text" class="form-control" id="keyword-phrase" name="phrase" value="{{ old('_form') === 'keyword_create' ? old('phrase') : '' }}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="keyword-match-type" class="form-label">Тип збігу</label>
                                <select class="form-select" id="keyword-match-type" name="match_type" required>
                                    <option value="positive" @selected(old('_form') === 'keyword_create' ? old('match_type', 'positive') === 'positive' : true)>Позитивний</option>
                                    <option value="negative" @selected(old('_form') === 'keyword_create' && old('match_type') === 'negative')>Негативний</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="keyword-weight" class="form-label">Вага</label>
                                <input type="number" class="form-control" id="keyword-weight" name="weight" min="1" max="10000" value="{{ old('_form') === 'keyword_create' ? old('weight', 100) : 100 }}" required>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="keyword-active" name="is_active" value="1" @checked(old('_form') === 'keyword_create' ? old('is_active') : true)>
                                    <label class="form-check-label ms-2" for="keyword-active">Ключове слово активне</label>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary" @disabled($topics->isEmpty())>Зберегти слово</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade kb-modal" id="topicProductCreateModal" tabindex="-1" aria-labelledby="topicProductCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('settings.ai.knowledge.topicProducts.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="_form" value="topic_product_create">
                <input type="hidden" name="_modal_id" value="topicProductCreateModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="topicProductCreateModalLabel">Прив’язати товар до теми</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                </div>
                <div class="modal-body">
                    @if($topics->isEmpty() || $products->isEmpty())
                        <div class="alert alert-warning mb-0">Потрібно мати активні теми і товари в каталозі.</div>
                    @else
                        @if(old('_modal_id') === 'topicProductCreateModal' && $errors->has('product_id'))
                            <div class="alert alert-danger">
                                {{ $errors->first('product_id') }}
                            </div>
                        @endif
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="topic-product-topic" class="form-label">Тема</label>
                                <select class="form-select" id="topic-product-topic" name="topic_id" required>
                                    <option value="">Оберіть тему</option>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}" @selected(old('_form') === 'topic_product_create' && (string) old('topic_id') === (string) $topic->id)>
                                            {{ $topic->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="topic-product-product" class="form-label">Товар</label>
                                <select class="form-select" id="topic-product-product" name="product_id" required>
                                    <option value="">Оберіть товар</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" @selected(old('_form') === 'topic_product_create' && (string) old('product_id') === (string) $product->id)>
                                            {{ $product->title }}@if($product->sku) (SKU: {{ $product->sku }})@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="topic-product-sort" class="form-label">Порядок</label>
                                <input type="number" class="form-control" id="topic-product-sort" name="sort_order" min="0" max="10000" value="{{ old('_form') === 'topic_product_create' ? old('sort_order', 100) : 100 }}" required>
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="topic-product-active" name="is_active" value="1" @checked(old('_form') === 'topic_product_create' ? old('is_active') : true)>
                                    <label class="form-check-label ms-2" for="topic-product-active">Прив’язка активна</label>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary" @disabled($topics->isEmpty() || $products->isEmpty())>Зберегти прив’язку</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade kb-modal" id="topicMediaCreateModal" tabindex="-1" aria-labelledby="topicMediaCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('settings.ai.knowledge.media.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="_form" value="topic_media_create">
                <input type="hidden" name="_modal_id" value="topicMediaCreateModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="topicMediaCreateModalLabel">Додати медіа</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                </div>
                <div class="modal-body">
                    @if($topics->isEmpty())
                        <div class="alert alert-warning mb-0">Спочатку додай тему, до якої треба прив’язати медіа.</div>
                    @else
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="media-topic" class="form-label">Тема</label>
                                <select class="form-select" id="media-topic" name="topic_id" required>
                                    <option value="">Оберіть тему</option>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}" @selected(old('_form') === 'topic_media_create' && (string) old('topic_id') === (string) $topic->id)>
                                            {{ $topic->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="media-label" class="form-label">Назва / мітка</label>
                                <input type="text" class="form-control" id="media-label" name="label" value="{{ old('_form') === 'topic_media_create' ? old('label') : '' }}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="media-type" class="form-label">Тип медіа</label>
                                <select class="form-select" id="media-type" name="media_type" required>
                                    <option value="image" @selected(old('_form') === 'topic_media_create' ? old('media_type', 'image') === 'image' : true)>Зображення</option>
                                    <option value="size_chart" @selected(old('_form') === 'topic_media_create' && old('media_type') === 'size_chart')>Таблиця розмірів</option>
                                    <option value="palette" @selected(old('_form') === 'topic_media_create' && old('media_type') === 'palette')>Палітра</option>
                                    <option value="promo" @selected(old('_form') === 'topic_media_create' && old('media_type') === 'promo')>Промо</option>
                                    <option value="collage" @selected(old('_form') === 'topic_media_create' && old('media_type') === 'collage')>Колаж</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="media-sort-order" class="form-label">Порядок</label>
                                <input type="number" class="form-control" id="media-sort-order" name="sort_order" min="0" max="10000" value="{{ old('_form') === 'topic_media_create' ? old('sort_order', 100) : 100 }}" required>
                            </div>
                            <div class="col-12">
                                <label for="media-file" class="form-label">Файл з галереї (необовʼязково)</label>
                                <select class="form-select" id="media-file" name="saved_file_id">
                                    <option value="">Не обрано</option>
                                    @foreach($savedFiles as $savedFile)
                                        <option value="{{ $savedFile->id }}" @selected(old('_form') === 'topic_media_create' && (string) old('saved_file_id') === (string) $savedFile->id)>
                                            {{ $savedFile->filename }}@if($savedFile->type) ({{ $savedFile->type }})@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="media-url" class="form-label">URL (необовʼязково)</label>
                                <input type="url" class="form-control" id="media-url" name="url" value="{{ old('_form') === 'topic_media_create' ? old('url') : '' }}" placeholder="https://...">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="media-active" name="is_active" value="1" @checked(old('_form') === 'topic_media_create' ? old('is_active') : true)>
                                    <label class="form-check-label ms-2" for="media-active">Медіа активне</label>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary" @disabled($topics->isEmpty())>Зберегти медіа</button>
                </div>
            </form>
        </div>
    </div>

    @foreach($topics as $topic)
        <div class="modal fade kb-modal" id="topicInventoryModal{{ $topic->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">{{ $topic->name }}</h5>
                            @if($topic->instruction)
                                <div class="text-muted small mt-1">{{ \Illuminate\Support\Str::limit($topic->instruction, 160) }}</div>
                            @endif
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button"
                                    class="btn btn-primary js-topic-prefill"
                                    data-open-prefill-modal="topicProductCreateModal"
                                    data-topic-id="{{ $topic->id }}">
                                <i class="bi bi-plus-lg me-1"></i>Додати товар
                            </button>
                            <button type="button"
                                    class="btn btn-outline-secondary js-topic-prefill"
                                    data-open-prefill-modal="topicMediaCreateModal"
                                    data-topic-id="{{ $topic->id }}">
                                <i class="bi bi-plus-lg me-1"></i>Додати медіа
                            </button>
                        </div>

                        <section class="kb-modal-section">
                            <div class="kb-modal-section-title">Товари теми</div>
                            @if($topic->topicProducts->isNotEmpty())
                                <div class="kb-data-list mt-0">
                                    @foreach($topic->topicProducts as $topicProduct)
                                        <article class="kb-data-item">
                                            <div class="kb-data-head">
                                                <div>
                                                    <h3 class="kb-data-title">{{ $topicProduct->product?->title ?: 'Товар не знайдено' }}</h3>
                                                    <div class="kb-data-subtitle">
                                                        @if($topicProduct->product?->sku)
                                                            SKU: {{ $topicProduct->product->sku }}
                                                        @else
                                                            Без SKU
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="kb-inline-actions">
                                                    <button type="button"
                                                            class="btn btn-outline-secondary kb-inline-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#topicProductEditModal{{ $topicProduct->id }}">
                                                        Редагувати
                                                    </button>
                                                    <form method="POST"
                                                          action="{{ route('settings.ai.knowledge.topicProducts.destroy', $topicProduct) }}"
                                                          onsubmit="return confirm('Видалити прив’язку товару?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-outline-danger kb-inline-btn">Видалити</button>
                                                    </form>
                                                </div>
                                            </div>
                                            <div class="kb-badge-row">
                                                <span class="kb-badge primary">Порядок {{ $topicProduct->sort_order }}</span>
                                                @if(!is_null($topicProduct->product?->sale_price))
                                                    <span class="kb-badge muted">{{ number_format((float) $topicProduct->product->sale_price, 0, ',', ' ') }} грн</span>
                                                @endif
                                                <span class="kb-badge {{ $topicProduct->is_active ? 'success' : 'muted' }}">
                                                    {{ $topicProduct->is_active ? 'Активна прив’язка' : 'Пауза' }}
                                                </span>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            @else
                                <div class="kb-empty-card mt-0">
                                    <div class="kb-empty-title">У цій темі ще немає товарів</div>
                                    <p class="kb-empty-copy mb-0">Додай товари саме до цієї теми, щоб AI показував їх клієнту.</p>
                                </div>
                            @endif
                        </section>

                        <section class="kb-modal-section">
                            <div class="kb-modal-section-title">Медіа теми</div>
                            @if($topic->mediaItems->isNotEmpty())
                                <div class="kb-data-list mt-0">
                                    @foreach($topic->mediaItems as $media)
                                        <article class="kb-data-item">
                                            <div class="kb-data-head">
                                                <div>
                                                    <h3 class="kb-data-title">{{ $media->label }}</h3>
                                                    <div class="kb-data-subtitle">
                                                        @if($media->savedFile?->filename)
                                                            Файл: {{ $media->savedFile->filename }}
                                                        @elseif($media->url)
                                                            URL додано вручну
                                                        @else
                                                            Без прив’язаного файлу
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="kb-inline-actions">
                                                    <button type="button"
                                                            class="btn btn-outline-secondary kb-inline-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#topicMediaEditModal{{ $media->id }}">
                                                        Редагувати
                                                    </button>
                                                    <form method="POST"
                                                          action="{{ route('settings.ai.knowledge.media.destroy', $media) }}"
                                                          onsubmit="return confirm('Видалити медіа «{{ $media->label }}»?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-outline-danger kb-inline-btn">Видалити</button>
                                                    </form>
                                                </div>
                                            </div>
                                            <div class="kb-badge-row mb-2">
                                                <span class="kb-badge primary">{{ $media->media_type }}</span>
                                                <span class="kb-badge primary">Порядок {{ $media->sort_order }}</span>
                                                <span class="kb-badge {{ $media->is_active ? 'success' : 'muted' }}">
                                                    {{ $media->is_active ? 'Активне' : 'Пауза' }}
                                                </span>
                                            </div>
                                            @if($media->url)
                                                <p class="kb-data-copy">{{ $media->url }}</p>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            @else
                                <div class="kb-empty-card mt-0">
                                    <div class="kb-empty-title">У цій темі ще немає медіа</div>
                                    <p class="kb-empty-copy mb-0">Додай фото, колажі або палітри, які підходять саме до цієї теми.</p>
                                </div>
                            @endif
                        </section>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Закрити</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="modal fade kb-modal" id="ruleCreateModal" tabindex="-1" aria-labelledby="ruleCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('settings.ai.knowledge.rules.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="_form" value="rule_create">
                <input type="hidden" name="_modal_id" value="ruleCreateModal">
                <div class="modal-header">
                    <h5 class="modal-title" id="ruleCreateModalLabel">Нове правило відповіді</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="rule-code" class="form-label">Код</label>
                            <input type="text" class="form-control" id="rule-code" name="code" value="{{ old('_form') === 'rule_create' ? old('code') : '' }}" placeholder="photo_request" required>
                        </div>
                        <div class="col-12 col-md-8">
                            <label for="rule-title" class="form-label">Назва</label>
                            <input type="text" class="form-control" id="rule-title" name="title" value="{{ old('_form') === 'rule_create' ? old('title') : '' }}" required>
                        </div>
                        <div class="col-12">
                            <label for="rule-instruction" class="form-label">Інструкція</label>
                            <textarea class="form-control" id="rule-instruction" name="instruction" rows="5" required>{{ old('_form') === 'rule_create' ? old('instruction') : '' }}</textarea>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="rule-priority" class="form-label">Пріоритет</label>
                            <input type="number" class="form-control" id="rule-priority" name="priority" min="0" max="10000" value="{{ old('_form') === 'rule_create' ? old('priority', 100) : 100 }}" required>
                        </div>
                        <div class="col-12 col-md-8 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="rule-active" name="is_active" value="1" @checked(old('_form') === 'rule_create' ? old('is_active') : true)>
                                <label class="form-check-label ms-2" for="rule-active">Правило активне</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">Зберегти правило</button>
                </div>
            </form>
        </div>
    </div>

    @foreach($topics as $topic)
        <div class="modal fade kb-modal" id="topicEditModal{{ $topic->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="{{ route('settings.ai.knowledge.topics.update', $topic) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form" value="topic_edit">
                    <input type="hidden" name="_modal_id" value="topicEditModal{{ $topic->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагування теми</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Назва теми</label>
                                <input type="text" class="form-control" name="name" value="{{ old('_modal_id') === 'topicEditModal'.$topic->id ? old('name') : $topic->name }}" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Інструкція для AI</label>
                                <textarea class="form-control" name="instruction" rows="4">{{ old('_modal_id') === 'topicEditModal'.$topic->id ? old('instruction') : $topic->instruction }}</textarea>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Пріоритет</label>
                                <input type="number" class="form-control" name="priority" min="0" max="10000" value="{{ old('_modal_id') === 'topicEditModal'.$topic->id ? old('priority') : $topic->priority }}" required>
                            </div>
                            <div class="col-12 col-md-8 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="topic-edit-active-{{ $topic->id }}" name="is_active" value="1" @checked(old('_modal_id') === 'topicEditModal'.$topic->id ? old('is_active') : $topic->is_active)>
                                    <label class="form-check-label ms-2" for="topic-edit-active-{{ $topic->id }}">Тема активна</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="submit" class="btn btn-primary">Оновити тему</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    @foreach($keywords as $keyword)
        <div class="modal fade kb-modal" id="keywordEditModal{{ $keyword->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="{{ route('settings.ai.knowledge.keywords.update', $keyword) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form" value="keyword_edit">
                    <input type="hidden" name="_modal_id" value="keywordEditModal{{ $keyword->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагування ключового слова</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Тема</label>
                                <select class="form-select" name="topic_id" required>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}" @selected((string) (old('_modal_id') === 'keywordEditModal'.$keyword->id ? old('topic_id') : $keyword->topic_id) === (string) $topic->id)>
                                            {{ $topic->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Фраза</label>
                                <input type="text" class="form-control" name="phrase" value="{{ old('_modal_id') === 'keywordEditModal'.$keyword->id ? old('phrase') : $keyword->phrase }}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Тип збігу</label>
                                <select class="form-select" name="match_type" required>
                                    <option value="positive" @selected((old('_modal_id') === 'keywordEditModal'.$keyword->id ? old('match_type') : $keyword->match_type) === 'positive')>Позитивний</option>
                                    <option value="negative" @selected((old('_modal_id') === 'keywordEditModal'.$keyword->id ? old('match_type') : $keyword->match_type) === 'negative')>Негативний</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Вага</label>
                                <input type="number" class="form-control" name="weight" min="1" max="10000" value="{{ old('_modal_id') === 'keywordEditModal'.$keyword->id ? old('weight') : $keyword->weight }}" required>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="keyword-edit-active-{{ $keyword->id }}" name="is_active" value="1" @checked(old('_modal_id') === 'keywordEditModal'.$keyword->id ? old('is_active') : $keyword->is_active)>
                                    <label class="form-check-label ms-2" for="keyword-edit-active-{{ $keyword->id }}">Ключове слово активне</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="submit" class="btn btn-primary">Оновити слово</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    @foreach($topicProducts as $topicProduct)
        <div class="modal fade kb-modal" id="topicProductEditModal{{ $topicProduct->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="{{ route('settings.ai.knowledge.topicProducts.update', $topicProduct) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form" value="topic_product_edit">
                    <input type="hidden" name="_modal_id" value="topicProductEditModal{{ $topicProduct->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагування прив’язки товару</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Тема</label>
                                <select class="form-select" name="topic_id" required>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}" @selected((string) (old('_modal_id') === 'topicProductEditModal'.$topicProduct->id ? old('topic_id') : $topicProduct->topic_id) === (string) $topic->id)>
                                            {{ $topic->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Товар</label>
                                <select class="form-select" name="product_id" required>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" @selected((string) (old('_modal_id') === 'topicProductEditModal'.$topicProduct->id ? old('product_id') : $topicProduct->product_id) === (string) $product->id)>
                                            {{ $product->title }}@if($product->sku) (SKU: {{ $product->sku }})@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Порядок</label>
                                <input type="number" class="form-control" name="sort_order" min="0" max="10000" value="{{ old('_modal_id') === 'topicProductEditModal'.$topicProduct->id ? old('sort_order') : $topicProduct->sort_order }}" required>
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="topic-product-edit-active-{{ $topicProduct->id }}" name="is_active" value="1" @checked(old('_modal_id') === 'topicProductEditModal'.$topicProduct->id ? old('is_active') : $topicProduct->is_active)>
                                    <label class="form-check-label ms-2" for="topic-product-edit-active-{{ $topicProduct->id }}">Прив’язка активна</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="submit" class="btn btn-primary">Оновити прив’язку</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    @foreach($topicMedia as $media)
        <div class="modal fade kb-modal" id="topicMediaEditModal{{ $media->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="{{ route('settings.ai.knowledge.media.update', $media) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form" value="topic_media_edit">
                    <input type="hidden" name="_modal_id" value="topicMediaEditModal{{ $media->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагування медіа</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Тема</label>
                                <select class="form-select" name="topic_id" required>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}" @selected((string) (old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('topic_id') : $media->topic_id) === (string) $topic->id)>
                                            {{ $topic->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Назва / мітка</label>
                                <input type="text" class="form-control" name="label" value="{{ old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('label') : $media->label }}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Тип медіа</label>
                                <select class="form-select" name="media_type" required>
                                    @foreach(['image' => 'Зображення', 'size_chart' => 'Таблиця розмірів', 'palette' => 'Палітра', 'promo' => 'Промо', 'collage' => 'Колаж'] as $value => $label)
                                        <option value="{{ $value }}" @selected((old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('media_type') : $media->media_type) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Порядок</label>
                                <input type="number" class="form-control" name="sort_order" min="0" max="10000" value="{{ old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('sort_order') : $media->sort_order }}" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Файл з галереї</label>
                                <select class="form-select" name="saved_file_id">
                                    <option value="">Не обрано</option>
                                    @foreach($savedFiles as $savedFile)
                                        <option value="{{ $savedFile->id }}" @selected((string) (old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('saved_file_id') : $media->saved_file_id) === (string) $savedFile->id)>
                                            {{ $savedFile->filename }}@if($savedFile->type) ({{ $savedFile->type }})@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">URL</label>
                                <input type="url" class="form-control" name="url" value="{{ old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('url') : $media->url }}">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="topic-media-edit-active-{{ $media->id }}" name="is_active" value="1" @checked(old('_modal_id') === 'topicMediaEditModal'.$media->id ? old('is_active') : $media->is_active)>
                                    <label class="form-check-label ms-2" for="topic-media-edit-active-{{ $media->id }}">Медіа активне</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="submit" class="btn btn-primary">Оновити медіа</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    @foreach($rules as $rule)
        <div class="modal fade kb-modal" id="ruleEditModal{{ $rule->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="{{ route('settings.ai.knowledge.rules.update', $rule) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form" value="rule_edit">
                    <input type="hidden" name="_modal_id" value="ruleEditModal{{ $rule->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагування правила</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Код</label>
                                <input type="text" class="form-control" name="code" value="{{ old('_modal_id') === 'ruleEditModal'.$rule->id ? old('code') : $rule->code }}" required>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label">Назва</label>
                                <input type="text" class="form-control" name="title" value="{{ old('_modal_id') === 'ruleEditModal'.$rule->id ? old('title') : $rule->title }}" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Інструкція</label>
                                <textarea class="form-control" name="instruction" rows="5" required>{{ old('_modal_id') === 'ruleEditModal'.$rule->id ? old('instruction') : $rule->instruction }}</textarea>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Пріоритет</label>
                                <input type="number" class="form-control" name="priority" min="0" max="10000" value="{{ old('_modal_id') === 'ruleEditModal'.$rule->id ? old('priority') : $rule->priority }}" required>
                            </div>
                            <div class="col-12 col-md-8 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="rule-edit-active-{{ $rule->id }}" name="is_active" value="1" @checked(old('_modal_id') === 'ruleEditModal'.$rule->id ? old('is_active') : $rule->is_active)>
                                    <label class="form-check-label ms-2" for="rule-edit-active-{{ $rule->id }}">Правило активне</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="submit" class="btn btn-primary">Оновити правило</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    @php
        $autoOpenModalId = old('_modal_id');
    @endphp

    @if($autoOpenModalId)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modalElement = document.getElementById('{{ $autoOpenModalId }}');
                if (!modalElement || !window.bootstrap) return;
                window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
            });
        </script>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.bootstrap) return;

            document.querySelectorAll('.js-topic-prefill').forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetModalId = this.dataset.openPrefillModal;
                    const topicId = this.dataset.topicId;
                    const currentModalElement = this.closest('.modal');
                    const targetModalElement = document.getElementById(targetModalId);

                    if (!targetModalElement) return;

                    const topicSelect = targetModalElement.querySelector('select[name="topic_id"]');
                    if (topicSelect && topicId) {
                        topicSelect.value = topicId;
                    }

                    const showTargetModal = function () {
                        window.bootstrap.Modal.getOrCreateInstance(targetModalElement).show();
                    };

                    if (currentModalElement) {
                        currentModalElement.addEventListener('hidden.bs.modal', showTargetModal, { once: true });
                        window.bootstrap.Modal.getOrCreateInstance(currentModalElement).hide();
                        return;
                    }

                    showTargetModal();
                });
            });
        });
    </script>
</x-app-layout>
