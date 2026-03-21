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

        @media (max-width: 1399.98px) {
            .kb-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {
            .kb-section {
                padding: 1.2rem;
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

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <article class="kb-mini-card h-100">
                                    <div class="kb-stat-label mb-3">Позитивні збіги</div>
                                    <div class="kb-stat-value">{{ $stats['keywords_total'] }}</div>
                                    <div class="kb-stat-copy">Слова, які запускають тему.</div>
                                </article>
                            </div>
                            <div class="col-12 col-md-6">
                                <article class="kb-mini-card h-100">
                                    <div class="kb-stat-label mb-3">Роль</div>
                                        <div class="kb-stat-copy">Слова і фрази, за якими AI знаходить або відсікає тему.</div>
                                </article>
                            </div>
                        </div>
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

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <article class="kb-mini-card h-100">
                                    <div class="kb-stat-label mb-3">Прив’язані товари</div>
                                    <div class="kb-stat-value">{{ $stats['linked_products_total'] }}</div>
                                    <div class="kb-stat-copy">Товари з каталогу, без дублювання даних.</div>
                                </article>
                            </div>
                            <div class="col-12 col-md-6">
                                <article class="kb-mini-card h-100">
                                    <div class="kb-stat-label mb-3">Роль</div>
                                        <div class="kb-stat-copy">Товари, які підтягуються в тему як опорні дані.</div>
                                </article>
                            </div>
                        </div>
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
                                            <div class="kb-badge-row">
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
                <div class="modal-header">
                    <h5 class="modal-title" id="topicProductCreateModalLabel">Прив’язати товар до теми</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                </div>
                <div class="modal-body">
                    @if($topics->isEmpty() || $products->isEmpty())
                        <div class="alert alert-warning mb-0">Потрібно мати активні теми і товари в каталозі.</div>
                    @else
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

    <div class="modal fade kb-modal" id="ruleCreateModal" tabindex="-1" aria-labelledby="ruleCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('settings.ai.knowledge.rules.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="_form" value="rule_create">
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

    @php
        $formModalMap = [
            'topic_create' => 'topicCreateModal',
            'keyword_create' => 'keywordCreateModal',
            'topic_product_create' => 'topicProductCreateModal',
            'topic_media_create' => 'topicMediaCreateModal',
            'rule_create' => 'ruleCreateModal',
        ];
        $autoOpenModalId = $formModalMap[old('_form')] ?? null;
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
</x-app-layout>
