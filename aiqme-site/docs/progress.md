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

## W-02 «Локали, маршруты и SEO-каркас» — 2026-10-19, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки). Агент не делал commit/push.

### Что сделано

- **Маршруты** (`routes/web.php`, `app/Support/Seo/StaticPages.php`): 19 статических страниц §1.2 на `lt`/`en`
  с сегментами прототипа (`/lt/paslaugos` · `/en/services`, `/lt/kalkuliatorius` · `/en/quote` и т. д.) и на `ru` с
  английскими сегментами (§4.3); имена `<locale>.<page>`. Страницы отвечают общей заготовкой `pages/static`
  (`<x-layout>` + `<h1>`), без контроллеров. Адреса сущностей, кабинета, админки и `apmokejimas/<public_id>` не
  создавались (W-05/W-06/W-25, W-07, W-08, W-16); slug в маршрутах нет. `/` → 301 на `route('lt.home')`.
  Адрес со «/» отвечает тем же содержимым (маршрутизатор Laravel не различает завершающий «/»), canonical — из `route()` без «/».
- **Локаль**: глобальный `SetLocaleFromPath` ставит `lt`/`en` по первому сегменту — и для 404 неизвестных адресов;
  `RestrictRussianToAdmin` на группе `/ru`: не администратор (гость или клиент) — 404 (такая же страница, как любой
  неизвестный адрес, LT), администратору — локаль `ru`, `X-Robots-Tag: noindex, nofollow` и meta noindex; без RU-файлов
  тексты берутся из EN (`APP_FALLBACK_LOCALE=en`).
- **SEO-head** `<x-seo-head>`: `<title>… | AIQME`, description, robots, canonical, hreflang только из переданных версий,
  `x-default` → LT (только если LT-версия есть), og:title, og:description, og:image — только если передано изображение
  (у заготовок медиа нет). `URL::forceRootUrl(APP_URL)` в `AppServiceProvider`: canonical/hreflang/sitemap не зависят от Host.
- **Переключатель языка** — одна ссылка `<a>` на ту же страницу другой локали, как в прототипе («LT / EN»,
  aria-label «Switch to English» · «Keisti į lietuvių kalbą»); на 404/500 — на главную другой локали; на `/ru` — на LT.
- **404/500** (`resources/views/errors/`) на обеих локалях, noindex. Тексты 404 — из `notFound()` app.js прототипа
  («Puslapis nerastas» · «Page not found», «Pasirinkite vieną…» · «Choose one…», «Grįžti į pradžią» · «Go home»).
- **sitemap.xml** (Blade, `xhtml:link` lt/en/x-default) и **robots.txt** (маршрут: `Disallow:` пустой + `Sitemap:` абсолютный);
  статический `public/robots.txt` удалён (иначе веб-сервер отдавал бы его вместо маршрута).
- **Фикстура** `tests/fixtures/prototype-routes.json`: 184 адреса route-manifest, у каждого только `path` и `row`
  (номер строки §4.6) + словарь `rows` со статусом строки; без текстов, заголовков и ссылок. Извлечена один раз скриптом
  `build/w02/extract_prototype_routes.py` (игнорируемый `build/`, не в Git) по правилам сегментов; адрес без строки — ошибка
  скрипта. Распределение по строкам: 1:1, 2:66, 3:12, 4:12, 5:16, 6:6, 7:2, 8:2, 9:1, 10:12, 11:6, 12:6, 13:30, 14:12.
- `resources/views/welcome.blade.php` шаблона удалён (`/` теперь 301); `HomePageTest` W-01 проверяет `/lt` и `/en`.
- `LunarSetupTest` (W-01): в tearDown добавлен `Livewire::flushState()` — тест панели Filament оставлял статический флаг
  вставки ассетов Livewire, и следующие тесты получали стили Livewire в HTML (нашлось сравнением содержимого «/» и без «/»).

### Решения по неопределённому (простейший вариант)

- **Тексты** (`lang/lt|en/pages.php`): заголовок — `<h1>` прототипа, description — meta description прототипа
  (verified-route-screens). Демо-описания политик («Demonstracinė informacijos vieta…» · «Illustrative policy area…»),
  калькулятора («…demonstracinę kainą» · «…illustrative price») и проектов («…demonstraciniai…») заменены общим описанием
  сайта прототипа «AIQME — vienas partneris nuo užduoties iki produkto.» · «AIQME — one partner from challenge to product.» (D-21).
  У «О нас» в `<title>` прототипа нет пробела («prasidedanuo» из-за `<br>`) — взят видимый текст h1 с пробелом.
  EN-проекты прототипа показывают LT (F-38): EN-заголовок «Projects» — из пункта меню EN.
  **Текста 500 в прототипе нет** — короткие нейтральные: «Serverio klaida» / «Bandykite dar kartą vėliau.» ·
  «Server error» / «Please try again later.»; кнопка — как у 404.
- **Sitemap** (W-02 — только статические): главная, услуги, информация, библиотека, о нас, контакты, калькулятор, FAQ,
  обучение. Не включены: поиск (noindex), товары, проекты, новости, политики (заглушки/черновики до публикации — их добавят
  W-25, W-06, W-05 по опубликованному контенту), корзина и сравнение (пустое состояние, D-12).
- `/` → 301 на `http://…/lt` (вид `route()`, без «/»), а не `/lt/` из текста §4.6 — по правилу §4.4 «только через route()».
- canonical страницы `?category=…` — список товаров без query (до фильтров W-25).
- hreflang не выводится на noindex-страницах (поиск, `/ru`); canonical на `/ru` — самоссылка.

