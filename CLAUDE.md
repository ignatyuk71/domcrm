# DomCRM — гайд для розробки

Система замовлень, пакування, доставки, фіскалізації та ШІ-консультанта для магазину DomMood.

## Стек
- **Laravel 12**, PHP 8.2+ (прод — PHP 8.4).
- Фронтенд: **Vue 3 + Vite + Bootstrap**. Blade-файли — лише тонкі точки монтування (`<div id="crm-...">`), уся логіка у `resources/js/crm/`.
- БД: MySQL (прод), SQLite `:memory:` у тестах.
- Тести: PHPUnit (`tests/Feature`, `tests/Unit`).

## Запуск і збірка
- Фронтенд: `npm run build` (Vite). **Зібрані ассети `public/build/` КОМІТЯТЬСЯ в git** — автодеплой робить лише rsync, без `npm build` на сервері. Після зміни будь-якого `.vue`/`.js` обовʼязково `npm run build` і закомітити `public/build`.
- Тести: `php artisan test` (або `php artisan test tests/Feature/Orders`).

## Деплой (автоматичний)
- Push у `main` → GitHub Action `.github/workflows/deploy.yml`: `rsync --checksum` → `php artisan migrate --force` → `optimize:clear` → **reset OPcache** (`curl .../opcache-reset.php?token=...`).
- Сервер — **rsync-файли, не git-репо**. Прод: `domcrm@vs2489.mirohost.net:/var/www/domcrm/domcrm.com.ua/`.
- Після деплою PHP-змін перевіряй: md5 файлу на сервері == локальний; для фронту — md5 нового бандла з `public/build/manifest.json`.

## Ключові домени
- **Замовлення**: `OrderController` (тонкий) + `app/Services/Orders/OrderService.php` (створення/оновлення). Моделі `Order`, `OrderItem`, `OrderPayment`, `OrderDelivery`.
- **ШІ-консультант**: `app/Services/Ai/` — `AiAgentService` (оркестратор) + `PromptBuilder`, `CatalogBuilder`, `HistoryBuilder`, `AgentTools`, `ImageMatcher` (vision/dHash), `OutgoingMessageWriter`, `ClaudeClient`, `TextScrubber`, `OrderTexts`, `AiSchedule`. Джоби `AiRespondToMessage`, `AiReplyToComment`.
- **Inbox/месенджери**: `app/Services/Meta/` (FB/IG OAuth, Send, Webhook). Вебхуки перевіряються HMAC-SHA256.
- **Доставка**: Нова Пошта — `NovaPoshtaService`.
- **Фіскалізація (РРО)**: Checkbox — `CheckboxService`, `FiscalizeOrderJob`, `FiscalQueueService`. Сторінка — Finance (`resources/js/crm/pages/finance/FinancePage.vue`).

## Конвенції
- Контролери тонкі, доменна логіка — у `app/Services/`. Валідація лишається в контролері.
- API-відповіді часто загорнуті у `{ data: {...} }`; `http`-обгортка (`resources/js/crm/api/http.js`) НЕ розгортає → у компонентах читай `response.data.data` (див. `OrderListPage`).
- Тести створюють дані через `Model::create(...)` + `RefreshDatabase`; авторизація — `actingAs(User::factory()->create(['role'=>...]))`.

## Граблі (важливо)
- **OPcache**: після деплою PHP без reset opcache сервер крутить старий код. Action це робить, але перевіряй.
- **Дворівневий ШІ-промпт**: базові правила в коді (`PromptBuilder`) + per-connection `ai_settings.system_prompt` з БД (редагується в CRM, **має пріоритет** — додається зверху). Якщо правки коду «не діють» — дивись промпт магазину в БД.
- **Кеш промпту**: системний промпт має 2 точки `cache_control` (правила окремо від каталогу) — не ламай при змінах.
- **Фіскалізація = гроші/податкова**: міняй обережно, лише з тестами (`tests/Feature/Fiscal`). `payments.value` рахується з тих самих `goods` → завжди == сумі товарів.
- **Два агенти в одному дереві**: ділять один зібраний бандл `public/build` → одночасні коміти ассетів конфліктують. Пускати агентів по черзі.
- **Scope**: працювати лише в репо `domcrm`; не чіпати окремий сайт dream-v-doma.
