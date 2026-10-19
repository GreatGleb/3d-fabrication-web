## Предпроверка 2026-10-19 19:36

D-39: вариант g (default — по умолчанию, b — «б», v — «в», g — «г», как ночь Flexiron)
OK    docker ps
OK    npm ping
OK    curl -sI https://repo.packagist.org/packages.json
OK    push отключён на время ночи (remote.origin.pushurl)
OK    gh не установлен
OK    docker rootless: снято вариантом D-39 g
OK    входы задач есть
OK    legacy-landing без instance и archive: снято вариантом D-39 g (чтение instance/ агенту запрещено)
OK    bin/php эксперимента: bcmath intl gd pdo_pgsql pgsql zip
OK    python3 import playwright
OK    which node npm
OK    удалённая отладка Chrome выключена (DevToolsActivePort устарел, порт 9222 не слушается)

Предпроверка: OK.

## W-01 «Каркас приложения и инструменты» — 2026-10-19, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки). Рабочий каталог сессии — корень репозитория
`/home/greatgleb/PycharmProjects/3D_website` (D-28); команды — из `aiqme-site/`. Агент не делал commit/push.

### Что сделано

- **Предпроверка** `bash docs/planning/edition-2/run/preflight.sh` — код 0, «Предпроверка: OK» (вывод выше, D-39 «г»).
- **Скелет**: `experiments/lunar-aiqme/bin/php /usr/local/bin/composer create-project laravel/laravel:^13.0 build/skeleton --no-install --no-scripts`
  → laravel/laravel v13.11.0 (тот же шаблон, что у эксперимента). Перенесён в `aiqme-site/` rsync'ом без `README.md`
  вместе с dotfiles (`.gitignore`, `.env.example`, `.editorconfig`, `.gitattributes`, `.npmrc`; `.env` — копия `.env.example`
  + `key:generate`). `build/skeleton/` удалён после переноса. `git status --porcelain aiqme-site/experiments` — пусто.
- **bin/php**: `/usr/bin/php` + `PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d:<runtime>/conf:aiqme-site/bin/php.d`
  и `LD_LIBRARY_PATH=<runtime>/usr/lib/x86_64-linux-gnu`; пути — от расположения скрипта.
  `bin/php.d/aiqme-limits.ini` — лимиты §6.4. **Локальная зависимость машины:** расширения bcmath, gd, intl, pdo_pgsql,
  pgsql, zip берутся из `aiqme-site/experiments/lunar-aiqme/runtime/` (исключён `.gitignore`, только чтение; его `conf/*.ini`
  содержат абсолютные пути этой копии). На сервере её заменяет образ PHP-FPM (W-20).
- **Зависимости** (`composer.json`): `lunarphp/lunar` 1.5.0, `stripe/stripe-php` 16.6.0 напрямую, dev `laravel/boost` ^2.10;
  `composer.lock` взят из эксперимента и обновлён частично `composer update lunarphp/stripe stripe/stripe-php`.
  **Отличия от composer.lock эксперимента: единственное — нет `lunarphp/stripe` 1.5.0** (§2.1; маршрута `/stripe/webhook` нет);
  остальные 176 пакетов — те же версии (Laravel 13.37.0, Lunar core 1.5.0, Livewire 3.8.10, Filament 4.15.2, Stripe 16.6.0,
  Boost 2.10.3, PHPUnit 12.5.40). Из эксперимента не взяты автозагрузка `Comparison\\`, `.env`, фикстуры, LocalTestAdapter.
  npm: `package.json` шаблона совпадает с экспериментом байт в байт, `package-lock.json` эксперимента → `npm ci` (Vite 8, Tailwind 4.3.3).
- **pint.json** исключает `experiments`, `build`, `demo`; **Tailwind** — `@import 'tailwindcss' source(none)` с явными `@source`
  (`resources/views`, `resources/js`, пагинация, скомпилированные views) — автопоиск дошёл бы до `experiments/`, он в Git;
  **Vite** `server.watch.ignored` — `experiments`, `build`, `demo`; **phpunit.xml** — только `tests/Unit`, `tests/Feature`, `<source>` — `app`.
