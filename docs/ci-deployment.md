# Перевірки перед деплоєм

Workflow `.github/workflows/deploy.yml` запускає обовʼязкові jobs **Tests (sqlite)** і **Tests (mysql)** для кожного push у `main` та pull request до `main`:

- Усі PHP Unit і Feature тести на PHP 8.4 окремо з SQLite у памʼяті та MySQL 8.0.
- Усі frontend тести Vitest на Node.js 22 (один раз, у job для SQLite).
- Production build Vite та перевірку синтаксису скрипту деплою.

Залежності встановлюються з `composer.lock` і `package-lock.json`. Тести використовують окремий `.env`, згенерований ключ, тестові кеш, сесії та пошту. MySQL працює в тимчасовому service-контейнері з БД `domcrm_testing` та окремими тестовими credentials із YAML. Production-секретів у тестових jobs немає. MySQL-перевірка потрібна, зокрема, для відмінностей SQL, enum і транзакційних блокувань, яких SQLite не відтворює.

Job **Deploy** має `needs: test`: він очікує успіху обох варіантів матриці; будь-яка помилка перевірок блокує автоматичний деплой. Він запускається лише для push у `main`; pull request не отримує SSH-ключ і не деплоїть.

Деплой передає `github.sha` та скрипт `scripts/deploy.sh` із цього самого коміту на сервер. Сервер робить detached checkout цього SHA і звіряє його перед міграціями. Незакомічений `/var/www/domcrm.com.ua/deploy.sh` більше не викликається GitHub Actions. Структура `releases`, `shared`, каталоги зображень, міграції, перезавантаження PHP-FPM та збереження пʼяти останніх релізів залишаються. `public/build` як і раніше має бути зібраний і закомічений разом зі змінами frontend.

Новий push не перериває активний workflow. Серверний `flock` додатково виключає одночасне виконання нового скрипту для цієї CRM. Перемикання `current` виконується атомарно.

## Обовʼязкові перевірки для злиття в main

Залежність `needs` захищає автоматичний деплой одразу після злиття workflow. Щоб також блокувати **злиття** невдалих pull request, після першого запуску додайте в GitHub **Settings → Rules → Rulesets** для `main` правило **Require status checks to pass** із перевірками **Tests (sqlite)** і **Tests (mysql)** та **Require branches to be up to date before merging**. У цьому репозиторії назви цих jobs слід залишати унікальними.

Налаштування правил GitHub не зберігаються в цьому YAML. Ручний SSH-деплой також не проходить через Actions; штатний шлях публікації — push у `main` після успішних перевірок.

Офіційна документація: [залежності jobs](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#jobsjob_idneeds), [керування одночасними запусками](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency).