### Проверки (запущены в этой сессии)

- `PATH=~/.nvm/versions/node/v22.23.1/bin:$PATH aiqme-site/bin/check` → `bin/check: OK`; PHPUnit **232 теста,
  160 passed, 72 skipped, 1577 проверок**; Pint passed; Vite build OK.
  **Замечание окружения:** в PATH этой сессии первым стоит системный Node 18.19.1 (`/usr/bin/node`), с ним шаг Vite
  `bin/check` падает (`styleText` из `node:util`, rolldown требует новее); с Node 22 из nvm — OK. Код W-02 на это не влияет.
- `PrototypeRoutesTest` (только фикстура): фикстура — 184 уникальных адреса, у каждого строка 1–14 и только `path`/`row`;
  строка 1 — 301 на `lt.home`; строка 2 (статические, включая `?category=`) — 200 и со «/», и без, одинаковое содержимое,
  canonical = APP_URL + путь без «/» и query; строки 3, 4, 5, 7, 8, 9, 12 — 404 гостю; строка 14 — 404 гостю и
  администратору; неизвестная строка — `fail`. **Пропущены с пометкой (72):** строка 2 — адреса сущностей (18:
  6+6 услуг, 3+3 статьи — W-05/W-06 после сидера W-04), строка 6 (W-06), 10 и 11 (W-07/W-15), 13 (вкладки админки — W-08 и др.).
- `LocaleRoutesTest`: все 19 страниц на LT/EN с `lang` и h1 своей локали; сегмент чужой локали — 404; неизвестные адреса —
  404 на языке пути, не главная, noindex, без canonical; 500 на обеих локалях без текста исключения (`app.debug=false`);
  `/ru…` — 404 гостю и клиенту (содержимое = 404 неизвестного адреса); администратору — 200, `X-Robots-Tag`, meta noindex,
  `lang="ru"`, EN-значения, не ключи `pages.`, без hreflang; полный head LT-страницы и взаимный hreflang с EN; описание
  без демо-фраз; поиск noindex; компонент `x-seo-head` — только переданные версии, x-default только при LT, og:image при
  изображении; переключатель; все href — абсолютные от APP_URL без завершающего «/»; чужой Host не меняет canonical.
- `SitemapTest` (DOMDocument с LIBXML_NONET, без XSD): корень `urlset` в пространстве sitemaps.org 0.9, адреса абсолютные
  от APP_URL, без дублей, ≤ 50 000; xhtml:link взаимные, x-default = LT; нет `/ru`, кабинета, админки, поиска и разделов-
  заглушек; каждый адрес sitemap — 200, canonical = он сам, index; robots.txt — `Sitemap:` APP_URL и единственный пустой `Disallow:`.
- **Тесты ловят поломки** (временные мутации, откачены): без проверки админа в `/ru`; без `forceRootUrl`; x-default не на LT;
  302 вместо 301; поиск в sitemap; без установки локали по пути — каждая роняет соответствующие тесты.
- Ручная проверка `bin/php artisan serve`: `/` 301, `/lt/` 200, `/ru/` 404 гостю, `/en/paslaugos` 404, sitemap/robots с типами.
- `git status --porcelain aiqme-site`: новые файлы только в `app/`, `lang/`, `resources/views/`, `tests/`; бинарных медиа,
  `.env`, ключей нет; `experiments/` не изменён.

### Не проверено / для следующих задач

- Поиск маркеров `bin/browser-check` (rules[7]) не выполнен: `bin/browser-check` и `marker-allowlist.json` создаёт W-03.
  Демо-фразы в `lang/` W-02 проверены вручную и тестом описаний (нет «Demonstracin», «Illustrative»).
- Вид страниц не оформлен (заготовки без CSS до оболочки W-03) — визуально **ждёт проверки пользователем** после W-03.
- Переключатель для сущностей (W-04/W-06), добавление сущностей в sitemap (W-05, W-06, W-25) — в своих задачах;
  `StaticPages::alternates()` и `<x-seo-head>` рассчитаны на передачу только существующих версий.
- Скрипт извлечения фикстуры лежит в игнорируемом `build/w02/` и в Git не попадёт; повторное извлечение не требуется (§4.6).

### Доработка после приёмки (W-02, замечание «bin/check красный»)

- Причина: шаг Vite build `bin/check` запускал первый `node` из PATH — системный Node 18.19.1, на нём rolldown (Vite 8)
  падает (`styleText` из `node:util`). Код W-02 тут ни при чём, PHPUnit/Pint были зелёными.
- Исправлено по §9.1 (`09-verification.md`: «Node стоит через nvm и может отсутствовать в PATH неинтерактивной оболочки,
  поэтому `bin/check` находит его явно»): перед `npm run build` `bin/check` добавляет в PATH новейший
  `~/.nvm/versions/node/v*/bin`, печатает путь к `node` и падает с понятным сообщением, если Node < 22. Путь в этой сессии:
  `/home/greatgleb/.nvm/versions/node/v22.23.1/bin/node`. Экспорт `NODE_BINARY` для PHPUnit и `node --test` — задача W-10, здесь не добавлены.
- Проверка: `aiqme-site/bin/check` без изменения PATH (`which node` → `/usr/bin/node`) → `bin/check: OK`; PHPUnit 232 теста,
  160 passed, 72 skipped (пропуски — как выше), Pint passed, Vite build OK.