- **docker-compose.dev.yml**: `postgres:17` на `127.0.0.1:55440` (БД `aiqme`, тестовая `aiqme_test` из
  `docker/postgres/init/`), `axllent/mailpit:latest` — UI `127.0.0.1:58025`, SMTP `127.0.0.1:51025`. Пароль БД — явно
  фиктивный `local-dev-only-not-a-secret` (только локально). Тег Mailpit не закреплён (локально был только `latest`; в плане версии нет).
- **Lunar**: `config/aiqme.php` → `lunar_panel` из `AIQME_LUNAR_PANEL` (по умолчанию false). `AppServiceProvider` вызывает
  `LunarPanel::register()` только при флаге и не в production. Без панели миграции Lunar падали
  (`EnsureBaseRolesAndPermissions` после миграций вызывает `LunarPanel::getPanel()`), поэтому при выключенной панели
  привязка `lunar-panel` заменена на `App\Support\UnregisteredLunarPanelManager`: Filament-панель не регистрируется
  (маршрутов `/lunar` нет), роли/права создаются для guard `staff`. Телеметрия Lunar (`stats.lunarphp.io`) отключена
  `Telemetry::optOut()` (D-20).
- **Пользователь/админ**: миграция `is_admin` + частичный уникальный индекс `users_single_admin` (второй строки с
  `is_admin = true` БД не примет); `is_admin` не в `#[Fillable]`. `DatabaseSeeder` пуст (test@example.com удалён).
  Команда `aiqme:make-admin {email?} {--name=} {--password-stdin}`: нет администратора → создаёт (email обязателен);
  есть → меняет его пароль (email можно не указывать; другой email — отказ «второй не создаётся»); email существующего
  клиента — отказ (клиент не повышается); в терминале — `password()` Laravel Prompts дважды (подтверждение); без
  терминала — только `--password-stdin` (одна строка); без терминала и без флага — ошибка с подсказкой. Пароль ≥ 12
  символов (моё решение: в плане длина не задана). Терминал определяется `stream_isatty(STDIN)` в `App\Console\Support\Terminal`.
- **tests/TestCase.php** — `Http::preventStrayRequests()`.
- **Boost** (D-17): `boost.json` — агенты `claude_code`, `codex`, guidelines + MCP. `bin/boost-install` запускает
  `bin/php artisan boost:install --no-interaction` с относительными путями для текста правил (`aiqme-site/AGENTS.md`,
  `CLAUDE.md` — команды вида `bin/php artisan …`, `bin/php /usr/local/bin/composer …`, `bin/php vendor/bin/pint …`) и затем
  python3 пишет абсолютные пути в `.mcp.json` (`command` — `…/aiqme-site/bin/php`, `args` — `…/aiqme-site/artisan boost:mcp`)
  и `.codex/config.toml` (те же + `cwd`). `config/boost.php` по умолчанию даёт MCP-серверу абсолютный `bin/php`.
  Обе конфигурации исключены корневым `.gitignore` (`git check-ignore` подтверждает). Корневой `AGENTS.md` не изменён.
  Проверено: MCP-сервер из `.mcp.json`, запущенный с cwd = корень, отвечает на `tools/list` (10 инструментов) и `database-query`.
  Claude Code подключает его флагом `--mcp-config aiqme-site/.mcp.json` в сессиях после W-01.
  Codex: в `~/.codex/config.toml` доверенным отмечен корень `/home/greatgleb/PycharmProjects/3D_website`
  (`trust_level = "trusted"`); отдельной записи для `aiqme-site/` нет — подхватит ли Codex `aiqme-site/.codex/config.toml`
  при запуске из `aiqme-site/`, запуском Codex **не проверено**.
- **bin/check**: расширения bin/php; `experiments/` без изменений; `git check-ignore` для `.env`, `storage/app/*`,
  `public/build`, `public/storage`, `vendor`, `node_modules`, `.codex/`, `.mcp.json`, `build/` и отсутствие их в `git ls-files`;
  `docker compose -f docker-compose.dev.yml config --format json` — у каждой записи `ports` `host_ip = "127.0.0.1"`;
  версии ключевых пакетов и отсутствие `lunarphp/stripe` в `composer.lock`; `pint --test`; запуск PostgreSQL dev
  (`up -d --wait postgres`); `bin/php artisan test`; `npm run build`.
