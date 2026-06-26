# DomCRM — гайд для розробки

Система замовлень, пакування, доставки, фіскалізації та ШІ-консультанта для магазину DomMood.

## Стек
- **Laravel 12**, PHP 8.2+ (прод — PHP 8.4).
- Фронтенд: **Vue 3 + Vite + Bootstrap**. Blade-файли — лише тонкі точки монтування (`<div id="crm-...">`), уся логіка у `resources/js/crm/`.
- БД: MySQL (прод), SQLite `:memory:` у тестах.
- Тести: PHPUnit (`tests/Feature`, `tests/Unit`).

## Запуск і збірка
- Фронтенд: `npm run build` (Vite). **Зібрані ассети `public/build/` КОМІТЯТЬСЯ в git** — деплой НЕ робить `npm build` на сервері. Після зміни будь-якого `.vue`/`.js` обовʼязково `npm run build` і закомітити `public/build`.
- Тести: `php artisan test` (або `php artisan test tests/Feature/Orders`).

## Сервер і доступ (Hetzner — з 26.06.2026, переїхали з Mirohost)
- Підключення: **`ssh -i ~/.ssh/id_ed25519 root@89.167.66.67`** (або хост `dream-v-doma` у ssh-config — це той самий сервер).
- Робоча директорія DOM-CRM: **`/var/www/domcrm.com.ua/`** (структура `releases/` + симлінк `current` + `shared/`; живий код — у `current/`).
- **На сервері ще 2 ЧУЖІ проєкти — `dommood.com.ua` і `dream-v-doma.com.ua`. НЕ ЧІПАТИ їх** (окремі папки, окремі бази). Працювати ЛИШЕ в `domcrm.com.ua` і базі `domcrm_com_ua`.
- БД: `domcrm_com_ua`, юзер `domcrm` (пароль у `shared/.db_pass`). `.env` — у `shared/.env`. **Anthropic-ключ — у БД (`ai_settings`), не в `.env`.**
- Стек: PHP 8.4, MySQL 8.0, nginx, Node 22. SSL — Let's Encrypt (certbot, авто-продовження).
- Старий Mirohost (`vs2489.mirohost.net`) — **ЗАМОРОЖЕНО** (`php artisan down`) як резерв для відкату; вимкнути після стабілізації.

## Деплой
- Репозиторій: **`git@github.com:ignatyuk71/domcrm.git`**, гілка `main`.
- Деплой на сервері: **`sudo -u deploy bash /var/www/domcrm.com.ua/deploy.sh`** (clone з GitHub → `composer install` [**з dev** — код юзає Pail-провайдер, `--no-dev` падає] → симлінки `.env`/`storage`/`bootstrap` + персистентні public-теки (ai-gallery, storage, saved, inbox-uploads, inbox-context) → `migrate --force` → атомарне перемикання `current` → reload `php8.4-fpm`).
- ⚠️ GitHub Action `.github/workflows/deploy.yml` ще налаштований на СТАРИЙ Mirohost (заморожений) → **деплой поки РУЧНИЙ** через `deploy.sh`, доки Action не переналаштовано на Hetzner.
- Крон: **один** `/etc/cron.d/domcrm` → `php artisan schedule:run` щохвилини керує всім (ai:sweep, fiscal:delivered, fiscal:shift-manager, packing:auto-release, delivery:sync-statuses). QUEUE=sync (без окремого воркера).
- Після деплою PHP-змін: `deploy.sh` сам робить reload php8.4-fpm (opcache скидається на новий реліз).

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
- **OPcache**: `deploy.sh` робить reload `php8.4-fpm` на новий реліз → opcache скидається сам (на старому Mirohost була окрема морока — більше не актуально).
- **Дворівневий ШІ-промпт**: базові правила в коді (`PromptBuilder`) + per-connection `ai_settings.system_prompt` з БД (редагується в CRM, додається зверху). Має пріоритет **лише в тоні/стилі**; секція «ЗАЛІЗНІ ПРАВИЛА» і правила з позначкою «ОБОВ'ЯЗКОВЕ ПРАВИЛО» — **НЕДОТОРКАННІ**, промпт магазину їх НЕ скасовує. Якщо правки коду «не діють» — дивись промпт магазину в БД.
- **Кеш промпту**: системний промпт має 2 точки `cache_control` (правила окремо від каталогу) — не ламай при змінах.
- **Фіскалізація = гроші/податкова**: міняй обережно, лише з тестами (`tests/Feature/Fiscal`). `payments.value` рахується з тих самих `goods` → завжди == сумі товарів.
- **Два агенти в одному дереві**: ділять один зібраний бандл `public/build` → одночасні коміти ассетів конфліктують. Пускати агентів по черзі.
- **Scope**: працювати лише в репо/папці `domcrm`; на сервері Hetzner НЕ чіпати чужі проєкти `dommood` і `dream-v-doma` (окремі папки й бази на тому ж сервері).
