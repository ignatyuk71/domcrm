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
</x-app-layout>