- **bin/setup** — подготовка после клонирования: корневой `.gitignore` исключает `storage/framework/`, `storage/logs/`,
  `bootstrap/cache/` целиком (вместе с заглушками `.gitignore` скелета), поэтому в свежем клоне этих каталогов нет и
  `package:discover` падает; `bin/setup` создаёт их до `composer install`, затем `.env`, ключ, `npm ci`, dev-compose, `migrate`.

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → `bin/check: OK`: 6 расширений; 3 записи ports на 127.0.0.1; Pint passed;
  PHPUnit **28 тестов, 101 проверка, passed**; Vite build OK.
- `bin/php -m` → bcmath, gd, intl, pdo_pgsql, pgsql, zip. `bin/php -i` → upload_max_filesize 25M, post_max_size 260M,
  max_file_uploads 20, memory_limit 512M; `max_execution_time` в CLI всегда 0 (так устроен CLI SAPI) — значение 120
  подтверждено тестом на встроенном сервере `bin/php -S` (как `artisan serve`); дочерний `/usr/bin/php` из `bin/php`
  наследует лимиты и pdo_pgsql (тест).
- Миграции на пустой БД: `migrate:fresh` на `aiqme` (129 миграций Laravel + Lunar + is_admin) и `RefreshDatabase` на `aiqme_test`.
- `bin/php artisan route:list`: без флага — 12 маршрутов, `/lunar` нет; `AIQME_LUNAR_PANEL=true` — 90 маршрутов с `/lunar/*`.
- Тесты (tests/Feature): `LunarSetupTest` — `/lunar` и `/lunar/login` 404 при выключенном флаге, `/lunar/login` 200 при
  флаге в local, нет маршрутов при флаге в production, нет `lunarphp/stripe` и маршрутов `stripe`, телеметрия выключена;
  `MakeAdminCommandTest` — `User::create`/`fill`/`update` с `is_admin=true` прав не дают; скрытый ввод создаёт админа;
  несовпадение подтверждения, пустой stdin, короткий пароль, отсутствие терминала без флага — ничего не создают;
  `--password-stdin` создаёт; повтор (через stdin и через скрытый ввод) меняет пароль того же админа; другой email и email
  клиента — отказ; БД отвергает вторую строку `is_admin`; у команды нет аргумента/опции со значением пароля; сидер не
  создаёт пользователей. `MakeAdminProcessTest` — настоящий процесс `bin/php artisan aiqme:make-admin` со stdin-каналом
  (без TTY): без флага — код 1 и подсказка, с `--password-stdin` — админ создан, пароль не выводится.
  `HttpStrayRequestsTest` — запрос к внешнему адресу без `Http::fake` падает `StrayRequestException`.
  `PhpRuntimeTest` — расширения, лимиты, наследование дочерними процессами, нет абсолютных путей в `bin/php`.
  `HomePageTest` — `/` отвечает 200 без ссылок на fonts.bunny.net.
- Проверка, что тесты ловят поломки: `is_admin` в `#[Fillable]` → тест массового присвоения падает; без
  `Http::preventStrayRequests()` → тест внешнего запроса падает; панель и в production → тест production падает (всё возвращено).
- Скрытый ввод в настоящем терминале (python `pty`, БД `aiqme`): пароль не отображается, админ создан (запись затем удалена).

### Не проверено / замечания для следующих задач

- **Отступление от D-20 (исправлено):** первая `npm run build` со стандартным `vite.config.js` шаблона (`fonts: [bunny('Instrument Sans')]`)
  скачала шрифт с `fonts.bunny.net` — это не реестр из D-20 (только GET публичного шрифта, без данных проекта).
  Плагин шрифтов убран из `vite.config.js`, сборка пересобрана без него (`public/build` без файлов шрифтов); шрифты
  прототипа подключает W-03 локальными файлами.

- Codex с `aiqme-site/.codex/config.toml` не запускался (см. выше). Ручного визуального просмотра в W-01 нет.
- Маршрут шаблона `GET|PUT storage/{path}` (`serve` локального диска Laravel 13, подписанные адреса) остаётся по умолчанию —
  §6.4 запрещает подписанные публичные ссылки на файлы; отключить при работе с хранилищем (W-11/W-12).
- Стартовая страница — `welcome.blade.php` шаблона Laravel; заменяется задачами вёрстки (W-03…).
- `bin/check` требует запущенного Docker (поднимает PostgreSQL dev сам).
