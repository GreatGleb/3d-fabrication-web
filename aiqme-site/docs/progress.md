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

## W-03 «Дизайн-оболочка по прототипу» — 2026-10-19, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; ручной визуальный просмотр — **ждёт проверки пользователем**). Агент не делал commit/push.

### Что сделано

- **CSS прототипа** (D-19, §7.1): `resources/css/prototype/style.css` — `style.css` прототипа (evidence `3b84c155….css`),
  правило в строке, порядок каскада исходника сохранён (поздние media-блоки и светлый подвал перекрывают ранние).
  Очистка: удалён класс демо-строки шапки `.demo-strip` (2 правила); фото `engineering.jpg` у `.hero-art` удалено
  (остаётся градиент), у `.split-image` заменено нейтральным фоном `#e9edf1`; других `url()` в CSS нет.
  `resources/css/shell.css` — исправления §7.2 (`min-width: 0`, переносы, блокировка фона и прокрутка меню на коротком
  экране — приёмы V2 `shared/responsive.css`; `[hidden]` у модального окна; третья группа подвала в ряду на ≤900 px).
  `app.css` больше **не импортирует Tailwind**: его preflight (сброс `h4`, списков, рамок) менял бы вид прототипа,
  утилиты оболочке не нужны; плагин `@tailwindcss/vite` в `vite.config.js` оставлен без изменений.
- **Логотип** (D-25): копии `legacy-landing/site/assets/images/logo-dark.png`, `logo-light.png` → `public/images/`
  (176 и 252 КБ; `legacy-landing/` не менялся). В шапке и подвале — `logo-dark.png` (оба фона белые); `logo-light.png`
  скопирован по D-25, в оболочке не используется.
- **Blade-компоненты** (`resources/views/components/`): `layout` (подключает `@vite`, шапку, подвал, cookie-плашку,
  `main#main[tabindex=-1]`, `theme-color` прототипа), `header` (логотип, 7 пунктов, поиск, LT/EN, корзина, кнопка меню;
  активный пункт — текущая страница и вложенные `<locale>.<page>.…`, `aria-current`), `header-cta` (второй ряд),
  `footer`, `breadcrumbs`, `page-top` (pageTop прототипа: крошки, ярлык «AIQME», h1, вводный текст), `button`, `card`,
  `field` (input/textarea/select, ошибка под полем с `aria-invalid`/`aria-describedby`, `old()`, пароль не возвращается),
  `modal` (разметка `.search-overlay > .search-panel[role=dialog]`), `table` (`.data-table` с собственной прокруткой),
  `empty`, `placeholder` («Informacija ruošiama» · «Information is being prepared»), `cookie-notice`, `status`
  (постоянный блок статуса §7.2: `.notice`, `role=status|alert`, по умолчанию `session('status')`), `line-icon`
  (иконки прототипа; имя `x-icon` занято blade-icons из Filament). Мобильное меню — тот же `nav#main-nav` с кнопкой.
- **Тексты оболочки** `lang/lt|en/shell.php` — из видимого текста шапки/подвала/cookie-плашки `verified-route-screens/lt|en/dom.html`
  и `t('LT','EN')` app.js. Не перенесены (D-21): «/ DEMONSTRACINĖ SVETAINĖ» · «/ DEMONSTRATION WEBSITE», «· Demonstracinis
  prototipas» · «· Demonstration prototype» в подвале, `<noscript>` целиком, первое предложение cookie-плашки, группа
  «Prototipo aplinkos» · «Prototype workspaces» со всеми ссылками, ярлык «AIQME / DEMO» и «Demo dialog» модального окна.
- **JS**: `resources/js/menu.js` — механизм V2 `shared/app.js` на разметке прототипа (aria-expanded синхронно с видом,
  в том числе после Escape — F-46; возврат фокуса; Tab по кругу кнопка ↔ пункты — кнопка в DOM после ссылок, поэтому
  цикл явный; блокировка фона; щелчок вне и смена брейкпоинта закрывают). `resources/js/modal.js` — `data-modal-open`,
  Escape/фон/крестик закрывают, ловушка Tab, `inert` фона, возврат фокуса. Cookie-плашка — в `app.js`.
- **`SecurityHeaders`** (§6.6), глобальный middleware: CSP enforce — `default-src 'self'`; `script-src 'self' 'nonce-…' 'unsafe-eval'`;
  `style-src` и `style-src-elem 'self' 'nonce-…'`; `style-src-attr 'unsafe-inline'`; `img-src 'self' data:`;
  `frame-ancestors 'none'`; `object-src 'none'`; `base-uri 'self'`; `form-action 'self'`; nonce — новый на каждый ответ,
  передаётся `Vite::useCspNonce()` (Livewire берёт его через `Vite::cspNonce()`); `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: same-origin`. Действует и на 404/500, редирект, sitemap, robots.
- **Страницы**: заготовка `pages/static` выводит `page-top` (на главной без крошек; вводный текст — description страницы,
  если он есть в `lang/*/pages.php`); 404/500 — разметка `notFound()` прототипа (`page-top` + `.empty` + кнопка).
- **`bin/browser-check`** (Python Playwright, bundled Chromium) и `tests/browser/` (`ClientFactory` — e-mail
  `client-####@aiqme.test`, имя без маркеров; `BrowserDataSeeder` запускается по имени класса). Подробно —
  `docs/verification/w03-design-shell.md`. Порт фиксированный **8791** (занят — отказ до старта); сервер
  `bin/php -S` с роутером Laravel (рабочий каталог `public/`, как `artisan serve`), окружение `DB_DATABASE=aiqme_browser`,
  `QUEUE_CONNECTION=sync`, `APP_URL=http://127.0.0.1:8791` (иначе ссылки и ресурсы шли бы на `localhost:8000` из `.env`),
  `APP_DEBUG=false`. Перед `migrate:fresh` скрипт отказывается при `bootstrap/cache/config.php` и проверяет
  `config:show database.connections.pgsql.database == aiqme_browser`; БД создаётся `createdb` в контейнере, если её нет.
  Пароли администратора и клиента — `secrets.token_urlsafe` на каждый запуск, только в памяти (stdin/переменная окружения
  подпроцесса). Пути страниц — аргументами (`bin/browser-check /lt /en/services`), без аргументов — все GET `/lt|/en` из
  `route:list` + две 404. Отчёт — `build/verification/browser-check/` (`summary.md`, `report.html`, `shots/`).
- **`tests/fixtures/marker-allowlist.json`** — пустой список (совпадений нет); формат записи `{"page", "phrase", "reason"}`
  описан в шапке `bin/browser-check`; запись разрешает маркер, только если точная фраза покрывает совпадение на этой странице.
- **Тесты (изменены)**: `tests/TestCase.php` — `withoutVite()` (PHPUnit идёт до сборки Vite в `bin/check`; в чистом клоне
  манифеста нет). `LocaleRoutesTest` (W-02): ссылка «к содержимому» `#main` исключена из проверки абсолютных ссылок;
  переключатель проверяется без закрывающей `>` (у ссылки теперь класс прототипа `icon-btn lang`); на `/ru` вместо
  «нет ссылок на /ru, кроме canonical» — «переключатель ведёт на LT, `hreflang="ru"` нет» (§4.3: RU нет в публичном
  переключателе; навигация шапки администратора на `/ru` остаётся внутри RU).

### Решения по неопределённому (простейший вариант)

- **Значка кабинета в шапке нет** до W-07 (маршрута кабинета ещё нет; мёртвая ссылка хуже). W-07 добавляет его.
- **Поиск в шапке** — обычная ссылка на страницу поиска (`/lt/paieska` · `/en/search`); модальное окно поиска — W-06 (§1.2).
- **Cookie-плашка**: текст — второе предложение прототипа «Analitika ir reklamos sekimas nenaudojami.» · «No analytics or
  advertising tracking is used.», кнопки «Tik būtini» · «Essential only» и «Nustatymai» · «Settings» (ссылка на страницу
  cookies). Отметка о подтверждении — `localStorage` (`aiqme-cookie-notice`), без cookie. Плашка выводится с `hidden`
  и показывается скриптом (без JS не показывается — она только информирует). Всплывающее «Pasirinkimas išsaugotas…» не перенесено.
- **Подвал**: «© {текущий год} AIQME» (в прототипе «© 2026 AIQME · Demonstracinis prototipas»).
- **Модальное окно**: ярлык «AIQME / DEMO» прототипа → «AIQME»; доступное имя — заголовок окна (`aria-labelledby`)
  вместо «Demonstracinis langas» · «Demo dialog».
- Ошибка поля — `<small>` под полем без нового цвета (в CSS прототипа стиля ошибки поля нет).
- Демо-классы в CSS: удалён только `.demo-strip`; классы Telegram-имитации (`.phone-shell`, `.tg-*`, `.bubble`) и другие
  оставлены как CSS страниц следующих задач.
- Dev-сервер Vite (`npm run dev`) политикой CSP не разрешён — страницы проверяются на собранных ресурсах (`npm run build`).

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **249 тестов, 177 passed, 72 skipped**
  (пропуски — прежние W-02), Vite build OK (`node: ~/.nvm/versions/node/v22.23.1/bin/node`).
- Новые Feature-тесты: `SecurityHeadersTest` (заголовки на странице, 404, `/ru` гостю, 301, sitemap, robots, 500 и `/ru`
  администратора; CSP enforce, не Report-Only; nonce в `script-src`/`style-src`/`style-src-elem`, нет `'unsafe-inline'`
  в них, `style-src-attr 'unsafe-inline'`; nonce новый на каждый ответ и равен `Vite::cspNonce()`), `ShellTest` (нет надписей
  оболочки D-21, в том числе «Prototipo aplinkos»/«Prototype workspaces» и ссылок группы, на LT/EN, страницах и 404;
  пункты меню, активный пункт, инструменты, второй ряд LT/EN; подвал; cookie-плашка без выбора аналитики; логотип —
  побайтная копия файлов legacy; `page-top`; CSS без `url(` и «demo», токены, светлый подвал после тёмного, без Tailwind),
  `ShellComponentsTest` (поле с ошибкой и `old`, select/textarea с экранированием, блок статуса и `session('status')`,
  модальное окно, кнопки, карточка, таблица, пустое состояние, заглушка LT/EN).
- **Тесты ловят поломки** (временные мутации, откачены): `'unsafe-inline'` в `script-src` → падает тест CSP;
  «Demonstracinis prototipas» в подвале → падают 2 теста `ShellTest`.
- **`bin/browser-check`** (без аргументов) → **OK, 3336 проверок**, 40 страниц, ≈ 5 мин; чужих хостов нет; `/ru` (19) —
  «пропущено: вход появится в W-07». Контрольные поломки (каждая поймана, откачена): демо-строка в шапке → маркеры
  «demo»/«demonstr»; Escape без смены `aria-expanded` → «после Escape aria-expanded=true (F-46)» и «фон заблокирован»;
  inline `<script>` без nonce → нарушение CSP в консоли; элемент 2000 px → «документ шире окна»; `width/height` у логотипа
  → «логотип не помещается в шапку» (эту ошибку я сначала допустил сам и нашёл по кадрам — после этого добавлена проверка).
- Кадры 1440×1000, 768×1024, 390×844 всех 40 страниц и `report.html` рядом с эталоном `evidence/` — в
  `build/verification/browser-check/`. Я сравнил `/lt/paslaugos` (desktop, phone) и `/en/faq` с эталоном: шапка, второй
  ряд, `page-top`, подвал совпадают по виду (отличия — нет значка кабинета и демо-строки, как решено выше).
  **Ручной просмотр остальных кадров — ждёт проверки пользователем.**
- `git status --porcelain aiqme-site`: новые файлы в `app/`, `bin/`, `docs/`, `lang/`, `public/images/` (2 PNG < 1 МБ),
  `resources/`, `tests/`; `.env`, ключей, `.codex/`, `.mcp.json` нет; `experiments/` не изменён.

### Не проверено / для следующих задач

- Значения hero §7.1 (h1 `clamp(54px, 5.1vw, 82px)`, абзац 21 px, `#c2cad4`) — hero появится в W-05; сверка порядка блоков — W-05.
- Размеры h2/h3 не сверяются: на страницах оболочки их нет (только h1 `page-top`).
- Модальное окно проверено на синтетической странице самопроверки с собранным JS приложения; на страницах — с W-06/W-09.
- Вход администратора и клиента через форму — W-07 (пароли уже создаются, но не используются).
- Livewire на страницах ещё нет: nonce для `@livewireScripts` берётся из `Vite::cspNonce()` (проверено чтением
  `FrontendAssets::nonce()` и тестом равенства nonce), в браузере — после появления компонентов.
- Реальные устройства, Safari/Firefox, Lighthouse — не в W-03.

## W-04 «Модель контента и ContentService» — 2026-10-19, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; правки текстов D-21 — **ждут проверки пользователем**). Агент не делал commit/push.

### Что сделано

- **Таблицы** (`database/migrations/2026_10_19_100000_create_content_tables.php`): `content_items` (type, key, position,
  `meta` — непереводимые атрибуты: группа FAQ, раздел/категория статьи, услуги и применение проекта, дата и формат обучения;
  уникально `(type, key)`), `content_translations` (locale, title, slug, fields, SEO, `revision`, `published_revision`,
  `published_at`; уникально `(content_item_id, locale)` и `(locale, type, slug)`), `content_revisions` (все версии с автором и
  каналом), `ui_texts` (locale, key, value, revision). Таблицы `media` и `activity_log` уже создаёт Lunar — новых миграций
  medialibrary/activitylog нет, новых пакетов нет.
- **Модель публикации**: черновик — последняя строка `content_revisions`; столбцы `content_translations` — опубликованная
  версия, которую видит сайт. Сохранение не меняет опубликованное; «Опубликовать» копирует последнюю версию в перевод своей
  локали (другие локали не трогает); «Снять» обнуляет `published_at`; восстановление старой версии создаёт новую.
- **`App\Domain\Content\ContentService`** — единственная точка записи: `createItem`, `updateItem`, `saveDraft`, `publish`,
  `unpublish`, `restore`, `saveUiText`, `addMedia` и `import` (только для сидера, канал system). Каждый метод, кроме `import`,
  проверяет `Gate::define('admin')` (AppServiceProvider: `is_admin`). Все изменения перевода и публикация принимают
  ожидаемую ревизию; расхождение → `ValidationException` по `revision` с текстом `content.stale` (в вебе форма сохранит ввод).
  Набор полей задан кодом (`ContentType::fields()`); у `page_text` — блоками, созданными сидером (новый блок не добавить —
  без конструктора страниц); значения — только текст (строки и вложенные списки строк). slug — только у service, project,
  article, product, category (`^[a-z0-9]+(-[a-z0-9]+)*$`), занятый slug на той же локали/типе при публикации отклоняется.
- **`ContentType`** — типы §4.1: page_text, service, project, article, training, product, brand, category, faq_item.
- **`PublishedContent`** (чтение для W-05/W-06): `list` (по порядку, отбор по `meta`), `find` по slug, `page` (на `/ru` без RU —
  EN, §4.3), `showsPlaceholder` (заглушка, пока на локали нет публикации), `switchUrl` — переключатель языка для сущностей:
  опубликованная альтернатива `route('<locale>.<раздел>.show', ['slug' => …])`, если такой маршрут есть, иначе список раздела
  (статья раздела «новости» — список новостей, иначе библиотека), иначе главная другой локали.
- **`Markup::html()`** — вывод длинных текстов: Markdown (`Str::markdown`, `html_input=escape`, `allow_unsafe_links=false`)
  + белый список тегов `p br strong em ul ol li a`. HTML/JS из текста не исполняется.
- **ui_text**: `UiTextLoader` оборачивает `translation.loader` (`AppServiceProvider::register`, `extend`): строки `lang/` +
  поверх них `ui_texts` той же локали. Работает для любого `__()`, включая оболочку W-03; RU без значения → EN (файл + БД).
  Ключ должен существовать в `lang/lt|en` (строка, не группа); `null` убирает переопределение; кеш переводчика сбрасывается.
- **Медиа**: `ContentItem` — `HasMedia`; коллекции `images`, `video`, `video_mobile`, `video_poster`; WebP-варианты `w480`,
  `w960`, `w1600` (`Fit::Max`, без увеличения) для изображений и постеров. `addMedia` — формат по сигнатуре (JPEG, PNG, WebP,
  MP4; SVG и прочее отклоняются), имя файла UUID, `alt` на локаль и необязательный источник (текст + только http(s)-ссылка)
  в custom properties. Диск — по умолчанию medialibrary (`public`, `storage/`, игнорируется Git).
- **Журнал** `App\Domain\Audit\AuditLog` (activitylog, log_name `audit`) + `Channel` (web, telegram, stripe, system):
  `content.published` / `content.unpublished` с каналом, локалью, ревизией, автором и переводом.
- **`database/seeders/data/prototype-content.json`** (56 КБ, 36 элементов) — извлечён один раз скриптом
  `build/w04/extract.py` (игнорируемый `build/`, в Git нет) из `data-from-source.json` и видимого текста `dom.html`
  (стандартный `html.parser`, только чтение как текст). Только тексты: без разметки, атрибутов, ссылок, медиа.
  - Опубликованы: page_text `home`, `about`, `contact`, `training` (вводная панель «Programa jums arba jūsų komandai…»),
    6 услуг (title, slug своей локали, short, подпись, 3 пункта блока, описание, проблемы, provide, receive), 9 faq_item
    (по одному в группе каждой услуги, `services` — «Nuo ko priklauso terminas?», `general` — 2 вопроса: аккаунт и самовывоз),
    3 статьи (раздел `library`, slug = id, категория = cat, дата — момент импорта), 5 категорий (slug как в `?category=`).
  - Черновики (дословно): 4 политики (page_text `privacy`, `cookies`, `delivery`, `returns`), 3 проекта (EN — из
    `data-from-source.json`, а не LT-текст EN-страниц прототипа), 2 темы обучения (дата, формат, название, описание).
  - Не импортированы: демо-товары, бренды, новости, цены, медиа и видео-ссылки третьих лиц, блок команды «О нас» (D-32).
- **`PrototypeContentSeeder`** читает только этот JSON; `import()` создаёт лишь отсутствующие элементы `(type, key)` —
  повторный запуск ничего не перезаписывает. Запуск: `bin/php artisan db:seed --class='Database\Seeders\PrototypeContentSeeder'`.
  `bin/browser-check` теперь запускает его после `migrate:fresh` (`CONTENT_SEEDERS`).

### Правки текстов по таблице 1 D-21 — ждут проверки пользователем

Все — только в публикуемых элементах, LT и EN; остальное перенесено дословно. «Удалено» — фраза не перенесена.

| Где | LT | EN | Действие |
|---|---|---|---|
| Главная, процесс, шаг 4 | «…atsiimti iš anksto apmokėjus.» → «Patikriname rezultatą ir paruošiame jį pristatyti arba atsiimti.» | «…or prepaid collection.» → «We check the result and prepare it for delivery or collection.» | заменено |
| Главная, hero | «Gamybos iliustracija ·» (вместе со ссылкой на видео третьего лица) | «Manufacturing illustration ·» | удалено |
| Главная и «Услуги», блок услуг | «Demonstracinė iliustracija» (подписи 6 иллюстраций) | «Illustrative image» | удалено |
| Главная | «Planuojami prekių ženklai. Partnerystės…», «Planuojamas asortimentas…», «Demonstraciniai AIQME informaciniai straipsniai.» | «Planned brands…», «Planned range…», «Illustrative AIQME informational articles.» | удалено |
| «О нас» | «Demonstracinis vaizdas · tikros darbo aplinkos nuotraukos vieta» | «Illustrative image · placeholder for real workplace photography» | удалено |
| «О нас» | блок «Žmonės už sprendimų» + текст | «The people behind the solutions» + текст | удалено (D-32) |
| FAQ услуги обучения | «Viešai rodomos prototipo datos yra demonstracinės.» | «Public dates in this prototype are illustrative.» | удалено, первое предложение осталось |
| Общий FAQ (аккаунт) | «…išsaugoti modelius ir taikyti administratoriaus nustatytą nuolaidą.» → «Paskyroje galima sekti užsakymus ir išsaugoti modelius.» | → «An account supports order tracking and saved models.» | заменено |
| Общий FAQ (самовывоз) | → «Taip, suderinus atsiėmimo vietą bei laiką.» | → «Yes, after agreement on the collection location and time.» | заменено |
| Контакты | notice «Demonstracinis prototipas. Kainos…» | «Demonstration prototype. Prices…» | удалено |
| Контакты | строка «Darbo laikas: tikslinamas» | «Opening hours: to be confirmed» | удалено |
| Контакты, форма | «Naudokite tik demonstracinius duomenis.» (осталось «* — būtini laukai.») | «Use demonstration data only.» («* — required fields.») | удалено |
| Контакты, форма | «Failai niekur nesiunčiami…», флажок «Suprantu, kad tai demonstracinė užklausa…» | «Files are not sent anywhere…», «I understand this is a demonstration enquiry…» | удалено |
| Контакты, форма | «Peržiūrėti užklausos patvirtinimą» → «Siųsti užklausą» | «Preview enquiry confirmation» → «Send enquiry» | заменено |

### Решения по неопределённому (простейший вариант)

- **Состав page_text** (блоки — W-05 выводит их): главная — eyebrow/заголовок/текст hero, 3 этапа, сценарии, процесс (общий
  и для страниц услуг, §4.1), заголовки разделов, CTA-полоса (`cta_title`/`cta_text` — один источник; на «О нас» и «Услугах»
  тот же текст); «О нас» — вводный блок и 3 панели; контакты — заголовок, реквизиты (подпись + значение), заметка о визите,
  заголовок, вводная строка и кнопка формы (кнопка и вводная строка — в page_text, т. к. их правит таблица D-21; подписи полей
  формы — дело W-09); обучение — вводная панель; политики — intro, notice, разделы (заголовок + текст, абзацы через пустую
  строку). Надписи кнопок и ссылок страниц (кроме формы контактов), `<h1>` и вводный абзац page-top остаются в `lang/` (W-02,
  W-05). `title` перевода page_text = `<h1>` страницы (для админки).
- Синяя точка в конце h1 hero — декоративный `<span>`: в `hero_title` без точки. Перенос строки `<br>` в заголовках — `\n`.
- Подпись блока услуги хранится без номера («AIQME», «VIENETINIAI GAMINIAI…»): номер «01 / » — порядок услуги.
- Группа FAQ услуги — ключ услуги (`scanning`, …) из `data-from-source.json`, а не локализованный slug.
- Кнопки политик («Išvalyti šios demonstracijos duomenis», «Leisti pasirenkamus (demo)», «Aprašyti neatitikimą») не перенесены:
  это интерфейс, не текст политики.
- `ui_text` применяется сразу при сохранении (без черновика), с той же проверкой ревизии.
- Тексты ошибок сервиса (`lang/*/content.php`) в прототипе отсутствуют — короткие нейтральные: «Įrašas pakeistas kitame lange.
  Atnaujinkite puslapį ir pakartokite.» · «Changed in another window. Reload the page and try again.»; «Netinkama reikšmė: :field.»
  · «Invalid value: :field.»; «Toks adresas jau naudojamas.» · «This address is already in use.».
- Удаления элементов в сервисе нет (в W-04 не требуется; удаление — веб-админка W-08/W-26).
- Карточки сущностей ещё не существуют: `switchUrl` ждёт маршрутов `<locale>.<раздел>.show` с параметром `slug` (W-05/W-06/W-25).

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **277 тестов, 205 passed, 72 skipped** (пропуски — прежние
  W-02), Vite build OK.
- Новые тесты: `ContentServiceTest` (12) — неадминистратор не может создать/изменить/опубликовать/снять/восстановить/
  переопределить ui_text/добавить медиа и ничего не меняется; черновик не публикует; публикация LT не публикует EN и LT не
  подставляется под EN; правка опубликованного не видна до повторной публикации; устаревшая ревизия отклоняется в
  saveDraft/publish/unpublish без перезаписи; восстановление → новая версия; журнал публикации/снятия с каналом
  telegram/web, локалью, автором; поля по типу, только текст, slug; блоки page_text фиксированы; занятый slug; заглушки по
  локали и разделу; переключатель языка (временные маршруты `*.services.show` в тесте); RU page_text → EN.
  `UiTextTest` (4) — значение из БД в шапке `/lt` (подвал со своим ключом не меняется), `/ru` администратора — EN-значение из
  БД, не ключ; снятие переопределения и устаревшая ревизия; только существующие строковые ключи. `MarkupTest` (3, Unit) —
  Markdown-подмножество; `<script>`, `<img onerror>`, атрибуты экранируются; `javascript:`/`data:` ссылки, изображения,
  заголовки, код снимаются. `ContentMediaTest` (2) — PNG 2000×1000 → WebP 480/960/1600 (тип и ширина проверены `getimagesize`),
  имя UUID, alt LT/EN, источник; SVG под `.png`, изображение в коллекции видео, неизвестная коллекция и `javascript:`-источник
  отклоняются. `PrototypeContentSeederTest` (7) — список фраз теста совпадает с цитатами таблицы 1 в
  `docs/planning/edition-2/decisions.md` (без плана — пропуск); в публикуемых элементах JSON нет исходных фраз D-21, слов
  tikslinamas / to be confirmed, новые и остающиеся фразы есть; JSON без тегов, медиа, ссылок и `-demo`, сидер не читает
  evidence; 6 услуг со slug прототипа и полями блока, 3 статьи библиотеки, 5 категорий, опубликованные страницы, без блока
  команды; политики, проекты и обучение — черновики, дословно; FAQ: 9 вопросов на локали без повторов, группы по 1, general — 2;
  повторный запуск ничего не перезаписывает (правка и снятие с публикации сохраняются).
- **Тесты ловят поломки** (временные мутации, откачены, файлы сверены `cmp`): без проверки прав в `publish`; без проверки
  ревизии; публикация всех локалей сразу; без загрузчика ui_text; демо-фраза возвращена в JSON; `import` перезаписывает;
  дубль FAQ в general; канал журнала всегда `web` — каждая роняет свой тест.
- `bin/php artisan migrate` + сидер дважды на dev-БД `aiqme`: «создано 36», затем ничего; 54 опубликованных перевода,
  54 записи журнала (system).
- `bin/browser-check /lt /en` → **OK (251 проверка)**; в `aiqme_browser` после прогона 36 элементов, 54 опубликованных перевода.
  Шаблоны в W-04 не менялись — поиск маркеров по своим страницам не требуется (rules[7]); прогон подтверждает, что сидер
  встроен в подготовку данных.
- `git status --porcelain aiqme-site`: новые файлы — `app/Domain/`, миграция, сидер и JSON, `lang/*/content.php`, тесты;
  изменены `AppServiceProvider.php`, `bin/browser-check`; бинарных медиа, `.env`, ключей нет; `experiments/` не изменён.

### Не проверено / для следующих задач

- Вывод контента на страницах (W-05/W-06), sitemap сущностей, маршруты карточек — в своих задачах; страницы пока показывают
  заготовки W-02/W-03.
- Видео: обработка MP4 (постер, мобильный вариант) — только приём по сигнатуре; конверсий видео нет (§4.4 — W-05/W-08).
  Лимиты размера медиа (§6.4) применяет загрузка в админке (W-08).
- Правки D-21 в таблице выше и выбор блоков page_text — **ждут проверки пользователем**.

## W-05 «Публичные страницы I: главная, услуги, о нас, контакты, FAQ, политики» — 2026-10-20, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; ручной визуальный просмотр кадров — **ждёт проверки пользователем**). Агент не делал commit/push.

### Что сделано

- **Маршруты** (`routes/web.php`): главная, список услуг, «О нас», контакты, FAQ — `App\Http\Controllers\Public\PageController`;
  4 политики — `PageController::policy` (`StaticPages::POLICIES`); страница услуги `<locale>.services.show`
  (`/lt/paslaugos/{slug}` · `/en/services/{slug}`, `ServiceController`): slug ищется среди опубликованных переводов своей локали,
  иначе 404 (slug другой локали — 404; на `/ru` без RU-перевода — 404, §4.3). Остальные страницы — прежние заготовки W-02/W-03.
- **Шаблоны** `resources/views/public/` с классами прототипа: `home` (hero, сценарии, процесс, услуги, бренды, категории, новости, CTA),
  `services`, `service`, `about`, `contact`, `faq`, `policy` и `partials/` (`service-features`, `process`, `cta`, `training-topics`,
  `project-card`, `ticker-toggle`, `media`, `art`). Тексты — опубликованный контент сидера W-04 (page_text, service, faq_item,
  category, article, brand); надписи шаблонов — `lang/lt|en/public.php` из видимого текста `dom.html` и `t('LT','EN')` app.js.
  Пока page_text страницы не опубликован — заглушка «Informacija ruošiama» · «Information is being prepared».
- **Главная**: h1 hero — `hero_title` + синяя точка; строка «01 SCAN → 02 DESIGN → 03 MANUFACTURE» — текст; сцены
  «СКАН → CAD → ДЕТАЛЬ» и крыльчатки нет (D-40). Сценарий 1 → страница услуги `modelling`, 2 → калькулятор, 3 → товары (как в
  прототипе). Категории — опубликованные `category` со ссылками `?category=<slug>`. Бренды — опубликованные `brand` (логотип из медиа,
  иначе название), aria-label группы «Prekių ženklai» · «Technology brands» (D-21); новости — только опубликованные статьи раздела
  `news` (до 6 последних, D-26), карточка ведёт на `library.show`, если маршрут уже есть (W-06), иначе на список новостей;
  без публикаций — заглушка без ленты и без кнопки паузы. Дубль группы ленты — `aria-hidden`, как в прототипе.
- **Страница услуги**: верх «AIQME / SERVICES», блок «When it helps» (описание, проблемы), CTA печати → калькулятор, остальных →
  контакты с `?service=<slug>` (контракт §1.2; читает W-09); иллюстрация — медиа услуги или своя заглушка без подписи прототипа;
  «What should you provide / receive»; у обучения после первого `div.grid.two.gap` — блок «Mokymų temos ir datos» (заглушка списка,
  панель — page_text `training` с кнопкой в контакты; ярлыка расписания нет); процесс — общий page_text главной; пример — первый
  опубликованный проект с этой услугой в `meta.services` (карточка без демо-ярлыков; ссылка — `projects.show`, если маршрут есть,
  иначе список проектов), без него заголовка примера и карточки нет, панель «One point of contact…» — всегда; FAQ — группа услуги,
  затем `services`.
- **FAQ**: группы услуг в порядке опубликованных услуг, затем `general` (8 вопросов, без `services`). **«О нас»**: вводный блок,
  фото — медиа page_text `about` или заглушка, 3 панели; блока команды нет (D-32). **Контакты**: реквизиты (телефон → `tel:`,
  e-mail → `mailto:`), ссылка «Open map ↗», форма (поля прототипа, select услуг из контента + «Equipment quotation», файлы, дата,
  кнопка «Send enquiry»; без демо-строк D-21). **Политики**: черновик → заголовок + заглушка; опубликованная — вводный абзац,
  предупреждение и разделы (Markdown-подмножество `Markup::html`).
- **SEO** (§4.4, §7.2): `<title>`/description/og — SEO-поля перевода, иначе заголовок и вводный абзац (главная — текст hero, услуга —
  `short`, «О нас» — первый абзац блока, а не слоган подвала; без абзаца — общее описание сайта). Перенос строки (`\n`) заголовка —
  `<br>` в h1 (`x-page-top`), пробел в крошках и `<title>`. hreflang и переключатель услуги — только опубликованные версии;
  нет версии — переключатель ведёт на список услуг другой локали (`PublishedContent::switchUrl`, новый проп `switchUrl` у
  `x-layout`/`x-header`). og:image — вариант w1600 иллюстрации услуги / постера hero, если они есть.
- **sitemap.xml** (`SitemapController`): статические страницы W-02 + политики только на локалях, где они опубликованы, + услуги —
  каждая версия с alternates только опубликованных локалей (x-default — если есть LT).
- **Медиа** (`public/partials/media.blade.php` — один компонент): изображение — WebP-варианты w480/w960/w1600 в `srcset` (ширина не
  больше оригинала, без повторов), `sizes`, `width/height` оригинала, `loading=lazy` (eager — иллюстрация первого экрана услуги),
  `alt` из подписи локали; видео hero — `muted loop playsinline preload="metadata"`, постер (w1600), `<source media="(max-width:
  700px)">` мобильного MP4 и основной MP4; без медиа — своя SVG-заглушка (`partials/art`, линейная схема детали). Подпись источника hero
  — из custom property `source` видео (текст, ссылка ↗); без видео нет ни подписи, ни кнопки.
- **JS**: `resources/js/hero-video.js` — механизм initHeroVideo прототипа: без autoplay-атрибута, играет только при видимой вкладке,
  hero в окне (IntersectionObserver), без reduced-motion и saveData либо после нажатия «Play»; кнопка паузы; ошибка — только у
  последнего `<source>` (неподходящий `media` тоже даёт error) → постер и сообщение для экранного диктора. `resources/js/tickers.js` —
  кнопка паузы лент (`data-paused` / `data-news-paused`, `aria-pressed`, подпись Pause ↔ Resume); движение и reduced-motion — CSS прототипа.
- **CSS**: `resources/css/public.css` (новый, подключён в `app.css`) — только размеры и цвет SVG-заглушки и изображений в рамках
  иллюстраций прототипа (`.service-feature-media`, `.split-image`, `.project-image`, новая `.media-frame` для иллюстрации услуги:
  `.sprite` прототипа рассчитана на спрайт-лист и ломала бы обычное изображение).
- **Фикстуры**: `tests/fixtures/prototype-blocks.json` — один раз извлечён скриптом `build/w05/extract_blocks.py` (игнорируемый
  `build/`) из `dom.html` 22 страниц (главная, список услуг, 12 услуг, «О нас», контакты, FAQ ×2 локали): прямые дети `main` и
  `div.wrap.page-content` как «тег.классы», без текстов; исключены `div.notice`, `div.notice.warning`, `div.inset.gap` «О нас».
  `tests/fixtures/text-allowlist.json` — законные расхождения текста (страница, фраза, причина). `marker-allowlist.json` — фразы услуги
  3D-печати с «prototype/prototipai» (D-21: услуга, а не демо-пометка).
- **`bin/browser-check`** дополнен: страницы услуг — из `sitemap.xml`; getComputedStyle hero (h1 `clamp(54px,5.1vw,82px)` / 52 px ≤700,
  1,06, −0,047em, max-width 950; абзац 21/18 px, 1,55, 650 px, `#c2cad4`), всех видимых h2 в main (500, 1,12, −0,045em) и заголовков
  карточек услуг (`clamp(29px,2.6vw,38px)`, ≤1050 — 30 px, 1,13, −0,04em) на 1440/768/390; порядок блоков по фикстуре (заголовок
  примера — только при карточке проекта); сверка текста с эталоном; медиа/заглушка отрисованы во всех рамках (`naturalWidth > 0`
  или видимый SVG, нет `/assets/`) на 1440/390; reduced-motion на `/lt` и `/en`. Данные: `tests/browser/ContentFactory.php`
  (через ContentService: 2 бренда, 2 новости, медиа hero), ролик hero 2 с H.264 (+ мобильный 360×640 и постер PNG) создаёт `ffmpeg`
  во временном каталоге; `storage:link`, если ссылки нет. Отчёт расхождений текста — `build/verification/browser-check/text-discrepancies.md`.
- **Прочие правки**: `x-page-top` — перенос заголовка; `x-line-icon` — иконки `check`, `scanner`, `printer` прототипа;
  `PrototypeRoutesTest` — адреса услуг строки 2 (200) и строки 3 (404) проверяются после сидера W-04 (статьи — пропуск до W-06);
  `PrototypeContentSeederTest::D21_TABLE_1` стал `public` (используется проверкой страниц).

### Решения по неопределённому (простейший вариант)

- **Карта Google не встраивается**: только ссылка «Open map ↗» (§1.2 «ссылка на карту»): iframe — внешний сервис (D-20), его не
  пропускает CSP (`default-src 'self'`) и `bin/browser-check`. Адрес ссылки — строка `public.contact.map_url` (lang, правится ui_text).
- **Форма контактов** отправляет `POST` на адрес контактов, обработчика нет до W-09 (сейчас ответ 405). Подписи полей — из прототипа
  в `lang/*/public.php`; W-09 добавит обработку и предвыбор `?service=`.
- Нейтральных надписей, которых нет в прототипе, не добавлялось; заглушка разделов — существующая «Informacija ruošiama» (W-03).
- Лента новостей — до 6 последних публикаций (число в прототипе не задано; показано 3).
- Светлая подложка иллюстраций у «Graviravimas» и «3D modeliavimo mokymai» сохранена по ключу услуги, как в прототипе.
- Политики — в sitemap только опубликованные на своей локали; hreflang заглушки политики — обе версии, как в W-02.
- Описание FAQ (вводного абзаца нет) — общее описание сайта `pages.default_description` (не слоган подвала).

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **297 тестов, 237 passed, 60 skipped** (пропуски: статьи
  строки 2 §4.6 — W-06, строки 6/10/11/13 — W-06/W-07/W-08…), Vite build OK.
- **`bin/browser-check`** без аргументов → **OK, 6194 проверки, 0 ошибок**, 52 страницы (38 статических LT/EN, 12 услуг из sitemap,
  две 404), чужих хостов нет; `text-discrepancies.md` — «Расхождений нет» (законные отличия — `text-allowlist.json`: подпись
  источника hero, демо-бренды, статьи библиотеки в ленте вместо новостей, исправленный `<br>` в крошках «О нас»); reduced-motion на
  `/lt` и `/en` с роликом ffmpeg — пройдено (с reduce: `paused`, без autoplay, постер 200, ленты без анимации и дублей; без reduce:
  видео играет, ленты движутся, пауза останавливает, `aria-pressed=true`). После прогона найдена и исправлена ошибка (код компании
  «308110786» выводился ссылкой `tel:`; телефон теперь — только с «+», тест дополнен); повтор `bin/check` — OK,
  `bin/browser-check /lt/kontaktai /en/contact` — OK (269).
- Новые Feature-тесты: `PublicPagesTest` (10: главная из контента без сцены, заглушки лент и лента только новостей с aria-label
  «Prekių ženklai»/«Technology brands», 6 услуг 200 со своим slug и 404 с чужим, общий процесс и FAQ услуги + services, нет ссылок
  на черновые проекты и пример после публикации проекта, блок тем обучения с панелью page_text, порядок FAQ, «О нас» без блока
  команды, контакты, политики-заглушки и опубликованная политика), `PublicSeoTest` (5: SEO-поля в title/description/og, перенос в
  h1/крошках/title и описание «О нас» не из слогана, hreflang и переключатель только опубликованных, sitemap: публикация EN добавляет
  адрес и alternate, снятие убирает, политики-черновики не в sitemap), `PublicMediaTest` (3: srcset/sizes/width/height/lazy/alt,
  варианты не шире оригинала, заглушка без медиа и без `/assets/`, видео hero preload=metadata, мобильный источник, постер, подпись
  источника), `PublicTextsTest` (2: таблица 1 D-21 на 30 страницах LT/EN — исходных фраз нет, новые есть; словарь — на EN-страницах
  нет элемента/атрибута, равного LT-строке lang/lt с LT ≠ EN). `PrototypeRoutesTest` — адреса услуг §4.6 строк 2 и 3.
- **Тесты ловят поломки** (временные мутации, откачены): LT-строка на EN-странице → словарный тест; «Planuojami prekių ženklai» в
  aria-label → тест D-21; группа services на странице FAQ → тест FAQ; alternates услуги без учёта публикации → тест sitemap.
- `git status --porcelain aiqme-site`: новые файлы — `app/Http/Controllers/Public/`, `lang/*/public.php`, `resources/{css,js,views/public}`,
  `tests/…`, фикстуры; бинарных медиа, `.env`, ключей нет; `public/storage` (ссылка) игнорируется; `experiments/` не изменён.

### Не проверено / для следующих задач

- **Ручной просмотр кадров** `build/verification/browser-check/report.html` (наш кадр рядом с эталоном) — **ждёт проверки
  пользователем**; я сравнил главную (desktop) и услугу обучения (desktop) с эталоном — композиция совпадает, вместо фото — заглушки.
- Отправка формы контактов, предвыбор услуги по `?service=` — W-09; список тем и дат обучения — W-06; карточки проектов и статей
  (`projects.show`, `library.show`) — W-06: до них ссылки примера и новостей ведут на списки разделов.
- Видео hero проверено на сгенерированном ролике H.264; настоящий ролик заказчика, saveData и пауза вне экрана (IntersectionObserver)
  в браузерной проверке не моделировались (код — по механизму прототипа). Реальные устройства, Safari/Firefox — не проверялись.
- Встроенный сервер PHP не отдаёт `Range`; Chromium проигрывает короткий ролик и без него, на рабочем сервере (nginx, W-20) — не проверено.

## W-06 «Публичные страницы II: проекты, информация, новости, обучение, поиск» — 2026-10-20, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; ручной визуальный просмотр кадров и нейтральные надписи ниже — **ждут проверки пользователем**).
Агент не делал commit/push.

### Что сделано

- **Маршруты** (`routes/web.php`): `projects` — `ProjectController@index`, `<locale>.projects.show` (`/lt/projektai/{slug}` ·
  `/en/projects/{slug}`); `information`, `news`, `library` — `ArticleController`; `<locale>.library.show` (`/lt/biblioteka/{slug}` ·
  `/en/library/{slug}`) — единственный адрес любой статьи (обоих разделов); маршрутов `/informacija/{slug}`, `/naujienos/{slug}` нет → 404
  (§4.1, §4.6); `training`, `search` — `PageController`. Карточки ищут slug среди опубликованных переводов своей локали, иначе 404
  (EN-проект без EN-перевода — 404 и нет в списках, F-38).
- **Проекты** (`public/projects`, `public/project`): без публикаций на локали — заглушка (без фильтров); после публикации — форма GET
  `?service=<ключ услуги>&application=<ключ>` (значения select прототипа), карточки (услуга проекта своей локали, название, задача,
  «View project ↗»; без ярлыков «ILLUSTRATIVE …»), пустой результат «No projects match these filters». Варианты услуги — опубликованные
  услуги локали, применения — 3 из `data-from-source.json` (EN-подписи оттуда). Вводный текст — по таблице 2 D-21, notice нет. Карточка:
  фото (медиа или своя заглушка), «01 / Task → 02 / Solution → 03 / Result», «Related services» (услуги из `meta.services`), кнопка
  «Discuss a similar project»; без notice и демо-подзаголовка; SEO/hreflang/переключатель — только опубликованные версии.
- **Информация, новости, библиотека** (`public/articles`): вкладки прототипа, поиск (`?q=`, название + вводный абзац) и категория
  (`?category=`, 3 категории прототипа); фильтра «Language» нет. «Информация» — все статьи, «новости»/«библиотека» — свой раздел;
  без публикаций раздела — заглушка; пустой результат «No resources found». Панель «Technical documents» — заголовок, заглушка
  «Information is being prepared» и «DOCUMENTS: 0» (D-21). **Статья** (`public/article`): ярлык «FREE LIBRARY», тег «EN · AIQME
  informational guide», разделы (Markdown-подмножество `Markup::html`), notice только «Specific manufacturing limits are set for each
  project.», кнопка «Ask about your project».
- **Обучение**: `public/training` (h1 — page_text `training`) и общий партиал `training-topics` (также на услуге обучения): опубликованные
  `training` — панели (дата `meta.date`, название, описание, «Register for a session ↗»), иначе заглушка; ярлыка расписания нет.
- **Поиск** `App\Domain\Content\Search` (как `searchAll` прототипа): опубликованные услуги (название + short), товары (название +
  категория из `meta.category`) и статьи (название + intro) текущей локали, подстрока без учёта регистра, до 12 результатов, порядок
  услуги → товары → статьи. Страница — форма GET `?q=`, подсказка без запроса, пустое состояние, `noindex`.
- **sitemap.xml**: + карточки проектов и статей (адрес библиотеки) с alternates только опубликованных локалей; списки проектов,
  новостей и обучения — только на локали, где есть ≥1 публикация (`training` убран из статического списка `StaticPages`).
- Общее: `PublishedContent::alternates()` (перенесено из `ServiceController`), трейт `PageSeo` (SEO статических страниц, из
  `PageController`), `resources/js/filters.js` — выбор в select отправляет форму фильтра. Ссылки карточек проектов (пример услуги) и
  новостей главной теперь ведут на карточки.
- **Фабрики** `tests/browser/ContentFactory`: `project()`, `training()`, `publicSections()` (проект LT+EN, связанный с modelling и
  scanning, и обучение); `BrowserDataSeeder` их вызывает. Новости — прежние `homeTickers()`.
- **Фикстуры**: `prototype-blocks.json` — + `/lt/mokymai`, `/en/training` и 6 адресов статей (скрипт `build/w06/extract_blocks.py`,
  игнорируемый; прежние записи не изменились); `marker-allowlist.json` — законные совпадения (вводный текст проектов по D-21,
  «prototipo bandymą» / «prototype trial» в статье, «Prototypes…» услуги в результатах поиска, заголовок «Application example» ·
  «Pritaikymo pavyzdys» на 12 страницах услуг). `text-allowlist.json` не понадобился (расхождений нет).
- **`bin/browser-check`**: замена таблицы 2 D-21 для notice статьи; карточка проекта всегда исключена из сверки текста (данные, а не
  шаблон; заголовок примера сверяется); страницы поиска с запросом (`SEARCH_PAGES`).

### Решения по неопределённому (простейший вариант) — ждут проверки пользователем

- **Нейтральные надписи, которых нет в прототипе** (EN-страницы проектов в прототипе выводят LT, F-38): «01 / Task», «02 / Solution»,
  «03 / Result», «Related services»; вводный текст «From task to result: engineering solution examples.» (D-21). Подзаголовка карточки
  проекта нет (демо-подзаголовок удалён, замены в прототипе нет). Надпись раздела новостей в карточке статьи — существующая «ARTICLE» ·
  «STRAIPSNIS» ленты главной (в прототипе есть только «LIBRARY»).
- **Связанные услуги проекта** — `meta.services` проекта; прототип всегда добавлял ещё «3D skenavimas», это не переносится.
- **Кнопка «Register for a session ↗»** пока ведёт в контакты с `?service=<slug услуги обучения>`; модальную регистрацию подключает W-09.
- **Фильтры** — серверные (форма GET); без JS фильтр по select применяется только отправкой поиска (Enter), кнопки «Применить» в
  прототипе нет — не добавлялась.
- **Поиск в шапке** — сделан в доработке (см. ниже «Доработка после приёмки»): модальное окно поиска.
- Порядок: проекты, статьи «информации»/«библиотеки» и обучение — по позиции элемента; «новости» — новые первыми (как лента главной).
- Товар в поиске ведёт на список товаров, пока карточки товара нет (W-25). Категория товара — ключ `meta.category` (уточнит W-25).
- YouTube-ссылка проекта — сделана в доработке (см. ниже): `meta.youtube` элемента проекта.
- Вне перечня touches изменены необходимые для задачи файлы: `routes/web.php`, `app/Support/Seo/StaticPages.php` (обучение в sitemap
  только с публикациями), `app/Domain/Content/PublishedContent.php`, `lang/*`, `resources/js/`, тесты W-02/W-05, `bin/browser-check`.

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **309 тестов, 261 passed, 48 skipped** (пропуски — строки 10,
  11, 13 §4.6: кабинет и админка, W-07/W-08…), Vite build OK.
- `bin/browser-check` без аргументов → **OK, 7699 проверок**, 67 страниц (в т. ч. списки проектов/информации/библиотеки/новостей,
  обучение, поиск без запроса и с запросом «3D»/«zzzz», карточки проекта и 4 статей из sitemap, 12 услуг — на модельере и сканировании
  с примером проекта), ширина 320/390/768/1024/1440, CSP/консоль, маркеры D-21; чужих хостов нет; сверка текста main с эталоном
  (6 статей, обучение LT/EN и прежние страницы W-05) — «Расхождений нет»; порядок блоков статей и обучения — по фикстуре.
- Новые Feature-тесты `PublicSectionsTest` (12): заглушка проектов до публикации и контент после (без кода), EN без перевода — 404 и
  заглушка, LT-текст под EN не подставляется; карточка проекта (история, связанные услуги, контакт, canonical, hreflang); переключатель
  языка проекта/статьи без версии другой локали → список раздела; фильтры услуги/применения и пустой результат; статьи (3 + новость)
  только по адресу библиотеки, дубли `/informacija/`, `/naujienos/`, `/information/`, `/news/` (с «/» и без) — 404, разделы влияют
  только на списки; заглушка новостей до публикации на локали; поиск и категория в списках без фильтра «Language»; notice статьи без
  заметки автора и панель техдокументов-заглушка; поиск по услугам (в т. ч. только по short), статьям, фабричному товару, без черновика и
  без другой локали, пустое состояние, подсказка, noindex, предел 12; обучение на странице и услуге (заглушка → опубликованная тема);
  sitemap: статьи по адресу библиотеки с alternates, проекты, списки проектов/новостей/обучения только с публикациями, снятие убирает,
  все адреса sitemap — 200 с canonical на себя.
- `PrototypeRoutesTest`: строка 2 — адреса 3 статей (200, canonical); строка 4 — дубли статей 404 после сидера; строка 6 — демо-проекты
  404, пока черновики, и 200 после публикации своей локали. `PublicTextsTest` — + 20 страниц W-06 (с данными фабрик): таблицы 1 и 2
  D-21 и словарный тест LT-строк на EN-страницах.
- **Тесты ловят поломки** (временные мутации, откачены): поиск по другой локали; маршрут `/naujienos/{slug}`; обучение вне правила
  sitemap; заметка автора в notice статьи; игнор фильтра применения; без предела 12; раздел не влияет на списки — каждая роняет тест.
- `git status --porcelain aiqme-site`: новые файлы — контроллеры `Public/`, `Domain/Content/Search.php`, шаблоны `public/`,
  `resources/js/filters.js`, `tests/Feature/PublicSectionsTest.php`; бинарных медиа, `.env`, ключей нет; `experiments/` не изменён.

### Не проверено

- Ручной просмотр кадров `build/verification/browser-check/report.html` — **ждёт проверки пользователем** (у карточек проектов и
  списков с данными фабрик эталон показывает демо-контент прототипа).
- Сверка текста списков проектов/информации/новостей и поиска с эталоном не делалась (там контент данных, а не шаблона; задача требует
  сверку трёх статей); страницы проектов прототипа — LT-текст на EN (F-38), эталоном не служат.
- Модальная регистрация на обучение — W-09; карточка товара и товары в списках — W-25.

### Доработка после приёмки (2026-10-20, Claude Code, ночной прогон)

Замечания приёмщика устранены оба; commit/push агент не делал.

1. **Модальное окно поиска** (01-scope.md:41 «+ модальное окно», `case'search':modal(searchPanel())` прототипа). Значок
   поиска в шапке — ссылка на страницу поиска с `data-modal-open="search-modal"`: с JS открывает `x-modal` (W-03:
   Escape, фон, ловушка Tab, inert, возврат фокуса), без JS ведёт на страницу поиска. Содержимое — общий партиал
   `public/partials/search-form` (как `searchPanel()` прототипа — тот же и на странице поиска): заголовок «What can we help you
   find?» · «Kuo galime padėti?», поле, подсказка. Фокус при открытии — в поле (`data-modal-focus`, как `$('#global-search').focus()`
   прототипа). Живые результаты (`resources/js/search.js`): ввод (задержка 200 мс) запрашивает страницу поиска `?q=` и подставляет
   её блок результатов — отдельного API нет, поиск один (`App\Domain\Content\Search`); Enter — обычная отправка на страницу
   поиска. Страница поиска тоже обновляет результаты при вводе, как в прототипе. Новых текстов нет (все из `public.search`).
2. **YouTube-ссылка проекта «при наличии»** (01-scope.md:29; 04-content-seo.md:12 включает её в поля `project`). Хранится в
   `meta.youtube` элемента проекта (ссылка общая для языков, как `services`/`application`; миграция не нужна — `meta` JSON
   W-04; ввод — редактор W-26). Выводится в панели «Related services» кнопкой `btn secondary` «YouTube ↗» (target=_blank,
   rel=noopener noreferrer), только для адреса `https://…`; без адреса кнопки нет. Встраивания плеера нет (в прототипе на
   странице проекта ссылка не выводилась вовсе; поле есть только в редакторе — «YouTube URL (optional)»). Надпись «YouTube ↗» —
   нейтральная, ждёт проверки пользователем.

Тесты: `PublicSectionsTest` + `test_project_page_shows_the_youtube_link_only_when_the_project_has_one` (без ссылки — нет кнопки;
с `https://` — кнопка с адресом, target, rel, текстом; `javascript:` — нет; мутация «без проверки https» роняет тест) и
`test_header_search_opens_the_search_modal_and_falls_back_to_the_search_page_without_js` (LT/EN: ссылка шапки на страницу
поиска с `data-modal-open`, скрытое окно с заголовком, формой GET на страницу поиска, полем `q`, подсказкой). Фабрика
`ContentFactory::publicSections()` даёт проекту browser-check YouTube-ссылку. `bin/browser-check` + `check_search_modal` на `/en` и
`/lt/paieska?q=3D` при 390 и 1440: окно открывается без ухода со страницы, фокус в поле, ввод «model» даёт результаты
сервера, ширина документа, Escape закрывает, фокус возвращается на значок, CSP/консоль без ошибок.


Проверки доработки (запущены в этой сессии): `bin/check` → **`bin/check: OK`** (Pint passed; PHPUnit 311 тестов, 263 passed,
48 skipped — прежние пропуски §4.6; Vite build OK). `bin/browser-check` без аргументов → **`bin/browser-check: OK (7729
проверок)`** (было 7699; +30 — модальное окно поиска на `/en` и `/lt/paieska?q=3D` при 390/1440 и страница проекта с YouTube-ссылкой);
чужих хостов нет (ссылка YouTube не загружается). `git status --porcelain aiqme-site`: новые файлы — `resources/js/search.js`,
`resources/views/public/partials/search-form.blade.php`; медиа, `.env`, ключей нет; `experiments/` не изменён.

Не проверено: живой поиск без задержки сети на рабочем сервере (W-20) и в Safari/Firefox; вид кнопки «YouTube ↗» и окна поиска
на кадрах — **ждёт проверки пользователем**.

## W-25 «Каталог: товары, сравнение, корзина, бренды» — 2026-10-20, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; ручной просмотр кадров и нейтральные решения ниже — **ждут проверки пользователем**).
Агент не делал commit/push.

### Что сделано

- **Маршруты** (`routes/web.php`): `products`, `compare`, `cart` — `App\Http\Controllers\Public\ProductController` (раньше —
  заготовки W-02); `<locale>.products.show` (`/lt/produktai/{slug}` · `/en/products/{slug}`): slug ищется среди опубликованных
  переводов своей локали, иначе 404 (slug другой локали, черновик, снятый, демо-товары прототипа `*-demo` — 404).
- **Данные товара** (модель W-04, без миграций): поля перевода `description`, `specifications`, `compatibility`; `meta.category` —
  ключ категории, `meta.brand` — id элемента бренда. Демо-ассортимент не импортируется.
- **Список** (`public/products`, `partials/product-card`): без публикаций на локали — заглушка «Informacija ruošiama» ·
  «Information is being prepared» (без формы и ленты); после — форма GET `.catalog` (фильтр категории `?category=<slug категории>`
  из опубликованных категорий, производителя `?brand=<id бренда>` из опубликованных брендов, «Išvalyti filtrus» · «Clear filters»,
  help-box), поиск по названию (`?q=`, без учёта регистра), сортировки «Rekomenduojama» · «Recommended» (по `published_at`
  перевода, новые первыми; при равенстве — порядок позиций) и «Pavadinimas A–Z» · «Name A–Z» (`Collator` локали); число найденных,
  пустой результат «No results» с кнопкой сброса; лента `brand-row` опубликованных брендов. Карточка: фото (первое) или своя
  заглушка, бренд, категория, название, «Pagal užklausą» · «On request», «Gauti pasiūlymą» · «Request a quote» (страница товара,
  как в прототипе), отметка «Palyginti» · «Compare». Нет: notice списка, brand-note (D-36), «PLANNED RANGE», «Supply to be
  confirmed», фильтров технологии и цены, сортировки по цене (D-41), покупки (D-12).
- **Страница товара** (`public/product`): верх «AIQME / PRODUCTS», категория в вводной строке; галерея — фото товара (миниатюры
  при ≥2, `resources/js/gallery.js`), без фото — своя заглушка `partials/art`; ярлык — только название бренда; описание;
  «Individualus pasiūlymas» · «Tailored quotation»; «Gauti pasiūlymą» → контакты `?product=<slug>` (контракт §1.2, читает W-09);
  список: «Delivery estimate: confirmed in the proposal» + совместимость; панель характеристик (категория + поле `specifications`
  через `Markup::html`, без него — заглушка), «Included items & support» с текстом прототипа, «Documents» — заглушка; «Compatible
  accessories» + notice совместимости (LT без «Prototipe»); «Back to the catalogue». SEO/hreflang/переключатель — только
  опубликованные версии.
- **Сравнение** (`public/compare`): `?products=<slug,…>` — опубликованные товары локали в порядке выбора, без повторов, первые 4;
  при <2 — пустое состояние прототипа «Select at least two products» + «Open catalogue»; при ≥2 — таблица: Manufacturer, Category,
  Price («On request»), Compatibility, «View». Выбор на странице товаров — `resources/js/compare.js`: localStorage своей локали,
  панель `.compare-bar` «Selected for comparison: N/4», ссылка «Compare» на сравнение с выбором в адресе, «Clear comparison»;
  пятая отметка снимается с сообщением «Compare up to 4 products.».
- **Корзина** (`public/cart`): всегда пустое состояние «Your basket is empty» · «Krepšelis kol kas tuščias» и кнопка на калькулятор
  (надпись — существующая `shell.quote_cta`); без demo-notice и «Try the purchase flow…».
- **sitemap.xml**: список товаров — только на локали с публикацией; карточки товаров с alternates только опубликованных локалей.
  **Поиск** (W-06) ведёт на карточку товара. Иконка `settings` прототипа в `x-line-icon`. CSS — `resources/css/public.css`
  (фото/заглушка в рамках `.visual`, `.gallery-main`; `[hidden]` у панели сравнения).
- **Фабрика** `ContentFactory::product()`/`catalog()` — 4 товара LT+EN с брендом (NORDA/VEKTA) и категорией; `BrowserDataSeeder`
  её вызывает. `bin/browser-check`: `CATALOG_PAGES` (фильтр, пустой результат, сравнение 4 товаров LT/EN), рамки фото товара в
  проверке медиа, `check_compare` на `/lt/produktai`, `/en/products` при 390 и 1440.

### Решения по неопределённому (простейший вариант) — ждут проверки пользователем

- **Цена для показа** (§4.1) не выводится: везде «On request» / «Tailored quotation» (D-12, D-41: цена плановых — по запросу).
  Поле цены добавит редактор W-26, если понадобится.
- Строки сравнения «Technology» (поля нет, D-41) и «Delivery» (значение «To be confirmed» — пометка D-21) не выводятся; подпись
  цены «Demo price excl. VAT» заменена на «Kaina» · «Price» (есть в app.js прототипа).
- Абзац прототипа о характеристиках («…These fields are not a real equipment specification.») — демо-заметка, заменён полем
  `specifications`; ярлык «<бренд> / PLANNED» — выводится только бренд; «Supply status» не выводится.
- Нейтральные надписи, которых нет в прототипе: aria-label миниатюр «Vaizdas N» · «View N» (первая — «Pagrindinis vaizdas» ·
  «Main view» из прототипа); кнопка корзины — «Įkelti modelį ir sužinoti kainą» (CTA шапки).
- LT-текст «Komplektacija… tikslinami konkrečiame pasiūlyme.» — текст прототипа, не из таблицы 2 D-21; совпадение маркера
  «tikslinam» внесено в `marker-allowlist.json` для 4 LT-страниц товаров фабрики.
- Выбор сравнения хранится в localStorage (как state.compare прототипа); без JS отметки не работают, страница сравнения по
  адресу работает.
- Вне touches изменены нужные файлы: `routes/web.php`, `SitemapController`, `StaticPages` (комментарий), `Search`, `lang/*`,
  `resources/js|css`, `x-line-icon`, тесты W-02/W-06 (`PrototypeRoutesTest` — строка 5 после сидера, `PublicSectionsTest` — ссылка
  поиска на карточку, `PublicTextsTest` — + 10 страниц каталога), `tests/Support/Html::texts` (контекстный узел), `bin/browser-check`.

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **321 тест, 273 passed, 48 skipped** (прежние пропуски §4.6:
  кабинет и админка), Vite build OK.
- `bin/browser-check` без аргументов → **`bin/browser-check: OK (8916 проверок)`**: в т. ч. список товаров, 8 карточек товаров из
  sitemap, фильтр, пустой результат, сравнение пустое и из 4 товаров, корзина LT/EN — ширина 320/390/768/1024/1440, CSP/консоль,
  маркеры D-21; `check_compare` (панель 4/4, пятая отметка отклонена с сообщением, восстановление из localStorage, очистка,
  переход на сравнение с таблицей из 4); сверка текста с эталоном — «Расхождений нет». Чужих хостов нет.
- Новый `tests/Feature/CatalogTest.php` (10): заглушка → карточки после публикации, снятие EN возвращает заглушку только EN;
  фильтры категории (slug) и бренда (id), их сочетание, поиск только по названию, сохранение значений, пустой результат, нет
  tech/max/сортировки по цене; сортировки (дата публикации, повторная публикация поднимает товар; литовский алфавит; неизвестная
  → Recommended); страница товара (тексты, `?product=`, без покупки, canonical/hreflang, notice LT без «Prototipe», 404 для чужой
  локали/черновика/снятого/демо); галерея (заглушка; 2 фото — переключатели, alt); сравнение (<2 — пустое; черновик, чужая локаль,
  повтор не считаются; таблица; из 5 — первые 4); корзина; фразы D-21/D-36 и демо отсутствуют на 12 страницах каталога LT/EN, «Pagal
  užklausą»/«Gauti pasiūlymą»/«On request» есть; sitemap — публикация и снятие; лента брендов главной после публикации бренда.
- **Тесты ловят поломки** (временные мутации, откачены, `cmp`): без предела 4; без фильтра бренда; товары не в sitemap; старые
  первыми; ссылка без `product=` — каждая роняет CatalogTest; предел в `compare.js` `>` вместо `>=` — роняет `check_compare`.
- `git status --porcelain aiqme-site`: новые — `ProductController.php`, шаблоны `public/products|product|compare|cart`,
  `partials/product-card`, `resources/js/compare.js|gallery.js`, `tests/Feature/CatalogTest.php`; медиа, `.env`, ключей нет;
  `experiments/` не изменён.

### Не проверено

- Ручной просмотр кадров `build/verification/browser-check/report.html` — **ждёт проверки пользователем** (я посмотрел список EN и
  товар LT на 1440: композиция как в прототипе, вместо иконок — заглушки). Эталон каталога показывает демо-товары, сверка текста для
  каталога не делается (в `prototype-blocks.json` его нет).
- Предзаполнение формы по `?product=` — W-09; редактор товаров/брендов — W-26. Safari/Firefox, реальные фото заказчика — нет.

## W-07 «Вход, регистрация, восстановление и профиль» — 2026-10-20, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; нейтральные надписи и ручной просмотр — **ждут проверки пользователем**). Агент не делал
commit/push.

### Что сделано

- **Маршруты** (`routes/web.php`, только LT/EN): `/lt/paskyra/…` · `/en/account/…` — `login`, `register`, `reset` (GET+POST),
  `reset/{token}` (ссылка из письма, токен в пути — под правило журнала nginx W-20), корень кабинета и `addresses` (GET, POST),
  `logout` (POST). Имена `<locale>.account[.login|.register|.reset|.reset.token|.addresses|.logout]`, у POST — `*.store/update`.
  На всех — `Cache-Control: private, no-store` (§6.6, `cache.headers`). Кабинет и выход — под `auth.session` (AuthenticateSession).
  Админка: `/lt/administravimas` · `/en/admin` (`<locale>.admin`) — `auth.session` + `App\Http\Middleware\EnsureAdmin` (404 гостю
  и клиенту, без редиректа на вход, Gate `admin`) + no-store; страница — заготовка `resources/views/admin/index.blade.php`
  (заголовок прототипа «AIQME valdymo aplinka» · «AIQME management workspace» и «Atsijungti»); оболочку и вкладки делает W-08.
  `/ru/` — добавлены `auth.session` и no-store (по-прежнему 404 всем, кроме администратора, `X-Robots-Tag: noindex`).
  `bootstrap/app.php`: `redirectGuestsTo` — форма входа своей локали (гость на POST кабинета и сессия, завершённая
  AuthenticateSession).
- **Контроллеры** `app/Http/Controllers/Auth/`: `LoginController` (вход: нейтральная ошибка «Neteisingas el. paštas arba
  slaptažodis.» · «Incorrect email or password.» для неверного пароля и несуществующего адреса; ограничение — 5 неудачных попыток
  в минуту на e-mail+IP и 20 на IP, затем «Per daug bandymų…» · «Too many attempts. Try again in N s.», успешный вход сбрасывает
  счётчик e-mail+IP; `session()->regenerate()`; клиент → кабинет, администратор → админка (`intended`); выход —
  `logout` + `invalidate()` + новый CSRF), `RegisterController` (имя, e-mail, пароль; `User::create` — `is_admin` не в `$fillable`;
  без подтверждения почты, сразу вход), `PasswordResetController` (запрос — всегда «Jei paskyra su šiuo el. pašto adresu yra,
  išsiuntėme…» · «If an account with this email exists, we have sent a password reset link.», в том числе для несуществующего
  адреса и повтора раньше `throttle` брокера; 5 запросов в минуту с IP; смена пароля — брокер Laravel: токен одноразовый, срок
  `auth.passwords.users.expire` = 60 мин; новый `remember_token`), общий трейт `AuthForm` (локализованные сообщения проверки,
  e-mail — строчными). `app/Http/Controllers/Account/AccountController` — вкладка профиля; гостю на корне и вкладке — форма входа (200).
- **Письмо сброса** `app/Mail/ResetPasswordLink` (`ShouldQueue`, язык страницы запроса), шаблон `account/reset-mail.blade.php`;
  `User::sendPasswordResetNotification` ставит его в очередь вместо уведомления Laravel. Локально уходит в Mailpit (SMTP .env).
- **Профиль** — миграция `customer_profiles` (`user_id` unique, FK cascade; name, company, company_code, vat_code, address, city,
  postcode — nullable), модель `App\Models\CustomerProfile`, `User::profile()`. Форма отправляет весь профиль: пустое поле очищается.
- **Пароль**: `Password::defaults()` — `min(8)` (AppServiceProvider); в формах `minlength="8"`, `autocomplete` `current-password`
  (вход) / `new-password` (регистрация, новый пароль); экрана смены пароля нет.
- **Сессии**: `SESSION_ENCRYPT=true` в `.env.example` и `.env`, по умолчанию `true` в `config/session.php`; драйвер database (был).
- **Шаблоны** `resources/views/account/auth.blade.php` (режимы login/register/reset/new_password, разметка `auth-form` прототипа:
  `.auth`, `.panel`, ссылки Registruotis / Pamiršote slaptažodį? / Prisijungti, «Užsakyti be paskyros» → калькулятор, `#auth-result`
  для сообщения), `account/addresses.blade.php` (`account-layout`, `side-nav`, `address-form`; выход — кнопка `form="logout-form"`,
  как кнопка прототипа в меню). Страницы кабинета и входа — `noindex` (§4.4). Тексты — `lang/{lt,en}/account.php`.
- **Шапка**: значок кабинета прототипа (`user`, «Paskyra» · «Account») → `<locale>.account` (на /ru — EN). `x-line-icon` + `user`.
- `aiqme:make-admin` приводит e-mail к строчным (как регистрация и вход), иначе администратор с заглавными не смог бы войти.
- **bin/browser-check**: вход администратора (`/en/account/login` → `/en/admin`) и клиента (`/lt/paskyra/login` → `/lt/paskyra`)
  через форму; кабинет (`ACCOUNT_PAGES`) и админка (`ADMIN_PAGES`) — 200 на 320–1440 с проверками ширины, §7.1, маркеров D-21 и
  консоли; админка и /ru/ гостю и клиенту — 404; все /ru/ из route:list администратору — 200 с `X-Robots-Tag` и meta noindex;
  сохранение профиля и выход; сброс пароля: запрос → письмо в Mailpit (API `127.0.0.1:58025`, тема, ссылка) → новый пароль →
  прежняя сессия клиента ведёт на вход → вход новым паролем. Пометка «пропущено: вход появится в W-07» снята; подготовка поднимает
  и `mailpit`. Страницы входа/регистрации/восстановления и кабинета для гостя проверяются в общем цикле (из route:list).

### Решения по неопределённому (простейший вариант) — ждут проверки пользователем

- Демо-надписи D-21 удалены: баннер, «Demo password *» → «Slaptažodis *» · «Password *», подсказка о пробном пароле, «Atverti
  paskyros pavyzdį», «Tik demonstraciniai duomenys šiai sesijai.», предзаполненный `demo@example.invalid`.
- Нейтральные надписи, которых нет в прототипе: кнопки «Prisijungti» · «Sign in» (вместо «…į demo paskyrą»), «Sukurti paskyrą» ·
  «Create an account», «Siųsti atkūrimo nuorodą» · «Send reset link» (вместо «Imituoti atkūrimo laišką»), «Išsaugoti duomenis» ·
  «Save details», «Duomenys išsaugoti.» · «Details saved.»; поле «Naujas slaptažodis *» · «New password *», кнопка «Išsaugoti
  naują slaptažodį» · «Save new password»; сообщения ошибок/ограничения/сброса (см. `lang/*/account.php`); aria-label меню
  «Paskyros meniu» · «Account menu»; текст письма сброса (тема — заголовок прототипа «Slaptažodžio atkūrimas» · «Reset your password»).
- Вкладки W-15 (Overview, Orders, Saved models, Invoices) не созданы; меню кабинета — только «Adresai ir įmonė», корень кабинета
  до W-15 показывает эту вкладку (в прототипе корень — Overview). Строки §4.6 этих вкладок пропускаются в `PrototypeRoutesTest`.
- Админка W-07 — только корень-заготовка (нужна для входа администратора и проверки 200); остальные вкладки — W-08 и далее.
- Форма нового пароля — одно поле, без подтверждения (как одно поле пароля в формах прототипа). Регистрация без ограничения частоты
  (план требует ограничения для входа и сброса). Ошибка «Paskyra su šiuo el. pašto adresu jau yra.» при регистрации раскрывает
  наличие адреса — штатное поведение, нейтральность требуется только для входа и сброса.
- `MAIL_FROM_ADDRESS` не менялся (D-15 не решён; локально — Mailpit).

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **343 теста, 307 passed, 36 skipped** (пропуски §4.6 —
  вкладки кабинета W-15 и вкладки админки W-08 и далее), Vite build OK.
- `bin/browser-check` без аргументов → **`bin/browser-check: OK (10429 проверок)`**: вход администратора и клиента через форму;
  кабинет 4 адреса и админка 2 адреса после входа на 320/390/768/1024/1440 (ширина, §7.1, маркеры D-21, CSP/консоль); админка и
  /ru/ гостю и клиенту — 404; 19 адресов /ru/ администратору — 200 с noindex; сохранение профиля и выход; **сброс пароля по
  письму из Mailpit** (тема, ссылка, новый пароль, прежняя сессия ведёт на вход, вход новым паролем); 89 страниц общего цикла,
  включая вход/регистрацию/восстановление и кабинет гостя. Чужих хостов нет. Первый прогон нашёл переполнение шапки на 320 px
  (351 > 320) из-за значка кабинета — исправлено в `resources/css/shell.css` (≤360 px: промежутки и поля кнопок шапки).
- Новые тесты: `tests/Feature/AuthTest.php` (16) — форма входа (тексты, minlength 8, autocomplete, noindex, no-store, нет
  демо-надписей), вход с регенерацией сессии, одинаковая ошибка для неверного пароля и неизвестного адреса, ограничение по e-mail+IP
  и по IP со снятием через минуту, вход администратора → админка и /ru/ (noindex), 404 админки и /ru/ гостю и клиенту, регистрация
  без is_admin и с отказами (короткий пароль, занятый адрес, пустые поля LT), выход (строка сессии удалена, скопированная cookie
  ведёт на форму входа, POST профиля с ней — на вход), сброс: вход через форму → смена пароля по ссылке из Mail::fake → следующий
  запрос той же сессии — редирект на вход (клиент и администратор), одноразовость, срок 60 мин, неверный токен/e-mail, нейтральный
  ответ и одно письмо при повторе, ограничение запросов по IP, письмо в очереди на языке страницы, шифрование: `session.encrypt`
  === true и `base64_decode(payload)` строки sessions после GET ссылки сброса не содержит токена. Сессии в этих тестах — драйвер
  database и cookie «браузера» (`tests/Support/BrowserSession.php`). `tests/Feature/AccountProfileTest.php` (6) — форма
  вкладки, сохранение в customer_profiles, очистка пустых полей, лимит 255, **изоляция двух клиентов**, гость, удаление с
  пользователем. `PrototypeRoutesTest`: строки 10 (корень и addresses), 11 и корень админки строки 13 проверяются; `ShellTest` —
  значок кабинета.
- **Тесты ловят поломки** (временные мутации, откачены, `cmp`): без `auth.session` — падает тест сброса; `SESSION_ENCRYPT=false` —
  тест шифрования; без `invalidate()` — тест выхода; пределы 500/2000 — тест ограничения; общий профиль вместо своего — тест
  изоляции.
- `git status --porcelain aiqme-site`: новые — контроллеры Auth/ и Account/, `EnsureAdmin`, `app/Mail/`, `CustomerProfile`,
  миграция, `lang/*/account.php`, шаблоны `account/`, `admin/`, тесты; медиа, ключей, `.env` нет (`.env` игнорируется);
  `experiments/` не изменён.

### Не проверено

- Ручной просмотр кадров (`build/verification/browser-check/report.html`, кадры после входа `shots/*-signed-in-*.png`) —
  **ждёт проверки пользователем** (я сверил вход EN и вкладку адресов EN на 1440 с эталоном: композиция совпадает, без демо-баннера
  и вкладок W-15).
- Настоящая доставка писем (D-15, SMTP домена) — уровень T; локально письмо проверено только в Mailpit. Вкладки кабинета W-15,
  оболочка админки W-08, подстановка профиля в заявку W-12 — в своих задачах.

## W-08 «Админка: оболочка, общий редактор, услуги и страницы» — 2026-10-20, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; нейтральные надписи и ручной просмотр кадров — **ждут проверки пользователем**).
Агент не делал commit/push.

### Что сделано

- **Маршруты** (`routes/web.php`): `/lt/administravimas/…` · `/en/admin/…` — корень (`<locale>.admin`, Overview, как `/admin/`
  прототипа) и вкладки `overview`, `services`, `content`, `audit` (`<locale>.admin.<вкладка>`), все под `auth.session`,
  `EnsureAdmin` (404 гостю и клиенту) и no-store. Контроллер `app/Http/Controllers/Admin/AdminController` (вне touches — нужен
  маршрутам). Шаблон-заготовка W-07 `admin/index.blade.php` заменён оболочкой.
- **Оболочка** `resources/views/admin/shell.blade.php` — разметка прототипа: page-top «AIQME valdymo aplinka» · «AIQME management
  workspace», `.admin-layout`, `nav.side-nav` «Administravimo meniu» · «Administration menu», `#admin-main`, выход (POST).
  Не перенесены (D-21, §1.4): вводный текст «Demo administration…», notice, ярлык «ADMINISTRATION DEMO», переключатель ролей
  «Try a role». В меню — только созданные вкладки (Overview, Services, Content & SEO, Audit log); Permissions, Telegram, CSV не
  созданы (constraints), остальные пункты добавят W-26, W-11, W-14, W-17, W-18 вместе со своими адресами.
- **Overview** — заготовка: заголовок прототипа «Darbo diena viename vaizde.» · «Your working day, at a glance.» и заглушка
  «Informacija ruošiama»; демо-метрики, заказ DEMO-1047 и notice не переносятся (наполняет W-14).
- **Services / Content & SEO** (`admin/content-tab`): таблица прототипа «Pavadinimas · LT / EN · Redaguoti», состояние
  языков — «Abi kalbos» · «Both languages» (LT и EN опубликованы), «LT»/«EN» (один), «Juodraštis» · «Draft» (ни одного);
  «Pridėti juodraštį +» · «Add a draft +» — только у услуг (`?new=1`); редактор открывается под списком (`?edit=<id>`), как
  `#content-editor` прототипа. Вкладка content в W-08 содержит 8 page_text (статьи и новости добавит W-26). Панель «Drafts in
  this session» не переносится.
- **Общий Livewire-компонент** `app/Livewire/Admin/ContentEditor` + `admin/content-editor.blade.php` (форма `content-edit-form`):
  поля перевода LT и EN рядом (`grid two form-grid`), RU — ниже под заголовком «RU»; подписи на языке поля, как в прототипе
  («Pavadinimas LT», «Title EN», «Adresas LT», «URL EN», «SEO pavadinimas LT», «SEO description EN»; RU — английская подпись +
  «RU»). «Išsaugoti» · «Save» сохраняет черновик каждого изменённого языка одной транзакцией (неизменённые и пустые несозданные
  языки не трогаются); строка публикации каждого языка — состояние (Paskelbta / Yra nepaskelbtų pakeitimų / Juodraštis /
  Nesukurta) и кнопки «Paskelbti LT» · «Publish LT», «Atšaukti paskelbimą LT» · «Unpublish LT» (публикация сначала сохраняет
  изменённый язык). Все записи — через `ContentService` с ожидаемой ревизией языка; расхождение → сообщение
  `content.stale` («Changed in another window. Reload the page and try again.»), введённый текст остаётся в форме, ни один
  язык не сохраняется. Новая услуга: ключ — slug LT (для групп FAQ и связей проектов), позиция — после последней.
  Каждый запрос компонента (`/livewire/update`, в том числе со снимком, полученным администратором) — `boot()`: не
  администратор → 404; локаль интерфейса хранится в компоненте (у `/livewire/update` нет префикса локали).
- **ContentTypes** (`app/Domain/Content/ContentTypes.php`) — одно место описания редактируемых типов для веба и бота W-19:
  зарегистрированы `service` (поля `SERVICE_FIELDS`: short, label — строка; benefits, problems — список «по строке на пункт»;
  description, provide, receive — текст; `ContentType::fields()` услуги берёт тот же список) и `page_text` (блоки страницы из
  первой версии перевода: строка → текст, список строк → список, список записей → записи с частями, число записей задано
  блоком — без конструктора). Там же проверки (`rules()` — правила валидатора по виду поля; slug — регулярное выражение
  `ContentService::SLUG`), коллекции медиа по типу (услуга — `images`; главная — `video`, `video_mobile`, `video_poster`;
  «О нас» — `images`; по одному файлу, новый заменяет прежний) и перевод хранимого вида ↔ формы (`toForm`/`fromForm`).
- **Медиа** (внутри редактора, без отдельной вкладки): раздел «Failai ir nuotraukos» · «Files & photos» — список файлов
  (коллекция, ссылка, alt), «Pašalinti» · «Remove», загрузка: «Paskirtis» (коллекция), файл, «Alt tekstas LT», «Alt text EN»,
  «Šaltinis», «Šaltinio nuoroda» (подпись источника hero, только http(s)), «Įkelti» · «Upload». Проверка файла —
  **`App\Domain\Content\MediaFile::check()`** (размер ≤ `aiqme.media_max_kb`, формат по сигнатуре: JPEG, PNG, WebP, MP4; SVG и
  прочее — отказ; подходит коллекции), её вызывает `ContentService::addMedia()` (и вызовет бот W-19). Имя файла — UUID с
  расширением по сигнатуре, имя медиа (`name`) — тот же UUID: исходные имя и расширение не сохраняются.
  `ContentService::addMedia(…, replace: true)` и новый `removeMedia()` (только администратор). Медиа применяются сразу (версий у
  файлов нет, как в W-04).
- **Лимит** (§6.4): `config/aiqme.php` → `media_max_kb` = 20480 (env `AIQME_MEDIA_MAX_KB`); `AppServiceProvider` задаёт тот же
  предел `livewire.temporary_file_upload.rules` = `['required', 'file', 'max:20480']` (по умолчанию у Livewire 12 МБ). Подпись
  поля файла берёт число МБ из конфигурации.
- **Audit** (`admin/audit`): последние 100 записей журнала `audit` — «Laikas» · «Time», «Kanalas» · «Channel» (вместо «Role»
  прототипа: один администратор), «Veiksmas» · «Action» (Paskelbta / Paskelbimas atšauktas; неизвестное событие — его ключ),
  «Objektas» · «Object» (заголовок перевода и тип), «Duomenys» · «Details» (свойства события, кроме канала: `locale: en ·
  revision: 1`; «было/стало» событий заказов выведется так же — W-13/W-16/W-28). Пусто — «Veiksmų dar nėra.» · «No actions yet.».
- **CSS**: `resources/css/shell.css` — `.account-layout>*, .admin-layout>*{min-width:0}`: таблица `.data-table` (min-width 550 px
  прототипа) раздувала колонку сетки до 552 px на 390 px — ровно ошибка прототипа из §1.4; теперь прокручивается сама таблица.
- **Тексты** `lang/lt|en/admin.php`, `content.media_invalid`; `ContentItem::pageBlocks()` (перенесено из ContentService),
  `ContentService::SLUG`/`MAX_TEXT` стали публичными.
- **bin/browser-check**: `ADMIN_PAGES` + 8 адресов вкладок; `check_admin_editor` — редактор услуги и главной из списка на 390 и
  1440 (ширина, консоль/CSP); на 1440: черновик не виден на сайте → «Publish EN» → текст на странице услуги; PNG с именем
  `x.html` → имя `UUID.png` и картинка на странице услуги; второе окно сохраняет ту же услугу раньше → в первом сообщение о
  конфликте и введённый текст на месте; затем исходный текст возвращается публикацией (страница сверяется с эталоном).

### Решения по неопределённому (простейший вариант) — ждут проверки пользователем

- **Нейтральные надписи, которых нет в прототипе:** вкладка не создаётся — пункта меню нет; состояния «Paskelbta» · «Published»,
  «Yra nepaskelbtų pakeitimų» · «Unpublished changes», «Nesukurta» · «Not created»; кнопки «Paskelbti :locale» · «Publish :locale»,
  «Atšaukti paskelbimą :locale» · «Unpublish :locale»; сообщения «Juodraštis išsaugotas.» · «Draft saved.», «Paskelbta: LT.» ·
  «Published: LT.», «Paskelbimas atšauktas: LT.» · «Unpublished: LT.», «Failas įkeltas.» · «File uploaded.», «Failas
  pašalintas.» · «File removed.», «Failų dar nėra.» · «No files yet.»; «(po vieną eilutėje)» · «(one per line)»; подписи полей
  услуги «Trumpas aprašymas» · «Short description», «Bloko parašas» · «Block caption», «Bloko punktai» · «Block points»,
  «Problemos» · «Problems» («Aprašymas» · «Description», «Ką pateikti?», «Ką gausite?» — из прототипа); медиа «Paskirtis» ·
  «Purpose», «Nuotrauka» · «Image», «Mobilusis video» · «Mobile video», «Video kadras» · «Video poster», «Failas (JPEG, PNG, WebP
  arba MP4, iki :max MB)», «Alt tekstas» · «Alt text», «Šaltinis» · «Source», «Šaltinio nuoroda» · «Source link», «Įkelti» ·
  «Upload», «Pašalinti» · «Remove»; ошибка файла «Netinkamas failas: tik JPEG, PNG, WebP arba MP4, ne didesnis nei :max MB.» ·
  «Invalid file: JPEG, PNG, WebP or MP4 only, up to :max MB.»; журнал «Kanalas» · «Channel», «Objektas» · «Object», «Duomenys» ·
  «Details», каналы «Svetainė» · «Website», «Sistema» · «System», события «Paskelbta» · «Published», «Paskelbimas atšauktas» ·
  «Unpublished». Сообщения проверки полей — тексты W-07 («Užpildykite šį lauką.», «Per ilga reikšmė…») и W-04 («Netinkama reikšmė: …»).
- **Подписи блоков page_text** — имена блоков (`hero_title LT`, `scenarios 1 · title EN`): в прототипе редактора страниц нет,
  придумывать ~40 подписей не стал. Порядок блоков — по имени (jsonb не хранит порядок ключей сидера), части записи — заголовок первым.
- «Save as a draft» прототипа не перенесён: сохранение всегда черновик, публикация — отдельной кнопкой по языку (§4.2).
  «Save both languages» → «Išsaugoti» · «Save» (языков три).
- Число записей в блоках-списках page_text (сценарии, шаги, разделы политик) фиксировано — без конструктора (§4.1); списки строк
  (benefits, problems, hero_stages) свободной длины.
- Подпись alt — только при загрузке (смена подписи — удалить и загрузить заново); alt RU не вводится (RU — тестовая локаль).
- Новые страницы page_text не создаются (набор — код); удаления услуги нет (не требуется задачей; снятие с публикации есть).
- Журнал — последние 100 записей без постраничного вывода; детали событий — пары «ключ: значение».

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **365 тестов, 337 passed, 28 skipped** (пропуски §4.6 —
  вкладки кабинета W-15 и вкладки админки других задач), Vite build OK.
- `bin/browser-check` без аргументов → **`bin/browser-check: OK (11154 проверок)`**: вход администратора через форму; 10 адресов
  админки (корень и 4 вкладки LT/EN) на 320/390/768/1024/1440 — ширина, §7.1, маркеры D-21, консоль/CSP; гостю и клиенту — 404;
  `check_admin_editor` (Livewire без нарушений CSP: черновик, публикация EN, загрузка PNG `x.html` → `UUID.png` и картинка на
  странице услуги, конфликт двух окон) и все 89 страниц прежних задач. Первый прогон нашёл: (1) документ 552 px на 320/390
  на вкладках с таблицей и в редакторе — исправлено `min-width:0` в shell.css; (2) мой же опубликованный текст ломал сверку
  страницы услуги с эталоном — проверка теперь возвращает исходный текст. Повтор — OK. Чужих хостов нет.
- Новые тесты: `AdminShellTest` (6) — все адреса W-08 (в т. ч. `?edit`, `?new=1`, со «/») 404 гостю и клиенту, 200 с noindex и
  no-store администратору; чужой тип/несуществующий элемент в `?edit` — 404; **действия Livewire (`/livewire/update` со снимком
  администратора) от клиента и гостя — 404**, правка не записана; оболочка LT/EN без демо-надписей, ролей, Permissions/Telegram/CSV,
  меню из 4 вкладок, один `aria-current`; заглушка Overview; исключённые и будущие вкладки — 404; списки услуг и страниц с
  состоянием языков; журнал (пусто → «No actions yet.»; публикация через Telegram и снятие через веб — время, канал, действие,
  объект, детали; LT-надпись). `AdminContentEditorTest` (11) — новая услуга: черновик (ключ = slug LT, позиция 7, пустой EN не
  создаётся, страница 404) → публикация LT (страница и список услуг) → EN → снятие EN (404 только EN); правка опубликованной
  услуги не видна до публикации языка, LT не пересохраняется; SEO title/description и новый slug на публичной странице, старый
  адрес 404; занятый slug — ошибка у поля, ввод сохранён; **конфликт ревизии** (правка того же перевода каналом telegram) —
  сообщение, ввод на месте, версия бота не перезаписана, публикация с устаревшей ревизией отклонена; конфликт EN отменяет и LT;
  проверки полей из ContentTypes (обязательность, slug, длина) LT/EN; page_text главной: блоки-строки, списки и записи, публикация
  EN видна на `/en`, не на `/lt`, набор блоков не меняется; черновая политика публикуется из редактора; форма без правок не
  создаёт версий (toForm/fromForm — без потерь); RU тем же редактором (`/ru/services/<slug>` администратору); описание полей
  услуги в одном месте, незарегистрированный тип и новый page_text — 404. `AdminMediaTest` (5) — лимит 20480 в конфигурации и
  в правилах Livewire, подпись «up to 20 MB»; **PNG с именем x.html → `UUID.png`**, `name` = UUID, `image/png`, alt LT/EN, картинка
  на странице услуги; новая иллюстрация заменяет прежнюю, удаление; SVG под `.png`/`.svg`, HTML под `.jpg`, MP4 в коллекцию
  фото — отказ, медиа не созданы; видео главной с источником; чужая коллекция и `javascript:`-источник — отказ; у политики
  раздела медиа нет; предел из конфигурации (1 МБ) — в Livewire и в `MediaFile::check()` по пути файла (как вызовет бот W-19).
  `PrototypeRoutesTest` — строка 13 §4.6 для корня и вкладок overview, services, content, audit проверяется (200 администратору,
  404 гостю и клиенту), остальные вкладки пропускаются со своей задачей.
- **Тесты ловят поломки** (временные мутации, откачены, `cmp`): без проверки администратора в `boot()` — падает тест действий
  Livewire; сохранение с текущей ревизией из БД («последняя запись побеждает») — 2 теста конфликта; MediaFile без сигнатуры —
  тест MediaFile; без правила Livewire — тест лимита. Остаток мутации (временный каталог `storage/media-library/temp` с SVG)
  удалён.
- `git status --porcelain aiqme-site`: новые файлы — `app/Domain/Content/{ContentTypes,MediaFile}.php`,
  `app/Http/Controllers/Admin/`, `app/Livewire/Admin/`, `lang/*/admin.php`, шаблоны `resources/views/admin/`, 3 теста; удалён
  `resources/views/admin/index.blade.php`; медиа, `.env`, ключей нет; `experiments/` не изменён.

### Не проверено

- Ручной просмотр кадров админки (`build/verification/browser-check/shots/*admin*-signed-in-*.png`) и редактора — **ждёт
  проверки пользователем**; я посмотрел вкладку услуг на 1440 (EN) и 390 (LT): композиция как в прототипе, без демо-блоков.
- Загрузка большого видео (до 20 МБ) через настоящий nginx/PHP-FPM (лимиты тела W-20) — не проверялась; локально — PNG и
  короткий MP4-заголовок в тестах. Конверсии видео нет (как в W-04).
- Проверку `MediaFile` из бота покажет W-19 (класс готов, тест вызова — там).

### Доработка после приёмки (2026-10-20): bin/check красный

- Причина (`W-08.check0.log`): упал `CatalogTest::test_products_show_a_placeholder_until_published_then_cards_without_code_changes`
  (W-25) — первой карточкой оказался «NORDA CAD», а не «NORDA S1 scanner». Код W-08 каталог не трогает: порядок «рекомендуемые»
  в `ProductController::index()` — `published_at` по убыванию с точностью до секунды; тест считал, что все позиции фабрики
  публикуются в одну секунду («Одна дата публикации»). Под нагрузкой полного прогона публикации пересекли границу секунды, и
  позже опубликованный товар честно встал первым — нестабильный тест, а не ошибка сортировки.
- Исправление: в тесте публикация каталога идёт при замороженном времени (`Carbon::setTestNow(now()->startOfSecond())` …
  `Carbon::setTestNow()`), как уже сделано в соседних тестах сортировки по дате того же файла. Поведение сайта не менялось.
- Проверки: `bin/php artisan test --compact tests/Feature/CatalogTest.php` — 10 passed; `bin/check` — OK (365 тестов: 337 passed,
  28 skipped; Pint, Vite build OK).

### Продолжение после таймаута (2026-10-20, ночь 13:11)

- Работа восстановлена в дереве; прошлая приёмка не состоялась (сессия приёмщика прервана таймаутом). Сверил критерии done с
  кодом: маршруты и 404 (EnsureAdmin + `boot()` компонента), ContentTypes, ContentEditor с ревизиями, MediaFile, лимит
  `aiqme.media_max_kb` = 20480 и `livewire.temporary_file_upload.rules` — на месте, тесты есть.
- Доделано: `MediaFile::sniff()` принимал любой `ftyp` как MP4, в том числе картинки HEIC/AVIF (сохранились бы как `.mp4`).
  Теперь основной бренд `heic/heix/heim/heis/hevc/hevx/mif1/msf1/avif/avis` отклоняется; в
  `AdminMediaTest::test_svg_html_and_wrong_collection_files_are_rejected_by_signature` добавлены файлы `ftypheic`/`ftypavif`
  с именем `.mp4` — отказ.
- Проверки: `bin/php artisan test --compact tests/Feature/AdminMediaTest.php tests/Feature/PublicMediaTest.php` — 8 passed;
  `bin/check` — **OK** (Pint; PHPUnit 365 тестов: 337 passed, 28 skipped; Vite build OK).
- `bin/browser-check` (передний план; из-за 10-минутного предела инструмента досмотрен после перевода в фон, ~13,5 мин) —
  **`bin/browser-check: OK (11154 проверок)`**, включая админку 320–1440, 404 гостю/клиенту, редактор W-08 и 89 страниц.
- `git status --porcelain aiqme-site`: новых файлов сверх перечисленных выше нет; медиа, `.env`, ключей нет.
- Не проверено, как и раньше: ручной просмотр кадров админки — **ждёт проверки пользователем**; большие видео через nginx — W-20.

## W-26 «Админка: остальные редакторы контента» — 2026-10-20, Claude Code (ночной прогон)

**Статус: выполнено** (ждёт приёмки; нейтральные надписи и ручной просмотр редакторов — **ждут проверки пользователем**).
Агент не делал commit/push.

### Что сделано

- **ContentTypes** (`app/Domain/Content/ContentTypes.php`) — зарегистрированы все типы §4.1. Поля перевода (`FIELDS`):
  project — task, solution, result (текст); article — intro (текст), sections — новый вид **records** (записи heading/text
  свободного числа: в форме сохранённые и одна пустая для новой, очищенная запись удаляется); training — description;
  product — description, specifications (текст, вывод через Markup), compatibility (строка); faq_item — answer; brand,
  category — только название (и slug у категории). `ContentType::fields()` берёт те же списки (одно место описания).
  **Атрибуты элемента (meta)** — `meta()`, `metaRules()`, `metaToForm()`, `metaFromForm()`: faq_item — группа (ключ услуги,
  `services`, `general`); article — раздел (news/library), категория (`ArticleController::CATEGORIES`); project — связанные
  услуги (несколько), применение (`ProjectController::APPLICATIONS`), YouTube (только https); training — дата (Y-m-d), формат
  (online/aiqme/company); product — категория (ключ), бренд (id). Варианты услуг/категорий/брендов — из БД; чужое значение —
  ошибка проверки. Прочие ключи meta сохраняются. Медиа: проект и товар — несколько фото (`images`), бренд — один логотип
  (новый заменяет прежний).
- **Общий компонент** `ContentEditor` (без отдельных редакторов по типам): блок атрибутов `#content-meta` над полями
  перевода (select, флажки, date, url); «Išsaugoti» сохраняет изменённые языки и атрибуты одной транзакцией
  (`ContentService::updateItem`, создание — `createItem` с meta); публикация/снятие сначала сохраняют изменённое (при ошибке
  атрибутов не выполняются); новый элемент — у всех типов, кроме page_text; ключ — slug LT у типов со slug, у обучения,
  FAQ и брендов ключа нет. Подписи записей статьи — «Skyrius 1 · Antraštė LT» · «Section 1 · Heading EN»; подписи блоков
  page_text не менялись (W-08).
- **Вкладки** (`AdminController`, маршруты из `TABS` + `HIDDEN_TABS`; `routes/web.php` вне touches — нужен маршрутам, как в
  W-08): меню в порядке прототипа — Overview, Products, Services, Projects, Content & SEO, Training, LT / EN content, Audit log.
  `products` — товары («Pridėti produktą +» · «Add a product +») и бренды (h3 «Prekių ženklai» · «Brands») + панель
  «Kategorijos» · «Categories» прототипа со ссылками «Redaguoti ↗» · «Edit ↗»; `categories` — список и редактор категорий
  (адрес из §4.6, пункта меню нет, как в прототипе — подсвечивается Products); `services` — услуги и FAQ (h3 «DUK» · «FAQ»);
  `content` — статьи и новости, затем page_text (h3 «Puslapių tekstai» · «Page texts»); `projects`, `training`.
  `?new=<тип>` вместо `?new=1` W-08.
- **Translations** (`admin/translations`, Livewire `UiTextEditor`, `admin/ui-text-editor`): фиксированный набор — строковые
  ключи lang/ групп `shell`, `public`, `pages`, `account` (тексты админки и служебные `content.*` не правятся); переключатель
  группы (`?group=`), таблица «Raktas · LT · EN» с действующими значениями, правка `?edit=<ключ>`: LT и EN рядом, RU ниже.
  Сохранение — `ContentService::saveUiText()` с ревизией каждого изменённого языка в одной транзакции; конфликт —
  «изменено в другом окне», ввод на месте, ничего не сохранено; пустое поле или текст, равный тексту файла, удаляет
  переопределение. Ключ вне набора — 404; компонент — только администратору (404).
- **Тексты** `lang/lt|en/admin.php`: из прототипа — вкладки «Produktai», «Projektai», «Mokymai», «LT / EN turinys» · «LT / EN
  content», «Pridėti produktą +», «Kategorijos», «Kategorija», «Gamintojas» · «Manufacturer», «YouTube nuoroda (neprivaloma)»,
  «Mokymų vieta» · «Training format» и варианты, «Suderinamumas», «Savybės ir specifikacijos», «Tekstas» · «Text», «Data» ·
  «Date» (без «Demo»); подписи разделов и применений — из `lang/*/public.php`.
- **bin/browser-check**: в `ADMIN_PAGES` добавлены products, categories, projects, training, translations (LT/EN);
  `check_admin_editors_w26` — редактор проекта (атрибуты), новая статья, строка интерфейса на 390 и 1440 (ширина, консоль/CSP),
  на 1440 правка `shell.nav.services` видна в шапке `/en` и откатывается пустым полем. Флаг `--signed-in` — запуск проверок
  после входа вместе с путями (полный прогон дольше 10-минутного предела инструмента агента).

### Решения по неопределённому (простейший вариант) — ждут проверки пользователем

- **Нейтральные надписи (в прототипе нет):** h3 «Prekių ženklai» · «Brands», «DUK» · «FAQ», «Puslapių tekstai» · «Page texts»;
  поля «Užduotis» · «Task», «Sprendimas» · «Solution», «Rezultatas» · «Result» (как на странице проекта, без номера),
  «Įžanga» · «Introduction», «Skyrius» · «Section», «Antraštė» · «Heading», «Atsakymas» · «Answer»; атрибуты «DUK grupė» ·
  «FAQ group» («Visos paslaugos» · «All services», «Bendri klausimai» · «General questions»), «Skiltis» · «Section»
  («Naujienos» · «News», «Biblioteka» · «Library»), «Susijusios paslaugos» · «Related services», «Taikymas» · «Application»;
  ui_text — «Tekstų grupės» · «Text groups», «Meniu ir poraštė» · «Menu and footer», «Puslapiai» · «Pages», «Puslapių
  pavadinimai» · «Page titles», «Paskyra ir formos» · «Account and forms», «Raktas» · «Key», «Tuščias laukas grąžina
  numatytąjį tekstą. Pakeitimas matomas svetainėje iš karto.» · «An empty field restores the default text. The change is
  visible on the site immediately.», «Išsaugota.» · «Saved.»; пустой вариант выбора — «—».
- **Цена, режим продажи, склад, срок товара и ссылка бренда не редактируются:** публичные страницы их не показывают (D-12,
  D-41 — «Pagal užklausą» · «On request»; ссылка бренда нигде не выводится), поле без вывода было бы лишним. Добавить — вместе
  с местом показа. Столбцы «Mode/Demo price» списка товаров прототипа не перенесены — список как у остальных типов.
- **Атрибуты (meta) применяются сразу при сохранении**, без версий и публикации по языкам (они общие для языков, как медиа
  W-08); проверки ревизии у них нет (правка в двух окнах — последняя запись). Сообщение после сохранения — то же «Draft saved.».
- Дата статьи — дата публикации (отдельного поля нет, списки сортируются по ней, как в W-06). Формат обучения хранится и
  правится, но публичные страницы его пока не выводят (как и до W-26).
- Удаления элементов нет (снятие с публикации есть); порядок — после последнего, перестановки нет.
- ui_text без версий и публикации (видно сразу, как задумано W-04); набор групп — константа `UI_TEXT_GROUPS`.

### Проверки (запущены в этой сессии)

- `aiqme-site/bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **377 тестов, 359 passed, 18 skipped** (пропуски §4.6 —
  вкладки других задач и кабинет W-15); Vite build OK.
- `bin/browser-check --signed-in /en` → **`bin/browser-check: OK (2639 проверок)`**: вход администратора через форму, все
  18 адресов админки на 320/390/768/1024/1440 (ширина, §7.1, маркеры D-21, консоль/CSP), гостю и клиенту — 404, редактор
  W-08 (публикация, загрузка, конфликт), `check_admin_editors_w26`, сброс пароля, страница `/en`. Полный прогон всех публичных
  страниц (~14 мин) в этой сессии не запускался: публичные шаблоны W-26 не менял; его выполнит проверка оркестратора.
- Новые тесты `AdminEditorsTest` (12): проект — атрибуты, черновик не виден, публикация EN/LT, фильтр применения, YouTube,
  снятие EN; ошибочные атрибуты (javascript:/http:, чужое применение и услуга) — ошибки, ввод на месте, meta не изменена;
  новость — список новостей и главная, карточка по адресу библиотеки, `/en/news/<slug>` и `/lt/naujienos/<slug>` — 404,
  разделы-записи (пустая запись не сохраняется), смена раздела переносит статью в библиотеку; обучение — без slug/SEO,
  дата/формат, список обучения только после публикации, неверная дата — ошибка; категория → бренд → товар: каталог, фильтры
  категории и бренда, карточка, чужие бренд/категория — ошибка; FAQ — группа general на странице FAQ, затем группа услуги;
  все типы описаны в ContentTypes; фото проекта — несколько, логотип бренда — один; **ui_text — правка EN видна в шапке
  `/en`, LT не меняется, пустое поле возвращает текст файла**; конфликт ревизии ui_text; редактор строк только
  администратору и только для ключей набора; вкладки — списки, кнопки добавления, панель категорий, подсветка меню.
  Обновлены `AdminShellTest` (адреса и меню W-26, FAQ в списке услуг, статьи во вкладке content, `?new=<тип>`),
  `AdminContentEditorTest` (все типы зарегистрированы), `PrototypeRoutesTest` (строка 13 §4.6 для products, categories,
  projects, training, translations теперь проверяется).
- **Тесты ловят поломки** (временные мутации, откачены, `cmp`): meta существующего элемента не сохраняется — 3 теста;
  UiTextEditor без проверки администратора — 1; без проверки атрибутов — 4; пустые записи статьи сохраняются — 1.
- `git status --porcelain aiqme-site`: новые файлы — `app/Livewire/Admin/UiTextEditor.php`, `resources/views/admin/
  {translations,ui-text-editor}.blade.php`, `tests/Feature/AdminEditorsTest.php`; медиа, `.env`, ключей нет; `experiments/`
  не изменён.

### Не проверено

- Ручной просмотр редакторов (атрибуты, записи статьи, строки интерфейса) на 390/1440 — **ждёт проверки пользователем**;
  я посмотрел кадры вкладок products (1440) и translations (390): композиция прототипа, без расползания.
- Полный `bin/browser-check` без аргументов (все публичные страницы) — в этой сессии не запускался (см. выше).
- Вызов ContentTypes::meta()/metaRules() из бота — W-19.

## W-33 «Медиа прототипа по умолчанию» — 2026-10-20, Claude Code (ночной прогон)

### Сделано

- **public/defaults/** (отслеживаемый, 15 файлов, 5,4 МБ): побайтовые копии `logo.jpg`, `favicon.svg`,
  `hero-engineering.mp4`, `hero-engineering-mobile.mp4`, `hero-video-poster.jpg`, `engineering.jpg`,
  `project-{part-reconstruction,prototype,production-fixture}.jpg` из `source-backup…/dist/assets/` (ролики не
  перекодированы) и `services/<ключ>.png` ×6 — фрагменты `concept-dark.png`/`concept-light.png` по координатам D-43 без
  масштабирования (вырезаны один раз Pillow, скрипт — игнорируемый `build/w33/make_defaults.py`). Целых спрайтов нет.
  `public/images/logo-dark.png`, `logo-light.png` (D-25) удалены, каталог `public/images/` больше не существует.
- **Таблица `config('aiqme.media_defaults')`** (тип → ключ записи → коллекция → путь в public/) — единственный источник;
  читают `ContentItem::defaultMedia()` / `ContentItem::mediaDefault()`. Подпись источника ролика по умолчанию —
  `config('aiqme.hero_video_source')` («Bosch / The Apic», ссылка прототипа `…watch?v=1Nd_xg8QfXo`).
- **Вывод** — тот же `public/partials/media` W-05: свой Media → иначе `$mediaDefault` (`<img>` с width/height файла, тем же
  loading, alt `$mediaAlt`) → иначе SVG-заглушка. Места: иллюстрации услуг (главная, список, страница услуги; alt — название
  услуги), фото «О нас» (alt пустой), фото проекта (карточка в списке и примере на странице услуги, страница проекта; alt —
  название проекта; только опубликованные проекты — публичные запросы черновиков не возвращают, D-11). Новый проект или товар
  без медиа — SVG-заглушка.
- **Hero**: видео-партиал получает адреса. Без своего `video` — ролик, мобильный ролик и постер по умолчанию (каждый
  перекрывается своей загрузкой слота) и подпись прототипа без «Manufacturing illustration ·»; со своим `video` умолчания
  hero и подпись прототипа не выводятся (мобильный ролик и постер — только свои, подпись — из источника своего медиа).
  `hero-video.js` не менялся. Кнопка паузы теперь выводится всегда (видео есть всегда).
- **Логотип и favicon**: `x-layout` один раз читает page_text главной (с `rescue` — страница 500 без БД получает умолчания),
  передаёт `$logo` в шапку и подвал и выводит `<link rel="icon">` (своё — PNG, иначе `favicon.svg`) — на всех страницах
  сайта, кабинета и админки (все они на `x-layout`). Новые коллекции `logo` (jpg/png/webp) и `favicon` (png) в
  `ContentItem::MEDIA_COLLECTIONS` и слоты page_text `home` в `ContentTypes::MEDIA`.
- **CSS** (`public.css`): `.split-image` — `overflow:hidden`; `.split-image>img` — `object-position:right center` и
  `scale(1.35)` от правого края, что равно фону прототипа `right center / auto 135%`. Правило места: применяется и к
  загруженному фото «О нас» (простейший вариант — одно правило места, как в прототипе) — **ждёт проверки пользователем**.
- **Админка** (общий редактор W-08): у слота без своего файла — превью файла по умолчанию (картинка 48 px, у видео —
  ссылка) с пометкой; у загруженных изображений тоже превью. При своём video главной умолчания video_mobile/video_poster
  не показываются (на сайте они не используются). «No files yet» — только если нет ни своих файлов, ни умолчаний.
- **Нейтральные надписи (в прототипе нет, rules[8]) — ждут проверки пользователем**: коллекции «Logotipas» · «Logo»,
  «Svetainės ikona (PNG)» · «Favicon (PNG)»; пометка умолчания «(numatytasis)» · «(default)».
- **bin/browser-check**: ffmpeg-ролик и загрузка hero в `BrowserDataSeeder` убраны — reduced-motion и воспроизведение
  проверяются на ролике по умолчанию; `ContentFactory::publishDraft()` публикует LT+EN черновик сидера `prototype`
  (как администратор снимает демо-пометку результата «Demonstracinis rezultatas:» · «Illustrative result:», D-21;
  остальное дословно); `check_defaults` (DEFAULT_MEDIA: `/en`, `/lt`, `/en/services`, `/en/services/engraving`,
  `/en/about`, `/en/projects`, `/en/projects/prototype`; 390 и 1440): `.logo img`, `.hero-video` (постер и оба
  источника), `.service-feature-media img`, `.media-frame img`, `.split-image img`, `.project-image img` — файл из
  `/defaults/` и отрисован; ожидание `load` ленивых картинок в пробах (раньше там были только SVG); `check_admin_editor`
  проверяет превью умолчания до загрузки и после удаления своего файла (и возврат умолчания на страницу услуги).
- **Фикстуры**: из `text-allowlist.json` убраны записи «Bosch / The Apic ↗» (фраза теперь выводится и сверяется по
  порядку); в `marker-allowlist.json` добавлены 4 фразы страниц проекта prototype («a trial prototype printed», «A
  physical prototype», «bandomasis prototipas», «Fizinis prototipas» — предмет проекта, как «prototipai» у 3D-печати).
  Этого файла нет в touches W-33, но без него проверка маркеров на опубликованном проекте, которого требует check[3],
  невозможна (rules[7]).

### Проверки

- `bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **383 теста, 365 passed, 18 skipped** (прежние пропуски §4.6);
  Vite build OK.
- Новый `tests/Feature/DefaultMediaTest.php` (6 тестов): каталог `public/defaults/` = значения таблицы, 15 файлов,
  ≤ 6 МБ, размеры фрагментов по самим файлам; sha256 копий и пиксели фрагментов (5 точек каждого) = исходникам assets/
  (source-backup есть локально — проверено, не пропущено); главная без загрузок — `<video>` с двумя источниками и
  постером /defaults/, подпись и ссылка прототипа, без «Manufacturing illustration»/«Demonstracinė iliustracija»; логотип
  и favicon /defaults/ на публичной странице и в админке; 6 услуг на главной, в списках LT/EN и на страницах услуг
  (src, width/height, alt); «О нас» — engineering.jpg, alt пустой; проект prototype — до публикации 404 и нет фото в
  списке, после — фото на странице, в списке и в примере услуги печати; новый проект без медиа — SVG; перекрытие и возврат
  по слотам logo, favicon, images «О нас», услуги, проекта; постер и мобильный ролик без своего video; своё video — нет ни
  `/defaults/hero-`, ни `1Nd_xg8QfXo`, ни подписи, после removeMedia — снова умолчание; редактор главной, «О нас»,
  опубликованного проекта и услуги — превью /defaults/, после загрузки — превью загруженного, после удаления — снова умолчание.
- **Тесты ловят поломки** (временные мутации, откачены): умолчания hero при своём video — падает тест перекрытия; превью
  умолчания всегда — падает тест админки; карточка проекта без умолчания — падает тест проекта.
- Обновлены `ShellTest` (логотип/favicon /defaults/, каталога images/ нет), `PublicMediaTest` (без медиа — файлы
  /defaults/ вместо заглушек), `PublicPagesTest` («О нас» — engineering.jpg), `AdminMediaTest` (превью умолчания вместо «No
  files yet»).
- `bin/browser-check /lt /en /en/services /lt/paslaugos /en/services/engraving /en/services/3d-printing
  /lt/paslaugos/3d-spausdinimas /en/about /lt/apie-mus /en/projects /lt/projektai /en/projects/prototype
  /lt/projektai/prototype` → **`OK (2415 проверок)`** (ширины, §7.1, маркеры D-21, сверка текста, медиа, умолчания,
  reduced-motion и воспроизведение ролика по умолчанию, без ответов 4xx). `bin/browser-check --signed-in /en` →
  **`OK (2685 проверок)`** (вход, все адреса админки, редакторы W-08/W-26 с возвратом умолчания).
- `git ls-files --cached --others --exclude-standard aiqme-site/public/defaults aiqme-site/public/images`: 15 файлов
  таблицы + ещё числятся `logo-dark.png`/`logo-light.png` только как неподготовленное удаление (в рабочем дереве их нет;
  удаление попадёт в коммит оркестратора). Суммарно 5 424 744 байт; больше 1 МБ — только `hero-engineering.mp4`.

### Не проверено / ждёт проверки пользователем

- Ручная сверка кадров `build/verification/browser-check/shots/` с эталоном evidence — **ждёт проверки пользователем**.
  Я посмотрел главную, «О нас» и список услуг на 1440: ролик, логотип и фото на местах. Иллюстрации услуг выводятся с
  кадрированием загруженного файла (cover на всю рамку, как требует W-33 done[2]); прототип показывал фрагмент целиком по
  ширине рамки — у нас фрагмент 267×121 растянут и обрезан сильнее, выглядит мягче. Это видимое отличие от прототипа.
- Полный `bin/browser-check` без аргументов (все публичные страницы, ~14 мин) не запускался: дольше предела инструмента;
  выполнит проверка оркестратора.
- Демо-сборка W-24 (копия в `aiqme-site/demo/`) — задача W-24.


## W-09: заблокирована ночным прогоном (2026-10-20 16:41)

Причина (оркестратор): ночь остановлена вручную — у пользователя пропадает интернет, работа прервана на середине. Работа — в git stash «night-blocked W-09».

## W-09: Формы обращений, обучение, письма и outbox — выполнено (2026-10-20, продолжение прерванной ночи)

Работа прошлой сессии (код без тестов и без записи здесь) восстановлена в дереве; сверена с done, доделано недостающее.

### Сделано

- **Контактная форма** (`POST /lt/kontaktai`, `/en/contact` → `FormController@contact`): поля прототипа; значение услуги —
  slug опубликованной услуги текущей локали или `equipment`. Запись `contact_requests` и событие `integration_events` —
  в одной транзакции (`App\Domain\Forms\FormSubmissions`); ошибка записи удаляет сохранённые файлы. Успех — сообщение в
  `#contact-result` (место результата прототипа): «AIQME has received your enquiry. Files attached: N.» ·
  «AIQME gavo jūsų užklausą. Pridėta failų: N.».
- **Вложения §6.4**: до 5 файлов по 15 МиБ, расширения прототипа (`config('aiqme.forms')`); PDF/JPEG/PNG — по сигнатуре;
  STL/STEP/OBJ не разбираются. Хранение — диск `local` (`storage/app/private/contact-requests/<UUID>`), исходное имя, размер
  и sha256 — в `attachments`. Выдача — только администратору (`/admin/requests/{id}/files/{n}`): `attachment`,
  `application/octet-stream`, `nosniff`, `Cache-Control: private, no-store`. Ошибки вложений выводятся под полем файлов
  (`x-field` для `name[]` берёт ошибки `name` и `name.*` — раньше они не показывались).
- **Регистрация на обучение**: модалка (компонент W-03, `data-modal-open` на кнопках «Register for a session ↗» W-05/W-06) на
  странице обучения и странице услуги обучения; поля training-form (name, email, format online/aiqme, participants 1–20).
  Запись — `training_registrations` со снимком названия и даты; ответ — возврат на ту же страницу и окно подтверждения
  (`data-modal-autoopen`, после закрытия фокус — на кнопку темы); ошибка проверки — снова открытое окно этой темы. Без JS
  кнопка остаётся ссылкой в контакты с услугой обучения.
- **Предзаполнение §1.2** (`PageController@contact`): GET `service` (slug услуги локали), `product` (slug товара → выбор
  «Equipment quotation» и название товара первой строкой описания), `message` (обрезка до 2000 символов); неизвестные slug и
  массивы игнорируются, вывод — экранированием Blade, файлы не передаются.
- **Honeypot** `website_hp` (вне экрана, без Tab): заполнен — ошибка формы, записи нет, ложного успеха нет.
  **Throttle** `throttle:forms` — общий лимит обеих форм по IP, `AIQME_FORMS_PER_HOUR` (по умолчанию 10/час); 429 —
  страница `errors/429` в разметке 404.
- **Outbox** (`App\Domain\Integrations\IntegrationEvents`): событие с каналами `mail_status`/`sheets_status` (pending), попытки,
  последняя ошибка, время захвата. Задание `DeliverMail` (очередь `mail`, `afterCommit`) захватывает канал одним UPDATE
  (pending/failed или sending старше 5 минут), при 0 строк выходит, отправляет вне транзакции, ставит sent/failed (ошибка
  транспорта — `mail_error`, заявка остаётся). `aiqme:integration-retry` в планировщике `everyFiveMinutes`: pending/failed
  старше 5 минут (по последнему захвату или созданию) и зависшие sending. Канал sheets не обрабатывается до W-18.
- **Письмо** `FormSubmitted` на `info@aiqme.com` (LT): тип, имя, контакты, услуга/обучение, краткое описание (≤200),
  число вложений, ссылка в админку; Reply-To — email клиента; вложений и полного текста нет; клиенту писем нет.
- **Админка**: Overview — заявки (последние 100) со статусом письма, `?request=<id>` — заявка целиком с вложениями и ошибкой
  письма (ссылка из письма); вкладка Training — панель «Training registrations» прототипа со статусом письма и ошибкой.
  Заглушка Overview «Information is being prepared» заменена списком заявок (метрики — W-14). Вместо Livewire
  `Admin/Requests*` из touches — обычный контроллер и Blade (проще; правки заявок нет).

### Простейшие решения и нейтральные надписи (прототип их не показывает) — ждут проверки пользователем

- Тексты модалки и подтверждений — из прототипа без демо-слов (D-21): «Register for a session» · «Registracija į mokymą»,
  «Confirm registration» · «Patvirtinti registraciją», «Registration received» · «Registracija gauta».
- Нейтральные надписи: результат форм («AIQME has received your enquiry. Files attached: N.», «AIQME has received your
  registration.» и LT), сообщения проверки `public.forms.*`, 429 «Too many requests. Please try again later.», тема письма
  обучения «Mokymų registracija», строка письма «Priedai ir visas tekstas peržiūrimi administravimo aplinkoje.», подписи и
  статусы админки `admin.requests.*` (заголовок «Project enquiries» · «Projekto užklausos», «No enquiries yet.»).
  «Nauja projekto užklausa» и «Mokymų registracijos»/«No registrations yet.» — из прототипа.
- Письмо на LT, но название услуги/обучения в нём — на языке формы (как отправил клиент), без перевода.
- Лимит частоты 10/час и лимиты вложений — не утверждённые производственные значения (§6.4).

### Проверки

- Новый `tests/Feature/FormsTest.php` (15 тестов): валидная форма — запись, приватные файлы (UUID, sha256), событие
  sent/pending и одно письмо на info@aiqme.com с Reply-To клиента без вложений; письмо реальным mailer (array): тема, адресат,
  число вложений, без полного текста; невалидная форма и вложения (6 файлов, >15 МиБ, .exe, .svg, поддельные PDF/JPEG/PNG) —
  ничего не сохранено и не отправлено; 5 файлов на пределе, STL/STEP/OBJ с произвольными байтами принимаются; выдача
  вложения — гостю и клиенту 404, администратору attachment/nosniff/no-store; honeypot в обеих формах; throttle общий для форм
  и по IP; предзаполнение (slug локали, чужой и неизвестный slug, экранирование, обрезка до 2000 по символам, product, массивы,
  файлы не передаются); модалка на 4 страницах обучения, регистрация и окно подтверждения, ошибка — окно снова открыто;
  отказ транспорта → заявка сохранена, канал failed с ошибкой в Overview, в заявке и во вкладке Training, sheets pending;
  расписание `*/5 * * * *`; выбор повтора (свежие pending/failed и недавний sending не берутся, sent не трогается);
  два задания одного события — одно письмо; захват — один UPDATE, при 0 строк больше запросов нет; зависший sending
  подбирается; sheets не меняется.
- **Тесты ловят поломки** (временные мутации, откачены): захват без условия статуса; повтор без условия 5 минут; исключение
  транспорта не перехвачено; без проверки сигнатуры; изменение sheets_status при отправке — каждый раз падают 1–4 теста.
- Обновлены `PublicPagesTest` (поле honeypot в форме), `AdminShellTest` (Overview — список заявок вместо заглушки).
  Исправлены ошибки прерванной сессии: Blade Overview не компилировался (смешение `@php(...)` и `@php … @endphp`),
  `x-modal` с атрибутами менял разметку компонента W-03.
- `bin/browser-check`: новые `check_contact_form` (/en/contact @1440: два вложения, результат, письмо в Mailpit на
  info@aiqme.com с данными и числом файлов, без вложений; писем клиенту нет) и `check_training_modal` (/en/training @1440 и
  /en/services/3d-modelling-training @390 на обучении фабрики cad-basics: открытие, фокус в окне, Escape и возврат фокуса,
  отправка, окно подтверждения, Escape → фокус на кнопке темы, письмо в Mailpit). Проверка ловит поломку (получатель
  письма изменён → FAIL «адресаты письма»).
  - `bin/browser-check /en/contact /lt/kontaktai /en/training /lt/mokymai /en/services/3d-modelling-training
    /lt/paslaugos/3d-modeliavimo-mokymai` → **`OK (849 проверок)`** (ширины, §7.1, маркеры D-21, сверка текста, формы).
  - `bin/browser-check --signed-in /en/contact` → **`OK (2396 проверок)`** (вход, все вкладки админки, редакторы, форма).
- `bin/check` → **`bin/check: OK`**: Pint passed; PHPUnit **398 тестов, 380 passed, 18 skipped** (прежние пропуски §4.6);
  Vite build OK.
- `git status --porcelain aiqme-site`: новые файлы только в app/, database/migrations, resources/views, tests/Feature.

### Не проверено

- Полный `bin/browser-check` без аргументов (~14 мин, дольше предела инструмента) — выполнит проверка оркестратора.
- Список заявок в админке в браузере проверен только пустым (вход идёт до отправки форм); с данными — Feature-тестами.
- Ручной просмотр модалки и списков админки — **ждёт проверки пользователем**.
- Реальный SMTP (D-15) — уровень T, не проверялся; локально — Mailpit.

### Повторная сверка (2026-10-21, после остановки сторожем сети)

Работа восстановлена в дереве, код сверен с done (outbox: захват одним UPDATE, повтор `everyFiveMinutes`, sheets не
трогается; формы, вложения, предзаполнение, модалка обучения, админка) — недостающего не найдено, кода не менялось.
Проверки перезапущены в переднем плане:
- `bin/check` → **`bin/check: OK`** (Pint passed; PHPUnit 398 тестов, 380 passed, 18 skipped; Vite build OK).
- `bin/browser-check /en/contact /lt/kontaktai /en/training /lt/mokymai /en/services/3d-modelling-training
  /lt/paslaugos/3d-modeliavimo-mokymai` → **`OK (849 проверок)`**; `bin/browser-check --signed-in /en/contact` →
  **`OK (2396 проверок)`**.
- `git status --porcelain aiqme-site`: новые файлы только в app/, database/migrations, resources/views, tests/Feature.
Не проверено — как выше (полный browser-check, ручной просмотр, реальный SMTP D-15).

## W-10 «Ядро калькулятора: JS-модуль и тесты» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- `resources/js/calculator/core/index.js` — ESM-модуль без DOM, без `three`/STLLoader и без новых npm-пакетов
  (`legacy-landing/` — только образец):
  - `parseStl(ArrayBuffer)` → `{ triangles, positions (Float32Array), size, volume, closed }` в единицах файла.
    Binary — если `84 + 50·N` равно размеру (как `calculator.js`, поэтому binary с заголовком «solid…» читается как binary),
    координаты `DataView.getFloat32`; иначе ASCII по грамматике `solid … (facet normal n n n outer loop (vertex x y z)×3
    endloop endfacet)+ endsolid [имя]` (ключевые слова строчными, пробелы только ASCII — как `\s` PCRE без `/u`; после
    строки `endsolid` — только пробелы). Число ASCII — только по общему шаблону `^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$`,
    затем `Number()` и `Math.fround`; нечисловое после float32 (NaN, Infinity, `1e39`) — отказ; `-0 → 0` до ключей рёбер.
    Отказы — `StlError` с кодом: `size` (> 25 МиБ или не ArrayBuffer), `triangle_count` (0 или > 500 000), `structure`,
    `number`, `coordinate`, `volume` (≤ 0).
  - Объём — `|Σ (a−o)·((b−o)×(c−o)) / 6|` по треугольникам в порядке файла, `o` — первая вершина, деление на 6 в каждом
    слагаемом, как `calculator.js` (у куба 1000 мм³ это 999,9999999999999; центы контрольного примера не меняются).
  - Замкнутость (новая): вершины — по точным float32-координатам, каждое неориентированное ребро должно встретиться
    ровно 2 раза; иначе предупреждение `open_mesh`.
  - `measure(model, { unit: mm|cm|in, scale: 1–1000 %, maxDimensionMm })` → габарит в мм (`size × k`), см³
    (`V × k × k × k / 1000`, `k = мм_на_единицу × масштаб / 100`), предупреждения `open_mesh` и `exceeds_build_size`
    (любой размер `> maxDimensionMm`; равенство — не превышение). Только предупреждения, не отказ.
  - `estimate({ parts: [{ volumeCm3, quantity 1–100 }], material, infill }, { densities, ratePerGram, orderFeeCents,
    fileFeeCents })` — масса и центы по §3.1 (`0,2 + 0,8 × infill / 100`, `floor(mass × rate × 100 + 0,5)`), итог =
    Σ строк + плата за заказ + n × плата за файл. Плотность — только из `densities[material]`; поле `density` у модели —
    отказ. `ratePerGram` — десятичная строка или число (`Number()`).
- Корпус `tests/fixtures/stl/` (16 файлов, 68 КБ): куб 10 мм ASCII/binary/binary с заголовком «solid»/обратная
  ориентация; тетраэдры открытый, с `-0` (ASCII и binary), с дробными координатами разных форм шаблона; отказы — `0x10`,
  `1e39`, NaN, Infinity, усечённый, неверный счётчик, 0 треугольников binary, ASCII без граней. Файлы созданы разовым
  Node-скриптом вне репозитория; корпус — обычные файлы, пригодные для паритета W-11.
- `tests/js/calculator-core.test.js` (`node:test`, 20 тестов): всё из §9.2 для ядра — ASCII = binary, единицы и масштаб
  (матрица mm/cm/in × 1/37/1000 % — точное совпадение с формулой §3.1), обратная ориентация, открытый тетраэдр, отказы,
  шаблон числа (8 допустимых и 12 недопустимых форм), `-0`; оценка: 512 центов на кубе из файла, 20 % → 0,4464 г,
  qty 3 → 37 центов, half-up на ровно x,5 (12,5→13, 37,5→38, 62,5→63), несколько файлов с платами, плотность из
  настроек; во временном каталоге — binary 500 000 треугольников (замкнутая бипирамида с 250 002 вершинами, принят)
  и 500 001 (отказ), ASCII ровно 26 214 400 байт (принят) и +1 байт (отказ).
- `bin/check`: шаг «Node» перенесён до PHPUnit — Node ≥ 22 ищется явно в nvm, иначе `FAIL`; путь экспортируется в
  `NODE_BINARY` (сейчас `/home/greatgleb/.nvm/versions/node/v22.23.1/bin/node`); шаг «Node-тесты ядра калькулятора» —
  `"$NODE_BINARY" --test 'tests/js/**/*.test.js'` (Node 22 не принимает каталог в `--test`: «Cannot find module …/js»,
  поэтому шаблон). Vite-шаг использует тот же Node.

### Простейшие решения (ждут проверки пользователем)

- Габарит оборудования ядро получает параметром (`maxDimensionMm`); значение по умолчанию 250 мм (D-24) — в
  конфигурации/настройках W-27/W-11, в ядре не дублируется. Тарифы D-09 в ядре тоже не зашиты (в тестах — явно).
- Лимит треугольников для ASCII не проверяется отдельно: грань ASCII не короче 86 байт, в 25 МиБ помещается < 305 000.
- ASCII, где `solid` и грани на одной строке, не принимается (имя — до конца первой строки). Ключевые слова в верхнем
  регистре не принимаются. W-11 должен повторить эти правила для паритета.
- Коды предупреждений `open_mesh`/`exceeds_build_size`, тексты для них — в интерфейсе W-27.

### Проверки

- `node --test 'tests/js/**/*.test.js'` → **20 pass, 0 fail** (~1,9 с; 500 000 треугольников — ~1,5 с).
- Тесты ловят поломки (временные мутации, откачены): без `-0 → 0`; рёбра не проверяются; без `Math.fround`; без шаблона
  числа; `floor` без 0,5; округление массы; иной порядок объёма; `>=` для габарита; не каждый размер; плотность клиента
  принимается; плата за файл один раз; сдвиг лимитов треугольников binary и байтов; без модуля объёма; без 0,2/0,8 — каждая
  мутация роняет 1–5 тестов.
- Шаг Node из `bin/check` при `PATH` только с системным Node 18 и без nvm → `FAIL: нужен Node 22 (nvm), найден:
  /usr/bin/node v18`, код 1; при упавшем тесте ядра шаг завершается с кодом 1.
- `bin/check` → **`bin/check: OK`**: Pint passed; Node-тесты 20/20; PHPUnit 398 тестов, 380 passed, 18 skipped; Vite OK.
- `git status --porcelain aiqme-site`: `bin/check`, новые `resources/js/calculator/`, `tests/fixtures/stl/`, `tests/js/`;
  бинарных медиа нет, `three`/STLLoader не подключены.

### Не проверено

- Паритет с PHP — задача W-11 (PHP-ядра ещё нет).
- Работа модуля в браузере (Vite-импорт, `TextDecoder('latin1')`) — подключит W-27; здесь только Node.

## W-27 «Калькулятор: 5-шаговый интерфейс» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- `/lt/kalkuliatorius` · `/en/quote` — `CalculatorController` (в `routes/web.php` одна строка вместо заготовки
  `pages.static`: без неё контроллер не подключить) и `resources/views/public/calculator.blade.php`: 5 шагов прототипа
  (Files → Production → Review → Price → Checkout), сводка справа, «Need help? / Ask a specialist ↗», окно подтверждения
  масштаба (`x-modal`). Тексты — `lang/{lt,en}/calculator.php` (EN — states-followup/quote-*, LT/EN — пары `t()` app.js).
- `config/aiqme.php` → `calculator`: D-09 (PLA 1,24, PETG 1,27, ABS 1,04; `'0.10'` EUR/г; плата 500 центов, за файл 0;
  заполнение по умолчанию 20 %), D-24 (250 мм), §6.4 (25 МиБ на файл, 10 файлов). В JS — `data-settings` (JSON), тексты —
  `data-texts`, адрес контактов — `data-contact-url`. W-11 подставит настройки админки в те же атрибуты.
- JS: `resources/js/calculator/quote.js` — состояние без DOM (класс `Quote`): `addFile(file)` проверяет формат/размер и
  вызывает `addBuffer(name, ArrayBuffer)` — ту же функцию, точку входа для W-15; новая модель сразу активна, поздний разбор
  заполняет только свою запись (удалённую — не возвращает), активную не меняет; оценка — `estimate()` ядра W-10 (центы ядра);
  `specialistMessage()` — вступление, параметры, по строке на модель, обрезка моделей с конца до 2000 символов со строкой
  «Models not listed: N.». `parse-worker.js` — разбор в Web Worker (500 000 треугольников ≈ 1 с в основном потоке — заметная
  блокировка); без `Worker` — в основном потоке. `index.js` — DOM (подключён в `app.js`, работает только при `#quote`).
- Шаг 1: STL; OBJ/STEP — «:name: specialist review required.» со ссылкой на контакты (имена попадают в message); иные
  форматы, размер > лимита (до чтения), отказ ядра — ошибка, модель не добавляется; индикатор «Reading files...». Единицы и
  масштаб по модели, окно «Before/After». Место просмотра — `.viewer[data-viewer]` с габаритом (`data-model-id` активной
  модели); canvas, вращение и подпись — W-31. Шаг 2: без технологии; материал из конфигурации; заполнение 20/40/60/100 +
  умолчание, «· recommended» только у него; цвет, качество, слой, обработка, срок Standard/Priority — пожелания с подписью.
  Шаг 3: строки моделей и «Build size limits», предупреждения `open_mesh`/`exceeds_build_size` + «Get specialist help»;
  не блокирует. Шаг 4: Part / Quantity / Estimate; сводка — модели, плата за заказ (и за файлы, если > 0), «Estimated total»
  с подписью; без VAT/доставки/скидки. Шаг 5: контакты, VAT number (optional), доставка ES / самовывоз (адрес скрыт);
  кнопка отправки `disabled` с подписью, имитации успеха нет.
- CSS (`public.css`, 2 правила): `#quote [hidden]{display:none}` (классы прототипа перекрывали hidden) и
  `.quote-layout>div{min-width:0}` — на 390 таблица шага 4 (min-width 550) растягивала страницу до 594 px.

### Нейтральные надписи и упрощения (ждут проверки пользователем)

- Вводный текст: «…review a preliminary price.» · «…peržiūrėkite preliminarią kainą.» (вместо «illustrative/demonstracinę»);
  он же meta description.
- Шаг 1: «STL, STEP and OBJ. You can select several files.» (без «In this prototype…»); «Up to :mb MB per file. STEP files
  require specialist review.» — число из конфигурации (25), единица «MB», как в прототипе; «Upload a model.» · «Įkelkite
  modelį.» (без «or choose a demonstration example»); «:name: up to :max files per quote.» · «:name: daugiausia :max failų
  vienoje užklausoje.»; ошибки файла с префиксом имени («:name: File exceeds the 25 MB limit» и др. из прототипа).
  `triangle_count` binary > 500 000 — «Too complex for preview; specialist review», прочие отказы — «Could not read geometry».
- Окно масштаба: «This changes the physical size and price.» · «Pasikeis fizinis gaminio dydis ir kaina.».
- Шаг 2: «Standard»/«Priority» · «Standartinė»/«Prioritetinė»; подпись «Colour, quality level, layer height, post-processing
  and schedule are preferences and do not change the estimate.» · «Spalva, kokybės lygis, sluoksnio aukštis, apdirbimas ir
  darbų eiga yra pageidavimai ir sąmatos nekeičia.»; количество 1–100 (лимит ядра; в прототипе 1000).
- Шаг 3: «:name: the model surface is not closed (open mesh).» · «:name: modelio paviršius neuždaras (atviras tinklelis).»;
  «:name: exceeds the 250 mm machine limit.» (без «illustrative»); «Geometry read.» (первое предложение прототипа).
  Плашка «Manufacturing analysis is illustrative…» и демо-«success» не выводятся.
- Шаг 4: LT столбец «Sąmata» (EN «Estimate» из задачи); фразы о сроках и «real system…» не выводятся.
- Сводка: «PRELIMINARY ESTIMATE» · «PRELIMINARI SĄMATA»; «Order fee» · «Užsakymo mokestis»; «File fee» · «Failų mokestis»;
  LT итог «Preliminari suma»; подпись «Preliminary estimate. Delivery and the final price are confirmed by the
  administrator.» · «Preliminari sąmata. Pristatymą ir galutinę kainą patvirtina administratorius.». Без моделей — итог 0,00.
- Шаг 5: «You can check out without an account.» (без «Use sample data only.»); самовывоз «Collection» · «Atsiėmimas»;
  кнопка «Submit order» · «Pateikti užsakymą» (недоступна), подпись «Order submission is not available yet.» ·
  «Užsakymo pateikimas dar neįjungtas.». Демо-плашки НДС, предоплаты и флажок согласия не выводятся.
- Сообщение специалисту: «Please help review these files:» (прототип), затем «FDM / PLA, Infill: 20%, Colour: …»,
  строки «- имя: 10.0 × 10.0 × 10.0 mm · Quantity: 1»; пометка «Models not listed: N.» · «Nenurodyta modelių: N.».
  service — slug опубликованной услуги печати (нет — без service).
- sessionStorage для метаданных шагов не используется (план — «можно»): после перезагрузки всё заново.

### Проверки

- `node --test tests/js/calculator-quote.test.js` → 9/9: File = ArrayBuffer (куб, 100 % → 512 центов, «€5.12» / «5,12 €»);
  второй файл до окончания разбора первого — активен второй и его результат (управляемые обещания); удалённая модель не
  возвращается; OBJ/STEP/PNG/размер — без разбора; >500 000 треугольников — too_complex; лимит файлов; единицы/масштаб
  по модели, материал/заполнение общие, итог = строки + платы; сообщение на 10 моделях (длинные имена → ≤ 2000 и «Models
  not listed: N», короткие → все 10). Мутации (активная после позднего разбора, возврат удалённой, без обрезки) роняют тест.
- `tests/Feature/CalculatorPageTest.php` → 7/7: data-атрибуты = конфигурация, шаги LT/EN, подпись лимита из конфигурации
  (10 МиБ → «10 MB»), шаг 2 без технологии и список заполнения (умолчание 30 → добавлено и «rekomenduojama» только у него),
  столбцы, итог и подпись, VAT-код, отправка disabled, нет демо-фраз/VAT/технологий, ссылка специалиста.
- `bin/browser-check /lt/kalkuliatorius /en/quote` → **OK (417 проверок)**; `check_calculator` на 390 и 1440 при отключённом
  canvas: ошибки, масштаб с окном, шаги 1–5, итог «5,12 €»/«€5.12» = сумма строк сводки (512), нет кнопок технологии и
  строк VAT/доставки/скидки, VAT-код есть, отправка недоступна; открытый тетраэдр — предупреждение, переход доступен;
  ссылка специалиста заполняет контактную форму (услуга печати, описание); крупный binary ~25 МБ (333 000 вершин) — есть
  индикация, интервал страницы < 400 мс, куб, выбранный следом, активен. Проверено, что без Worker проверка падает
  («нет индикации», «страница замирала на ~700 мс»). Маркеры D-21 — по каждому шагу.
- `bin/check` → **OK**: Pint; Node 29/29; PHPUnit 405 (387 passed, 18 skipped); Vite.
- `git status --porcelain aiqme-site`: изменены bin/browser-check, config/aiqme.php, resources/css/public.css,
  resources/js/app.js, routes/web.php; новые — контроллер, lang calculator, resources/js/calculator/{index,quote,parse-worker}.js,
  шаблон, 2 теста. Бинарных файлов нет, новых npm-пакетов нет.

### Не проверено

- Полный `bin/browser-check` (все страницы и вход) не запускался — только страницы калькулятора.
- Вид шагов рядом с эталоном — ждёт проверки пользователем (кадры: build/verification/browser-check/report.html).
  Пустое место просмотра до W-31 — серая рамка с габаритом.
- Drag-and-drop проверен только кодом (браузерная проверка выбирает файлы через input).

## W-31 «Калькулятор: просмотр модели Canvas 2D» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- `resources/js/calculator/viewer/index.js` — свой код (app.js не копировался, D-19), параметры поведения из
  initViewer/drawViewer прототипа: начальный вид x −0,55 / y 0,6 рад, масштаб 1; перетаскивание 0,008 рад/px; стрелки
  ±0,15 рад; клавиши +/= и − — ×1,1 и ÷1,1; кнопки + / − — ×1,15 и ÷1,15; колесо ×0,93 / ×1,07 (`preventDefault`, страница
  не листается); масштаб 0,3–3; ↺ — исходный вид. Рисуется каждый k-й треугольник, k = ⌈n / 8 500⌉ (500 000 → каждый
  59-й, 8 475 граней), сортировка от дальних к ближним, тон грани по нормали, сетка фона, буквы X/Y/Z, DPR ≤ 2 — как в
  прототипе. Без WebGL и three, новых npm-пакетов нет.
- `projectFaces()` и `viewForKey()`/`zoomed()` — без DOM (тесты Node); `mountViewer(.viewer)` — события canvas и кнопок.
  Без canvas (`getContext('2d')` → null) просмотр ничего не рисует и не бросает исключений; расчёт от него не зависит.
- `index.js`: при смене активной модели (тот же блок, что ставит единицы и масштаб) — `viewer.show(active.model)`;
  передаётся сетка разбора ядра (`positions`). Вид при смене модели сохраняется (как глобальный viewRotation прототипа).
- Шаблон `.viewer`: подпись, `canvas#model-canvas` (tabindex 0, role img, aria-label), габарит, кнопки + − ↺ —
  разметка и классы прототипа (CSS прототипа `.viewer*` уже был). Тексты `calculator.viewer.*` — EN из
  more-states/viewer-keyboard/dom.html, LT — пары t() app.js: «GEOMETRIJOS PERŽIŪRA · Sukite vilkdami», «3D peržiūra.
  Rodyklėmis sukite, pliusu ar minusu keiskite mastelį.», «Priartinti», «Atitolinti», «Atkurti vaizdą».

### Простейшие решения (ждут проверки пользователем)

- Подписи «DEMO MODEL» и «PLACEHOLDER GEOMETRY · STEP NOT PROCESSED» и текст «Re-upload the file…» не переносятся:
  демо-моделей нет (D-21), STEP/OBJ не разбираются и в просмотр не попадают, после перезагрузки моделей нет вовсе (W-27).
- Перерисовка синхронная на каждое событие (как в прототипе), без requestAnimationFrame; размер canvas — по clientWidth
  в момент рисования (изменение окна без события не перерисовывает — как в прототипе).
- Кнопки + − ↺ при недоступном canvas остаются видимыми (ничего не делают): простейший вариант.

### Проверки

- `node --test tests/js/calculator-viewer.test.js` → 7/7: клавиши (±0,15, ×1,1, чужая — null), пределы 0,3/3; куб — 12
  граней по глубине, центр окна, ребро 0,72 меньшей стороны; 500 000 треугольников → ≤ 8 500 с равномерным шагом;
  ближний треугольник последним и смена порядка после поворота на π; `mountViewer` на заглушках — клавиша, колесо,
  кнопка, перетаскивание (и его конец), сброс, число заливок; без canvas — не падает. Мутации (без сортировки, без
  прореживания, неравномерный шаг) роняют тесты.
- `tests/Feature/CalculatorPageTest.php` → 8/8 (новый: подпись, canvas с aria-label/tabindex/role и кнопки LT/EN).
- `bin/browser-check /lt/kalkuliatorius /en/quote` → **OK (481 проверка)**. Новый `check_viewer` на 390 и 1440: после
  загрузки `tests/fixtures/stl/cube-10mm-binary.stl` на canvas > 1000 непрозрачных пикселей заливки модели; ArrowRight,
  клавиша +, кнопки + и −, колесо, перетаскивание меняют `toDataURL`, ↺ возвращает снимок, совпадающий с исходным; колесо
  над canvas не листает страницу. Мутации (колесо без preventDefault, сброс на другой вид) роняют проверку. W-27
  `check_calculator` (canvas отключён `getContext = () => null`) проходит все 5 шагов с итогом 5,12 — расчёт без canvas
  работает и теперь при реально подключённом просмотре; отправка шага 5 до W-12 недоступна по плану, проверить её
  без canvas можно будет только после W-12.
- Модель предельного размера: 500 000 треугольников (41 666 кубов решёткой 35×35×35, binary ~25 МБ, генерируется
  проверкой во временном каталоге `large-grid.stl`) — нарисована (пиксели модели есть). Время кадра (синхронная
  перерисовка по ArrowRight, 10 кадров, bundled Chromium, эта машина): /lt @390 — медиана 20,6 мс, максимум 35,7;
  /lt @1440 — 22,1 / 34,7; /en @390 — 21,1 / 34,5; /en @1440 — 21,9 / 38,3 мс. Порог проверки — < 100 мс на кадр.
- `bin/check` → **OK**: Pint; Node 36/36; PHPUnit 406 (388 passed, 18 skipped); Vite.
- `git status --porcelain aiqme-site`: изменены bin/browser-check, lang/{en,lt}/calculator.php,
  resources/js/calculator/index.js, шаблон калькулятора, CalculatorPageTest; новые — resources/js/calculator/viewer/,
  tests/js/calculator-viewer.test.js. Бинарных файлов нет.

### Не проверено

- Вид просмотра рядом с эталоном (цвет, освещённость граней) — ждёт проверки пользователем; кадр прототипа —
  evidence/audit-20261016/more-states/viewer-keyboard/screen.png.
- Касание и перетаскивание пальцем на реальном телефоне (проверено только мышью Playwright), производительность на слабых
  устройствах. Полный `bin/browser-check` по всем страницам не запускался — только страницы калькулятора.

## W-11 «Серверный анализ STL и настройки калькулятора» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- `app/Domain/Pricing/StlAnalyzer.php` (+ `StlModel`, `StlException` с кодом отказа ядра JS): binary/ASCII по шагам
  `resources/js/calculator/core/index.js` в том же порядке операций. Координаты — float32: binary `unpack('g9')`, ASCII
  `unpack('g', pack('g', $x))`; -0 → 0 до ключей рёбер (ключ вершины — `pack('g3')`); тот же шаблон числа ASCII; пробелы —
  только ASCII (`\x0B`, а не класс `\v` PCRE, который ловил бы `\x85`). `analyzeFile()` проверяет размер (`filesize`) и
  счётчик binary (84 байта заголовка) до чтения тела. `measure()` — единицы/масштаб к объёму и габариту, предупреждения
  `open_mesh` и `exceeds_build_size`. Константы 25 МиБ и 500 000 — как в ядре JS.
- `EstimateService` — §3.1: `floor(mass × rate × 100 + 0,5)` в double без bcmath, одна цена за грамм, плата за заказ и
  n × плата за файл, в результате — снимок настроек (`settings`), по которым посчитано (для W-12).
- Таблица `settings` (ключ → JSON, миграция `2026_10_21_100000`) и модель `App\Models\Setting` (общая для W-17/W-18,
  поэтому в app/Models, а не в Domain/Pricing). Миграция данных `2026_10_21_100001` кладёт `calculator` (D-09: PLA 1,24,
  PETG 1,27, ABS 1,04; 0.10 €/г; 500 центов; плата за файл 0; заполнение 20 %; D-24: 250 мм) через `insertOrIgnore` —
  только если ключа нет. `CalculatorSettings` (Domain/Pricing) читает и сохраняет; список `unconfirmed` внутри значения.
- Вкладка админки `/lt/administravimas/calculator` · `/en/admin/calculator` (после Services, как в меню прототипа),
  Livewire `Admin\CalculatorSettings`: строки «материал — плотность» (+ пустая строка для нового, пустое имя — удалить),
  цена за грамм, плата за заказ и за файл в EUR (хранятся центы, перевод строкой без float), заполнение по умолчанию 0–100 %,
  габарит 10–2000 мм. Только администратору (boot → 404).
- Страница калькулятора: `data-settings` = настройки из БД + `max_file_bytes`/`max_files` из `config('aiqme.calculator')`;
  тарифы, заполнение и габарит из config удалены (лимиты §6.4 остались там и в админке не редактируются).
- `bin/browser-check`: вкладка calculator в ADMIN_PAGES (маркеры D-21, ширина, §7.1, консоль) и `check_calculator_settings`.

### Тексты (rules[8])

- Из прототипа (verified-route-screens/*admin__calculator/dom.html): вкладка «Calculator» · «Kalkuliatorius»,
  «Materials, settings & pricing» · «Medžiagos, parametrai ir kainodara», «Save parameters» · «Išsaugoti parametrus»,
  «Build size, mm» · «Įrangos kraštinė, mm» (из «Demo build size, mm» · «Demo įrangos kraštinė, mm» без «Demo», D-21).
- Не переносятся: базовые цены FDM/SLA/SLS/Metal, скидка, notice «Illustrative rules only…», фраза «The prototype does not
  invent…», выбор технологии и «Materials, comma separated» (у нас материалы с плотностями).
- Нейтральные (в прототипе нет): «Material» · «Medžiaga» (как в калькуляторе), «Density, g/cm³» · «Tankis, g/cm³»,
  «Price per gram, €» · «Kaina už gramą, €», «Order fee, €» · «Užsakymo mokestis, €», «Fee per file, €» · «Mokestis už
  failą, €», «Default infill, %» · «Numatytasis užpildas, %», «Not confirmed» · «Nepatvirtinta» (дописывается к подписи
  через « · »), подсказка о пустой строке материала, «Saved.» · «Išsaugota.», «Check the value.» · «Patikrinkite reikšmę.»
  (одно сообщение для всех ошибок проверки).

### Простейшие решения (ждут проверки пользователем)

- «Не подтверждено» снимается с поля, только когда администратор меняет его значение (буквально по done[2]); сохранение
  без изменений пометку оставляет. Если реальные тарифы совпадут с примерными, пометку снять нечем — отдельной кнопки
  «подтвердить» не делал. Цена за грамм сравнивается строкой («0.1» после «0.10» считается изменением).
- Пометка относится к материалам с плотностями, цене за грамм, обеим платам и габариту; заполнение по умолчанию не помечается.
- Изменения настроек не пишутся в Audit log (в §1.4 журнал — цена заказа, статусы, оплата, публикации).
- «Версионный снимок» (§2.5) понят как полный набор значений в результате EstimateService; номера версии нет.

### Проверки

- `tests/Unit/StlAnalyzerTest.php` → 19/19: куб ASCII/binary/«solid»-binary одинаковы (12 треугольников, 1000 мм³,
  10×10×10, замкнут), обратная ориентация, объём по float32 (и отличие от расчёта в double > 1e-9), -0/0 — одна вершина,
  открытый тетраэдр → `open_mesh`, 8 отказов корпуса (number, coordinate, structure, triangle_count), формы чисел ASCII,
  грамматика и хвост после endsolid, VT — пробел, `\x85` — нет, единицы/масштаб, габарит по каждому размеру, неверные
  unit/scale/габарит; отказ по размеру и по счётчику binary без чтения тела (разрежённый файл, рост пиковой памяти < 1 МиБ).
- `tests/Feature/StlParityTest.php` → 1 тест, 44 010 проверок: PHPUnit запускает `NODE_BINARY tests/js/stl-parity.mjs`
  (ядро JS) и сравнивает на 16 файлах корпуса + 18 сгенерированных во временный каталог (случайные binary 3000 и ASCII 800
  треугольников с 17 знаками, 11 форм чисел, хвосты, VT/NEL): ≥15 принятых и ≥15 отклонённых, коды отказов, число
  треугольников и замкнутость — точно, объём/габарит/см³/масса — 1e-9 относительно, предупреждения и центы — точно; матрица
  mm/cm/in × 1, 37, 100, 1000 % × 4 материала × заполнение 20/100 × qty 1/3 (> 2000 комбинаций) по настройкам из БД
  (0.137 €/г, плата за файл 35 центов) и общая заявка из всех принятых файлов. Без `NODE_BINARY` — провал с сообщением.
  Мутации PHP (ASCII без float32, без -0 → 0, без VT в пробелах, рёбра «≥ 2») роняют тест. Не ловятся (укладываются в
  допуск 1e-9 и не меняют центы на корпусе): `/6` → `*(1/6)`, иной порядок множителей заполнения, `round()` вместо
  `floor(x+0,5)` (half-up проверяет EstimateServiceTest).
- `tests/Feature/StlAnalyzerLimitsTest.php` → 3/3: binary 500 000 треугольников (125 000 замкнутых тетраэдров) в подпроцессе
  `php -d memory_limit=512M` — принят, замкнут, объём верен; **пиковая память 269,9 МиБ, 1,09 с**. Отдельно вручную:
  500 000 треугольников без общих вершин (худший случай для карты рёбер) — 369,9 МиБ, 1,40 с. 500 001 — `triangle_count`;
  ASCII ровно 26 214 400 байт с пробелами — принят, 26 214 401 — `size`.
- `tests/Feature/EstimateServiceTest.php` → 4/4: куб 10 мм → 1,24 г, 12 + 500 = 512 центов; 20 % → 0,4464 г; qty 3 →
  3,72 г и 37 центов; half-up 12,5 → 13, 37,5 → 38, 62,5 → 63, 0.4999 → 12; два файла ABS 20 % с платой 700 и 150 за файл
  → 4 + 60 + 700 + 300 = 1064, снимок = текущие настройки; отказы (чужой материал, qty 0/101, объём 0, заполнение 101, пусто).
- `tests/Feature/CalculatorSettingsTest.php` → 7/7: чистая БД после migrate — значения D-09/D-24 в data-атрибутах
  /en/quote и /lt/kalkuliatorius; повторная миграция данных не затирает изменённое; вкладка LT/EN — заголовок, кнопка,
  пометки, без демо-цен/скидки/«Illustrative»/лимитов; гость и клиент — 404 (страница и Livewire); после сохранения в
  Livewire (плотность, удалён ABS, добавлен TPU, 0.125 €/г, 7,50 €, 1,05 €, 30 %, 300 мм) обе страницы выводят новые
  значения, а посторонние значения в `config('aiqme.calculator.*')` на страницу не попадают (тест сначала поймал слияние
  всего config — исправлено); EstimateService считает по ним (16 + 33 + 750 + 210); пометка снимается только с изменённого;
  14 неверных вводов не сохраняются.
- `CalculatorPageTest` и `AdminShellTest` обновлены (настройки из БД; вкладка Calculator в меню после Services).
- `bin/browser-check --signed-in /lt/kalkuliatorius /en/quote` → **OK (2916 проверок)**: вход, все вкладки админки с новой
  calculator (маркеры, ширина, консоль/CSP), `check_calculator_settings` (390/1440; на 1440 габарит 300 сохранён через
  Livewire и виден в data-settings /en/quote, пометка снята, затем 250), W-27/W-31 калькулятор (итог 5,12) — без изменений.
- `bin/check` → **OK**: Pint; Node 36/36; PHPUnit 440 (422 passed, 18 skipped, паритет с NODE_BINARY); Vite.
- `git status --porcelain aiqme-site`: изменены AdminController, CalculatorController, bin/browser-check, config/aiqme.php,
  lang/{en,lt}/admin.php, quote.js (комментарий), шаблон калькулятора (комментарий), 3 теста; новые — app/Domain/Pricing/,
  Livewire CalculatorSettings, app/Models/Setting.php, 2 миграции, 2 шаблона админки, 5 тестов, tests/js/stl-parity.mjs.
  Бинарных файлов, .env и ключей нет; новых пакетов нет.

### Не проверено

- Вид вкладки Calculator рядом с эталоном (сетка «материал — плотность», подписи с « · Not confirmed») — ждёт проверки
  пользователем; кадры эталона — evidence/audit-20261016/verified-route-screens/en__admin__calculator/.
- Полный `bin/browser-check` — см. доработку ниже (зелёный после исправления).
- StlAnalyzer пока вызывается только тестами: приём файлов заявки — W-12.

### Доработка после приёмки (2026-10-21)

Замечание: «bin/check зелёный, bin/browser-check красный» — `/en/admin/calculator @1440: на /en/quote габарит 300,
сохранено 250`. Причина в самой проверке, а не в сохранении: после первого сохранения (300) плашка «Saved.» остаётся
на странице, поэтому при втором сохранении (250) `wait_for_selector('… .notice.success:has-text("Saved.")')` срабатывал
сразу, до ответа Livewire, и /en/quote читался ещё со старым значением 300. Исправлено в `check_calculator_settings`:
нажатие «Save» обёрнуто в `page.expect_response(POST …livewire…)` — проверка ждёт ответа именно этого запроса и лишь
затем открывает /en/quote. Код приложения не менялся.

- `bin/browser-check` (полный, все страницы) → **OK (12949 проверок)**, включая оба сохранения 300 → 250.
- `bin/check` → **OK**: Pint; Node 36/36; PHPUnit 440 (422 passed, 18 skipped); Vite.

## W-12 «Отправка заявки на изготовление» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- Миграция `2026_10_21_200000_create_order_tables`: `counters` (имя → значение), `stl_files` (владелец user_id или заказ
  order_id, путь UUID, исходное имя, размер, sha256, треугольники, `analysis` с версией анализатора), `stl_parts`
  (параметры, измерения, оценка в центах), `order_charges` (вид, сумма, заказ), `order_meta` (номер unique, хеш гостевого
  токена unique, `submission_token` unique, `submission_digest`, локаль, контакты, получение, адрес, снимок настроек).
- Модели `StlFile`, `StlPart`, `OrderCharge` (Purchasable; общее — трейт `Domain/Orders/PricedLine`: цена Lunar = итог
  строки в центах, quantity в корзине всегда 1, налоговый класс по умолчанию), `Domain/Orders/Models/OrderMeta`.
- `Domain/Orders/OrderSubmission` (§3.1): StlAnalyzer по каждому файлу (отказ — 422 с именем файла, тексты ядра JS),
  пересчёт `EstimateService` по текущим настройкам (клиентская сумма не читается), отпечаток данных, повтор токена; файлы на
  диск local `stl/<uuid>.stl`, затем одна транзакция: корзина Lunar → StlPart × n (+ OrderCharge `fee`, если плата > 0) →
  `createOrder()` через `RequestCartValidator` → `placed_at`, привязка файлов и fee к заказу, сверка суммы заказа с оценкой,
  `order_meta`, событие outbox `order.submitted`. Любая ошибка после записи файлов удаляет их; конфликт уникальности
  `submission_token` (параллельная отправка) — ответ тем же заказом.
- `Domain/Orders/RequestCartValidator` (из эксперимента): только StlPart и OrderCharge, quantity = 1; 1–10 строк StlPart
  (fee не считается); `parameters.quantity` 1–100; файл — владельца корзины и ещё без заказа (у гостя сравнение user_id
  null === null само по себе ничего не доказывает, §6.1). `Domain/Orders/OrderNumber` — генератор номера Lunar:
  `insertOrIgnore` строки счётчика (первый номер — `AIQME_ORDERS_FIRST_NUMBER`, по умолчанию 1) и
  `UPDATE counters … RETURNING value`; номер пишется в `reference` и `order_meta.number`.
- Команда `aiqme:bootstrap`: языки lt (по умолчанию) и en, EUR по умолчанию, канал webstore, группа retail, налоговая зона
  и класс по умолчанию, ставок нет; `firstOrCreate` — повтор ничего не меняет. Добавлена в `bin/setup` и в подготовку
  `bin/browser-check` после `migrate:fresh`.
- `OrderRequestController`: POST на адрес калькулятора (`/lt/kalkuliatorius`, `/en/quote`, middleware `throttle:orders` —
  `AIQME_ORDERS_PER_HOUR`, по умолчанию 10 в час по IP), honeypot `website_hp` как у форм W-09 (ошибка формы, без номера);
  ответ 201 `{redirect}`; номер, сумма и гостевая ссылка — во flash сессии. Подтверждение `/lt/kalkuliatorius/pateikta` ·
  `/en/quote/submitted` (без только что отправленной заявки — 404). Гостевая страница-заготовка `/lt/uzsakymas/<номер>/<токен>`
  · `/en/order/<номер>/<токен>`: номер, файлы × количество, предварительная сумма; неверный номер или токен — 404. Обе — noindex,
  `Cache-Control: no-store, private`.
- Гостевой токен — 32 байта base64url, в БД только SHA-256; повтор с тем же токеном отправки перевыпускает его (прежняя
  ссылка — 404). Заказ клиента: `user_id` заказа и файлов, гостевого токена нет, на подтверждении — ссылка в кабинет.
- Калькулятор: форма шага 5 — `@csrf`, скрытый `submission_token` (UUID на каждый показ формы), honeypot; профиль вошедшего
  клиента подставлен (имя профиля или аккаунта, email аккаунта, компания, коды, адрес, город, индекс). `index.js` отправляет
  один `FormData` fetch-запросом: поля формы, параметры шага 2 и по каждой модели файл (байты из записи модели — `quote.js`
  хранит `buffer`), единицы, масштаб, количество; 422 — ошибки под полями, прочие — списком; 429/413/сбой — понятные тексты.
  Подпись «отправка недоступна» и `disabled` убраны.
- Тело больше `post_max_size` (`PostTooLargeException`, до маршрута и локали) — JSON 413 с текстом о лимите на языке
  адреса (`bootstrap/app.php`); файл больше `upload_max_filesize` — 422 «File exceeds the 25 MB limit».
- Письмо: тот же `FormSubmitted` (kind `order`): тема «Naujas gamybos užsakymas», имя, контакты, номер, файлы, предварительная
  сумма, ссылка в админку `?order=<id>` (вкладки заказов ещё нет — W-14). Канал sheets — `pending`.
- Вне touches (минимально): `StlAnalyzer::VERSION` (версия анализатора в `stl_files.analysis`, §2.5), обработчик 413 в
  `bootstrap/app.php`, `.env.example` (2 переменные), `bin/setup`, нормализация токена отправки в `PrototypeRoutesTest`
  (сравнение страниц «со / и без /» — токен новый на каждый показ).

### Тексты (rules[8])

В прототипе подтверждения и гостевой страницы нет (он создаёт демо-заказ и открывает его карточку). Из прототипа:
«Order contents» · «Užsakymo turinys», «MANUFACTURING ORDER» (eyebrow калькулятора), «Estimated total» и подпись сводки.
Нейтральные надписи: «Order submitted» · «Užsakymas pateiktas»; «AIQME has received your order No. :number. The
administrator will review the files and confirm the price.» · «AIQME gavo jūsų užsakymą Nr. :number. Administratorius
peržiūrės failus ir patvirtins kainą.» (по образцу «AIQME has received your enquiry» W-09); «Your order link. Save it: it is
shown only once.» · «Jūsų užsakymo nuoroda. Išsaugokite ją: ji rodoma tik vieną kartą.»; «The order is saved in your
account.» · «Užsakymas išsaugotas jūsų paskyroje.»; «Order No. :number» · «Užsakymas Nr. :number»; «This order has already
been submitted with other details. Reload the page and try again.» · «Šis užsakymas jau pateiktas su kitais duomenimis.
Atnaujinkite puslapį ir bandykite dar kartą.»; «The files are too large to send: up to :mb MB per file.» · «Failai per
dideli išsiųsti: iki :mb MB vienam failui.»; «Quantity: from 1 to 100.» · «Kiekis: nuo 1 iki 100.»; «The order was not sent.
Please try again.» · «Užsakymas neišsiųstas. Bandykite dar kartą.»; письмо: «New manufacturing order» · «Naujas gamybos
užsakymas», поля «Order» · «Užsakymas», «Estimated total» · «Preliminari suma». Остальные сообщения — тексты форм W-09 и ядра.

### Простейшие решения (ждут проверки пользователем)

- Номер и гостевая ссылка передаются странице подтверждения через flash сессии (сессии зашифрованы, §6.3); после
  перезагрузки подтверждения — 404, ссылка не показывается повторно.
- Статус заказа Lunar не меняется (черновой `awaiting-payment` Lunar), ставится только `placed_at`: стадии производства —
  W-13. Lunar `Customer` и адреса заказа Lunar не создаются: контакты и адрес — в `order_meta`.
- На `/ru/quote` (только администратор) отправки нет: маршрут заявки — только lt/en (локаль заявки — lt/en), кнопка недоступна.
- Заполнение принимается любым целым 0–100 (EstimateService), интерфейс предлагает список прототипа.
- Отказ сервера по `triangle_count` (> 500 000) — текст «Could not read geometry», а не «Too complex» (интерфейс такие файлы
  отклоняет раньше).
- `aiqme:bootstrap` создаёт только отсутствующие записи и существующие не исправляет (например, другую валюту по умолчанию).
- В отпечаток отправки входят пользователь, локаль, контакты, получение, адрес, параметры и по файлу sha256, имя, единицы,
  масштаб, количество.

### Тесты эксперимента (orders-comparison/shared/Tests) → наши

`test_submission_persists_two_private_stl_lines_without_payment` → `OrderSubmissionTest::test_guest_submission_…` (две
приватные строки, разные sha256, без транзакций, файл не в public); `test_empty_basket_and_bad_quantity_are_rejected` →
`test_invalid_input_…` и `OrderCartTest::test_quantity_…`, `test_cart_with_only_a_fee_…`;
`test_other_buyer_cannot_…_attach_to_private_basket` → `OrderCartTest::test_file_of_another_owner_or_already_ordered…`;
повтор `submit` того же basket → `test_double_submit_…`; `test_order_list_does_not_expose_another_buyers_order` →
`test_guest_order_is_not_visible_…`, `test_signed_in_client_owns_the_order_…`. Тесты корзины (add/change/remove — у нас
корзина собирается сервером за один запрос), скачивания STL (W-32), оплаты и цены (W-13, W-16, W-28) здесь не переносились.

### Проверки

- `tests/Feature/OrderSubmissionTest.php` → 18/18: гость — заказ Lunar 549 центов = сумма строк = сумма на подтверждении
  (37 за qty 3, а не 3 × 12; 12; fee 500), quantity строк 1, `parameters.quantity`, налог 0, клиентские `total`/`cents`
  игнорируются, файлы приватные (UUID, sha256), `order_meta` (контакты, адрес, локаль, снимок), токен 43 символа, в БД только
  хеш; гостевая ссылка LT/EN — 200, чужой токен/номер — 404, подтверждение только один раз; двойная отправка — один заказ,
  одно событие, 2 файла, старая ссылка 404, новая 200; тот же токен с другими данными — 422 без записей; номера 1001–1003 из
  счётчика; событие в транзакции, письмо на info@aiqme.com (Reply-To клиента, тема, файлы, 5.49 EUR), sheets pending; сбой
  после записи файлов (исключение в `order_meta`) — нет файлов, заказов, событий, писем, номер не израсходован (следующий —
  1); сбой почты — заказ остаётся, mail failed; honeypot — 422 без номера и записей; лимит 2/час — третья 429 без файлов,
  заказа, события и письма, другой IP — принят; `UPLOAD_ERR_INI_SIZE` — «File exceeds the 25 MB limit» (LT/EN); тело
  261 МБ — 413 с понятным текстом LT/EN; 12 видов неверного ввода (битые STL, масштаб, количество, единицы, 11 файлов,
  материал, заполнение, пустые поля, email, токен) и пустая заявка — 422 без записей; самовывоз без адреса, локаль lt/en;
  клиент — владелец заказа и файлов, без гостевой ссылки, любой токен — 404 и для клиента B; профиль подставлен в форму
  (профиль, аккаунт без профиля, гость — пусто); форма шага 5 — action, CSRF, honeypot, UUID, кнопка доступна.
- `tests/Feature/OrderCartTest.php` → 7/7: `aiqme:bootstrap` — данные и отсутствие ставок, повтор через 5 минут не меняет
  ни одной строки (тест сначала поймал `updateOrCreate`, менявший `updated_at` валюты, — заменено на `firstOrCreate`);
  10 StlPart + fee — заказ 555 центов без налога; 11 StlPart, только fee, `parameters.quantity` 101, quantity 2 в корзине,
  файл чужого клиента, файл клиента в гостевой корзине, файл уже размещённого заказа — отказ; `reference` 7, 8; откат
  транзакции не расходует номер.
- `tests/Feature/OrderNumberConcurrencyTest.php` → 2/2: 4 отдельных процесса PHP одновременно по 15 номеров (каждая
  транзакция держит строку счётчика 2 мс) — 60 номеров 1–60 без повторов, последовательности процессов перемежаются.
  Мутация (SELECT + UPDATE вместо `UPDATE … RETURNING`) роняет тест; восстановлено.
- `CalculatorPageTest`, `PrototypeRoutesTest` обновлены; `tests/js/calculator-quote.test.js` — байты файла в записи модели.
- `bin/browser-check /lt/kalkuliatorius /en/quote` → **OK (537 проверок)**: в `check_calculator` (canvas отключён) пустая
  форма шага 5 → 422, ошибки «Fill in this field.» · «Užpildykite šį lauką.» под именем, email, телефоном (3, адрес при
  самовывозе не требуется); заполненная → подтверждение с номером, `data-cents` 512 = итог калькулятора, гостевая ссылка
  `/en/order/<n>/…` · `/lt/uzsakymas/<n>/…` открывает «Order No. n» с файлом куба, чужой токен — 404; ширина и маркеры D-21
  на подтверждении и гостевой странице (первый прогон поймал ширину ссылки на 390 px — добавлен перенос `overflow-wrap`).
- `bin/browser-check`: полный прогон одним запуском не уложился в 10 минут переднего плана (прерван на обходе страниц; до
  обхода в блоке входа/админки была 1 ошибка, текст не сохранился — отчёт пишется в конце). Поэтому: `--signed-in /en/faq`
  (вход, кабинет, все вкладки админки, редакторы, настройки калькулятора, сброс пароля) → **OK (2550)**; 80 адресов
  route:list + sitemap.xml (без калькулятора) тремя частями → **OK (3176, 3542, 2561)**.
- `bin/check` → **OK**: Pint; Node 36/36; PHPUnit 467 (449 passed, 18 skipped); Vite.
- `git status --porcelain aiqme-site`: изменены .env.example, StlAnalyzer, CalculatorController, AppServiceProvider,
  bin/browser-check, bin/setup, bootstrap/app.php, config/aiqme.php, lang/{en,lt}/{admin,calculator}.php, calculator JS
  (index.js, quote.js), шаблоны письма и калькулятора, routes/web.php, 3 теста; новые — Console/Commands/Bootstrap.php,
  app/Domain/Orders/, OrderRequestController, модели OrderCharge/StlFile/StlPart, миграция, 2 шаблона, 3 теста. Бинарных
  файлов, .env и ключей нет; новых пакетов нет.

### Не проверено

- Параллельная отправка с одним токеном отправки (ветка конфликта уникальности `submission_token` → тот же заказ)
  проверена только последовательно; параллельность проверена на выдаче номеров.
- Сбой канала sheets не откатывает заказ — канал не обрабатывается до W-18.
- Вид страницы подтверждения и гостевой страницы — эталона в прототипе нет; ждёт проверки пользователем.
- Ошибка полного `bin/browser-check` до обхода страниц не воспроизвелась при повторе блока входа; полного прогона одним
  запуском в эту ночь нет (обход разбит на части, 82 адреса против 91 в полном прогоне: список собран из route:list и
  sitemap.xml вручную).
- Право гостя в сессии, ограничение частоты гостевой страницы, защищённая выдача STL — W-15, W-32.

## W-32 «Защищённая выдача STL и проверка прав на заказ» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- `app/Domain/Orders/OrderAccess::allows(Order, ?User)` — единственная проверка прав на заказ (§6.1): администратор
  (`is_admin`) или владелец — вошедший пользователь при непустом `user_id` заказа, равном его id. У гостевого заказа
  (`user_id` = null) владельца нет; сравнение `user_id === auth()->id()` эксперимента не перенесено. Право гостя в сессии
  добавит W-15 в этот же сервис; W-14…W-17 вызывают его же, отказ — 404 у вызывающего.
- `app/Http/Controllers/StlDownloadController` + маршрут `GET|HEAD /stl/{stlFile}` (`stl.download`, `whereNumber`,
  `auth.session`). Права — через `OrderAccess` на каждый запрос (GET, HEAD, Range); файл без заказа, чужой, несуществующий
  или отсутствующий на диске — 404. Ответ — `BinaryFileResponse` (Range и HEAD обслуживает Symfony): `application/octet-stream`,
  `Content-Disposition: attachment` (ASCII `filename=` с экранированием кавычек + `filename*` UTF-8; `/` и `\` в имени
  заменяются на `_`, иначе заголовок не собрать), `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`.
  Подписанных ссылок нет.
- `config/filesystems.php`: `'serve' => false` у диска `local` — маршрута шаблона `storage/{path}` больше нет
  (`route:list --path=storage` пуст). Диска `private` в приложении нет (приватные файлы — диск `local`,
  `storage/app/private`), поэтому изменён только `local`.
- Решения без указаний плана (простейший вариант): адрес выдачи `/stl/<id>` без префикса локали — один маршрут для
  кабинета, гостевой страницы и админки; в адресе нет токена (§6.2), знание id доступа не даёт (§6.1). Отдельной проверки
  владельца заказа в W-12 не было (гостевая страница сверяет токен, `RequestCartValidator` — владельца файла в корзине до
  заказа), заменять было нечего. Ссылки на скачивание в шаблонах не добавлены — их выводят карточки W-14/W-15.

### Проверки

- `tests/Feature/StlDownloadTest.php` → 6/6 (114 утверждений), заказы созданы штатной отправкой `OrderSubmission`:
  заказ клиента A — владелец и администратор получают 200 (тело = файл, заголовки), HEAD 200 с `Content-Length` и пустым
  телом, `Range: bytes=0-9` → 206, `Content-Range`, 10 байт; клиент B и посетитель — 404 на GET/HEAD/Range без
  `attachment`; гостевой заказ (`user_id` = null) — посетитель и клиент B 404 на все три, администратор скачивает; сервис:
  посетитель на гостевом заказе — false; неизвестный id, нечисловой id и файл без заказа — 404 даже администратору;
  экранирование имени `detalė "1";x\y.stl`; `/storage/stl/<uuid>.stl` GET/HEAD и PUT — 404.
- Мутации: `'serve' => true` — тест `/storage` падает (403 вместо 404, отдельно от проверки конфигурации); проверка
  эксперимента `is_admin || order.user_id === user?->id` — падают тесты гостевого заказа (200 посетителю) и сервиса;
  без строки `Cache-Control` — `private, must-revalidate` вместо `no-store, private`. Всё восстановлено.
- `vendor/bin/pint --dirty` → passed; `bin/check` → **OK**: PHPUnit 473 (455 passed, 18 skipped), Vite.
- `bin/browser-check` не запускался: шаблонов и страниц задача не меняет (поиск маркеров rules[7] не требуется), новый
  маршрут без локали в обход route:list не входит.
- `git status --porcelain aiqme-site`: изменены `config/filesystems.php`, `routes/web.php`; новые `OrderAccess.php`,
  `StlDownloadController.php`, `StlDownloadTest.php`. Бинарных файлов, .env, ключей, новых пакетов нет.

### Не проверено

- HEAD/Range проверены через ядро Laravel в PHPUnit, не на nginx/PHP-FPM (W-20).
- Скачивание гостем по праву в сессии — W-15 (до неё гость получает 404 на STL своего заказа).


## W-32: заблокирована ночным прогоном (2026-10-21 07:52)

Причина (оркестратор): автор не вернул JSON-итог. Работа — в git stash «night-blocked W-32».

## W-19 «Telegram-бот контента (локальный уровень)» — 2026-10-21, Claude Code (ночной прогон)

### Сделано

- Таблицы §2.5 (миграция `2026_10_21_300000_create_telegram_tables`): `telegram_bindings` — одна строка (администратор,
  привязанные Telegram `user.id`/`chat.id`, SHA-256 кода и его срок, последняя ошибка Bot API без токена),
  `telegram_dialogs` — контекст диалога чата со сроком 30 минут, `telegram_updates` — принятые `update_id`.
- `app/Domain/Integrations/Telegram/TelegramClient` — тонкий клиент Bot API на HTTP-клиенте Laravel без пакетов (`call()`
  для sendMessage, answerCallbackQuery, getFile, setWebhook, deleteWebhook, getUpdates; `download()` файла). Любая ошибка
  сети/API → `TelegramException`, из сообщения вырезан токен (и его urlencode-вид) до записи в журнал (`Log::warning`)
  и в последнюю ошибку привязки; исходное исключение дальше не передаётся. `editMessageText` не понадобился: бот
  отвечает новыми сообщениями.
- `TelegramUpdates::receive()` — общий приём для webhook и poll: `insertOrIgnore` `update_id`, повтор — без эффекта, затем
  `TelegramBot::handle()`; ошибка обработки — `report()`, апдейт остаётся принятым.
- `TelegramBot` — один общий диалог для всех 9 типов, зарегистрированных в `ContentTypes` (кода на отдельный тип нет):
  меню типов → список записей (+ «Add a draft +», кроме page_text) → запись (состояние LT/EN, атрибуты meta, назначения
  медиа) → язык → пронумерованные поля формы `ContentTypes::toForm()` (пути `Arr::dot`: title, slug, SEO, поля, части
  записей pairs/records, у records — пустая запись для новой) → новый текст → `ContentTypes::fromForm()` →
  `ContentService::saveDraft()` с каналом `telegram` и ревизией кнопки → предпросмотр → `publish`/`unpublish` LT или EN.
  Атрибуты meta — кнопками вариантов (select/choices) или текстом (date/url), проверка `ContentTypes::metaRules()`,
  запись `ContentService::updateItem()`. Новая запись — заголовок LT, у типов со slug затем адрес LT (он же ключ, как
  в веб-редакторе). Кнопки несут id записи, у правки и публикации — ревизию перевода: устаревшая отклоняется
  («This button is outdated…») и ничего не меняет. Конфликт ревизии при вводе текста: правка не сохраняется, бот
  показывает текущую версию поля и присланный текст (§4.2). `edited_message` не обрабатывается. Удаления нет.
- Доступ: только `chat.type = private`, `from.id` и `chat.id` привязки, привязанный пользователь всё ещё администратор
  (Gate `admin`); остальным — ни одного ответа. Привязка — `/start <код>`: код с экрана Integrations (32 случайных байта
  base64url, 43 символа, 10 минут, одноразовый, в БД только SHA-256); неверный код — нейтральное «The code is invalid or
  has expired.», 5 неверных попыток с одного `user.id` за 10 минут блокируют и верный код (RateLimiter по user.id).
- Медиа: фото (наибольший размер), видео или файл-документ только после кнопки назначения записи; размер из апдейта и
  getFile ≤ `aiqme.media_max_kb` (20 МБ), больше — «The bot accepts files up to 20 MB. Upload larger files in the web
  administration.» без getFile; файл скачивается во временный файл и сохраняется `ContentService::addMedia()` — та же
  проверка `MediaFile` по сигнатуре и то же имя UUID с расширением по сигнатуре, что у W-08; имя документа Telegram не
  используется; SVG и чужая сигнатура отклоняются.
- Ответы бота — после записи; сбой Telegram при ответе не отменяет правку (ответ теряется, ошибка — в журнале и на
  экране Integrations). Язык ответов — `from.language_code` (`lt` → LT, иначе EN).
- Webhook `POST /webhooks/telegram` (`App\Http\Controllers\Webhooks\TelegramController`, `routes/webhooks.php`,
  подключён через `then:` в `bootstrap/app.php`) — вне группы web: без сессии, cookie и CSRF. Заголовок
  `X-Telegram-Bot-Api-Secret-Token` сравнивается `hash_equals` с `TELEGRAM_WEBHOOK_SECRET`; неверный или пустой секрет —
  403 без записи; тело без целого `update_id` — 400.
- Команды: `aiqme:telegram-webhook` (setWebhook: `APP_URL/webhooks/telegram`, `secret_token`, `allowed_updates`
  message/callback_query; секрет не по `^[A-Za-z0-9_-]{1,256}$` — отказ без запросов); `aiqme:telegram-poll [--once]`
  (только `APP_ENV=local`: deleteWebhook, getUpdates с offset в цикле до остановки, апдейты — `TelegramUpdates::receive`).
- Экран Integrations (W-18 не выполнена — экран создан здесь): вкладка `integrations` в меню перед «Audit log», как
  в прототипе; Livewire `App\Livewire\Admin\Integrations` — раздел Telegram: «NOT CONNECTED»/«CONNECTED», время привязки,
  «бот не настроен» (без значений секретов), последняя ошибка, кнопка кода и команда `/start <код>`. Карточки TEJA, Bambu,
  «Banking & shipping», описание прототипа о ролях/экспорте и кнопка демо-CSV не перенесены. W-18 добавит свои разделы.
- `config/services.php` — `telegram.token`/`telegram.webhook_secret` из `.env`; в `.env.example` — пустые
  `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`.
- Тексты (rules[8]). Из прототипа: вкладка «Integracijos»/«Integrations», «Integracijos ir duomenų eiga»/«Integrations &
  data flow», «NEPRIJUNGTA»/«NOT CONNECTED», «Telegram»; реплики «Pirmiausia sukurkite juodraštį.»/«Create a draft
  first.», «Peržiūrėti juodraštį»/«Preview draft», «Peržiūra: …»/«Preview: …», «Būsena: juodraštis. Publikavimui reikia
  atskiro administratoriaus patvirtinimo.»/«Status: draft. Publication requires a separate administrator confirmation.»;
  кнопки публикации, состояния, «Draft saved.», «Saved.», «File uploaded.», «Add a draft +», подписи полей и сообщения
  проверки — существующие строки `lang/*/admin.php`, `content.php`. Нейтральные (в прототипе нет): «PRIJUNGTA»/«CONNECTED»,
  «Paskutinė klaida»/«Last error», «Asmeninis pokalbis susietas: …»/«Private chat linked: …», «Boto prieigos duomenys
  nenustatyti serveryje.»/«Bot credentials are not set on the server.», «Gauti susiejimo kodą»/«Get a link code»,
  «Per :minutes min. nusiųskite botui šią komandą:»/«Send this command to the bot within :minutes min:» и все строки
  `lang/*/telegram.php`, кроме перечисленных из прототипа (меню типов и названия типов, «← Atgal»/«← Back», ответы на код,
  вопросы о заголовке, URL, поле, атрибуте и медиа, «+ :collection», отказы по медиа, устаревшая кнопка, конфликт).
  Демо-реплики чата прототипа (оплата, заявка, DEMO-1047, статус, экспорт клиентов) не перенесены (§4.5, D-21).
- Решения без указаний плана (простейший вариант): обработка апдейта синхронно в запросе webhook (без очереди); контекст
  диалога 30 минут; лимит 5 неверных кодов / 10 минут; подпись к фото не используется (медиа — только в контексте записи
  по кнопке назначения, alt задаётся в вебе); ответы новыми сообщениями; строки `telegram_updates` не очищаются
  (одна короткая строка на апдейт); списки записей — все записи типа одним сообщением без страниц (тест сидера проверяет ≤100 кнопок только у вида перевода);
  RU-перевод в боте не редактируется (только LT/EN, §4.5).
- Тесты: `AdminShellTest` — меню с «Integracijos»/«Integrations», запрет фразы `telegram` заменён на адреса вкладки
  имитатора (`admin/telegram`), т. к. на экране Integrations есть раздел Telegram; `PrototypeRoutesTest` — integrations
  проверяется (строка 13: 200 администратору, 404 гостю и клиенту) и `Livewire::flushState()` в setUp: статический флаг
  автоподключения ресурсов Livewire после рендера вкладки иначе переходил в следующие тесты процесса. `bin/browser-check`
  — integrations в ADMIN_PAGES.

### Проверки

- `tests/Feature/TelegramBotTest.php` → 17/17 (1202 утверждения), всё на `Http::fake`, токен `test-bot-token-not-real`,
  секрет `test-webhook-secret-not-real`: webhook с включённой проверкой CSRF (подмена `PreventRequestForgery`; контроль —
  POST формы контактов без токена → 419) — неверный и пустой секрет 403, не 419, без `Set-Cookie` и без записи; маршрут
  без middleware `web`; код привязки с экрана (только SHA-256 в БД, 43 символа), неверный код не привязывает и отвечает
  одной нейтральной фразой без кнопок, 5 неверных попыток блокируют верный код для этого user.id, другой user.id
  привязывается, повтор кода не работает, просроченный код и код бывшего администратора не привязывают; чужой user.id,
  чужой chat.id, группа — ни одного запроса к Telegram и без изменений; заказы/клиенты/заявки — на любые команды и текст
  только меню типов контента, e-mail и имена клиентов не уходят ни в один запрос; правка из бота — черновик (публичная
  страница без изменений, веб-редактор показывает текст), публикация EN из бота видна на /en, LT не меняется, журнал —
  канал telegram, снятие с публикации → 404; правка в вебе видна в боте, устаревшие кнопки публикации и правки ничего не
  меняют (и не трогают другую запись); конфликт ревизии — обе версии в ответе, сохранена веб-версия; повтор `update_id`
  и `edited_message` — без эффекта; истёкший контекст; все 9 типов: число кнопок полей = числу полей формы ContentTypes,
  ≤100 кнопок, callback_data ≤64 байт, текст ≤4000; новый проект (неверный slug отклонён), атрибут кнопкой, публикация
  только LT → 200 /lt, 404 /en; медиа: без контекста — отказ, SVG-документ и чужая сигнатура отклонены, PNG с именем
  `evil.php.jpg` сохранён как `<uuid>.png`; видео 21 МБ — понятный отказ без getFile; сетевая ошибка (`failedConnection`,
  сообщение с адресом и токеном) — правка сохранена, в журнале и последней ошибке токена нет (`/bot[token]/sendMessage`),
  экран Integrations показывает ошибку; `aiqme:telegram-webhook` — адрес, тело setWebhook, отказ на 4 неверных секретах
  без запросов, 256 символов — успех, ответ 401 — вывод без токена; `aiqme:telegram-poll --once` — отказ вне local без
  запросов, в local: deleteWebhook → getUpdates (offset 0, allowed_updates) → ответ только на новый апдейт (повтор уже
  принятого update_id без эффекта).
- Мутации (восстановлены): без вырезания токена, без дедупликации, без лимита попыток, без сверки chat.id, без проверки
  ревизии кнопки правки, приём при пустом секрете — тесты падают. Удаление проверки `chat.type = private` тесты не
  ловят: групповой chat.id всегда отличается от привязанного личного, отказ даёт сверка chat.id (проверка оставлена как
  явное правило §4.5).
- `vendor/bin/pint --dirty` → passed; `bin/check` → **OK**: PHPUnit 484 (468 passed, 16 skipped), Vite.
- `bin/browser-check --signed-in /lt /en` → **OK (3472 проверки)**, включая `/lt/administravimas/integrations` и
  `/en/admin/integrations` (ширина, §7.1, маркеры D-21, консоль) на 390 и 1440.
- `git status --porcelain aiqme-site`: изменены `.env.example`, `AdminController.php`, `bin/browser-check`,
  `bootstrap/app.php`, `config/services.php`, `lang/{lt,en}/admin.php`, `AdminShellTest.php`, `PrototypeRoutesTest.php`;
  новые — команды Telegram*, `app/Domain/Integrations/Telegram/`, `app/Http/Controllers/Webhooks/`,
  `app/Livewire/Admin/Integrations.php`, миграция, `lang/{lt,en}/telegram.php`, два шаблона admin, `routes/webhooks.php`,
  `TelegramBotTest.php`. Бинарных файлов, .env, ключей и новых пакетов нет.

### Не проверено

- Реальный Bot API (уровень T, W-22): форматы апдейтов — по документации Telegram, фикстуры записаны вручную, не сняты
  с настоящего бота; `aiqme:telegram-poll` в бесконечном цикле и Ctrl+C не запускались (только `--once`).
- Скачивание файла до 20 МБ по сети и время обработки синхронного webhook с таким файлом — не измерялись.
- Вид экрана Integrations и раздел Telegram — кадры `build/verification/browser-check/shots/*integrations*` ждут
  проверки пользователем; удобство диалога в настоящем клиенте Telegram — ждёт проверки пользователем (W-22).
- Сценарий 5 §9.3 (W-30) не собирался.

## W-32: продолжение после блокировки — выполнено (2026-10-21, Claude Code, ночной прогон)

- Работа прошлой сессии восстановлена в дереве (`OrderAccess`, `StlDownloadController`, маршрут `stl.download`,
  `'serve' => false` у диска `local`, `StlDownloadTest`); сверена с done[0–3] — недостающего нет, код не менялся.
  Восстановленный `progress.md` был старше HEAD (терял записи «W-32 заблокирована» и W-19) — возвращён из HEAD, эта
  запись добавлена в конец.
- Проверки: `tests/Feature/StlDownloadTest.php` → 6/6 (114 утверждений); `route:list --path=storage` — маршрутов нет;
  `pint --dirty` → passed; `bin/check` → **OK** (PHPUnit 490: 474 passed, 16 skipped; Vite).
- `git status --porcelain aiqme-site`: те же пять файлов задачи и `docs/progress.md`; бинарных файлов, .env, ключей нет.
- Не проверено: как в записи выше (HEAD/Range на nginx — W-20; право гостя в сессии — W-15).

## W-13: Правила заказа: цена, производство, отмена — выполнено (2026-10-21, Claude Code, ночной прогон)

### Сделано

- Миграция `2026_10_21_400000_create_order_workflow_tables.php`: `production_states` (стадия, `confirmed_at`, `started_at`,
  ревизия, перевозчик, трек, причина отмены), `price_revisions` (было/стало по id строк, основание, согласование, автор;
  уникально `(order_id, revision)`), `payment_requests` (все поля §2.5: `public_id`, сумма, валюта, `state`,
  `needs_review`, `review_reason`, `stripe_intent_id`, `idempotency_key`, `intent_first_attempt_at`; зарядов нет),
  `order_messages`. Модели `ProductionState`, `PriceRevision`, `OrderMessage` (Domain/Orders/Models), `PaymentRequest`
  (Domain/Payments/Models), перечисление `ProductionStage`.
- `App\Domain\Orders\OrderWorkflow`: `confirmPrice` (review → confirmed, ревизия 1; цена каждой строки, StlPart > 0,
  fee ≥ 0; строка delivery создаётся как `OrderLine` с purchasable `OrderCharge` delivery в той же транзакции),
  `changePrice` (до начала производства, основание и согласование обязательны, нет открытых запросов creating/pending/
  cancelling, цена ≥ оплачено, остаток 0 или ≥ 50 центов; строка delivery меняет цену, удалить её нельзя),
  `requestPayment` (pending без intent, ≥ 50 центов, ≤ долг − открытые запросы, остаток 0 или ≥ 50), `cancelPaymentRequest`
  (только pending без intent → void), `startProduction` (без оплаты можно; при долге — флаг `debtConfirmed`, §3.4
  «система спрашивает подтверждение»), `advance` (строго на одну стадию вперёд; shipped — при доставке и с треком;
  самовывоз ready → completed), `setTracking`, `cancel` (с причиной; pending без intent → void в той же транзакции,
  запросы с intent и creating не трогаются — W-16; возврата нет), `postAdminMessage`, `recordCustomerReply` (только
  владелец по `OrderAccess::owns`, иначе `AuthorizationException` без записи; ответ ничего не меняет), `balance`.
  Права — в сервисе (Gate `admin`). Каждое изменение — под блокировкой строки заказа и состояния, событие
  `integration_events` (`order.price_confirmed`, `order.price_changed`, `payment_request.created`,
  `payment_request.cancelled`, `order.stage_changed`, `order.tracking_set`, `order.cancelled`) и журнал в той же транзакции.
- `IntegrationEvents::record(..., mail: false)` — события §5.1 без письма: канал `mail = none`, задание письма не ставится.
- `OrderSubmission` (W-12) создаёт `production_states` в стадии review вместе с заказом; `OrderAccess::owns()` выделен
  из `allows()`; `OrderCharge::DELIVERY`.
- Метода записи оплаты нет; «оплачено» в тестах — `tests/Support/LunarCapture` (транзакция Lunar capture).
- Тексты: в прототипе сообщений правил нет — нейтральные фразы в `lang/{lt,en}/orders.php` (`orders.workflow.*`:
  cancelled, already_confirmed, not_confirmed, price_locked, reason_required, open_request, every_line, line_price,
  delivery_required, below_paid, rest_too_small, request_too_small, request_too_large, request_rest_too_small,
  request_not_cancellable, stage_invalid, debt_unconfirmed, tracking_required, tracking_pickup, not_cancellable,
  message_invalid, reply_invalid). Шаблонов задача не добавляет — поиск маркеров `bin/browser-check` не требуется.

### Решения по неопределённому (простейший вариант)

- Переходы «только вперёд» — строго на следующую стадию, без пропусков; review → confirmed только через `confirmPrice`,
  confirmed → in_production только через `startProduction`; для заказа с доставкой ready → completed запрещён (через shipped).
- Первое подтверждение цены основания не требует (§3.2 требует его для изменения); `price_revisions.reason/agreement`
  у ревизии 1 пусты. Согласование — одна строка (текст или ссылка на ответ клиента).
- Строку delivery можно подтвердить с ценой 0; доставка добавляется независимо от выбранного способа получения
  (проверки «только при ship» нет). Цены строк fee/delivery пишутся и в `order_charges.amount`; `stl_parts.estimate`
  (серверная оценка) не меняется.
- Трек можно ввести или исправить до completed (не для самовывоза); ответ клиента без текста (три кнопки §1.3);
  сообщение администратора — до 5000 символов. Lunar `orders.status` не меняется: стадия — в `production_states`.
- Частичный уникальный индекс `lunar_transactions (reference) WHERE type = 'capture'` не создан — его потребует запись
  capture (W-16/W-28).

### Проверки

- `tests/Feature/OrderWorkflowTest.php` (§9.2 «Цена и производство», запросы без intent) → 29/29 (272 утверждения):
  подтверждение всех строк с fee 0 и одной delivery, «каждой строке — цена», повторное подтверждение отклонено; ревизия
  заказа с доставкой меняет ту же строку (не добавляет вторую), удалить delivery нельзя, добавить при ревизии можно;
  основание и согласование обязательны; ревизия после аванса сохраняет историю и «оплачено»; ниже оплаченного — отказ;
  остаток 1, 30, 49 центов — отказ, 50 и 0 — успех; открытые creating/pending/cancelling блокируют изменение, void/paid —
  нет; после старта цена заблокирована; производство без подтверждённой цены — отказ, без оплаты — только с
  подтверждением долга, оплата стадию не меняет; переходы вперёд по одной, назад и через стадию — отказ, shipped без
  трека — отказ, самовывоз ready → completed; запросы: только после подтверждения и не для отменённого, 49 — отказ,
  оставляющий 30 центов — отказ, резерв открытых (включая creating/cancelling) и оплаченного; отмена запроса без intent →
  void, с intent — отказ; отмена заказа: pending без intent → void, с intent и creating без изменений, возврата нет,
  дальнейшие действия отклонены, completed не отменяется; 9 методов администратора от клиента — `AuthorizationException`
  без изменений; команд `aiqme:*` с order/payment нет; события и журнал (типы, `mail_status = none`, номер, сумма) и
  откат изменения при сбое записи события (уровень транзакции события = уровень изменения); сообщения и три ответа,
  «прошу отменить» не отменяет заказ и запрос; ответ от другого клиента, посетителя, администратора и на гостевой заказ —
  отказ без записи.
- Мутации (восстановлены): открытые = только pending; без проверки остатка запроса; отмена заказа без void; ответ с
  `allows` вместо `owns` (пускает администратора); без проверки остатка ревизии; shipped без трека — тесты падают.
- `pint --dirty` → fixed (форматирование), `bin/check` → **OK**: PHPUnit 519 (503 passed, 16 skipped), JS 36/36, Vite.
- `git status --porcelain aiqme-site`: изменены `IntegrationEvents.php`, `OrderAccess.php`, `OrderSubmission.php`,
  `OrderCharge.php`, `docs/progress.md`; новые — модели, `OrderWorkflow.php`, `ProductionStage.php`, `Domain/Payments/`,
  миграция, `lang/{lt,en}/orders.php`, `OrderWorkflowTest.php`, `tests/Support/LunarCapture.php`. Бинарных файлов,
  .env, ключей и новых пакетов нет.

### Не проверено

- Параллельные вызовы (две вкладки администратора) — блокировки строк есть, отдельного теста конкурентности нет.
- Действия в интерфейсе — W-14 (админка), W-15 (кабинет, гостевая ветка ответа); отмена запросов с intent и в creating
  при отмене заказа — W-16; блокировка цены после выпуска счёта — W-17; проекция событий в листы — W-18.

## W-14: Админка: обзор, заказы, клиенты — выполнено (2026-10-21, Claude Code, ночной прогон)

### Сделано

- Вкладки `orders` и `customers` в меню в порядке прототипа (Overview, Orders, Products, Services, Calculator, Customers, …);
  маршруты `/lt/administravimas/orders|customers` · `/en/admin/orders|customers` — 404 всем, кроме администратора.
- `App\Livewire\Admin\Overview` (на Overview над заявками W-09): метрики «Awaiting technical review» (стадия review),
  «Awaiting payment» (заказы с открытым платёжным запросом), «In production» (in_production и quality_check), новые
  обращения и регистрации на обучение за 7 дней; последние 10 заказов; предупреждение «Failed background tasks: N» —
  строки `failed_jobs` плюс события outbox с `mail_status` или `sheets_status` = failed. `/admin?order=<id>` (ссылка из
  письма W-12) переадресует в карточку заказа.
- `App\Livewire\Admin\Orders\Index`: поиск по началу номера, фильтр по стадии, последние 100 заказов; таблица прототипа
  (Order/Type/Status/Total, «Manage»), общая с Overview (`admin/partials/order-rows`).
- `App\Livewire\Admin\Orders\Card` (`?order=<id>`, под списком, как `#admin-order-detail`): файлы со ссылками скачивания
  только через `route('stl.download')` (W-32), параметры и пожелания, серверные размеры, объём, масса, оценка и
  предупреждения; контакты, способ получения и адрес, ссылка на карточку клиента; цены строк в EUR и строка доставки:
  подтверждение (review) и изменение с основанием и согласованием (confirmed); оплата (итог, оплачено, долг, состояние),
  запросы и отмена pending без intent, новый запрос; начало производства и «Next stage»; перевозчик и трек; сообщение
  клиенту и ответы клиента («Customer response» + подписи трёх кнопок прототипа); перевыпуск гостевой ссылки (только у
  гостевого заказа); отмена с причиной; история (ревизии цены и журнал заказа). Все изменения вызывают методы
  `OrderWorkflow`; отказы правил выводятся в карточке (`@error('workflow')`). Опасные действия (отмена, изменение цены,
  начало производства при долге > 0) — первое нажатие показывает `#order-confirm` с «Confirm»/«Back», действие —
  только после «Confirm»; при долге `startProduction` получает `debtConfirmed: true` только после подтверждения.
- Лист задания Bambu («Bambu Studio staff handoff» → «Staff job handoff»): номер, файлы (ссылки защищённой выдачи),
  количество, материал, заполнение, пожелания, единицы, масштаб, текст прототипа без «Demo job sheet…». Кнопок передачи на
  принтер и JSON-выгрузки нет.
- `OrderWorkflow::reissueGuestLink` (новый метод сервиса W-13): новый токен, в БД — только SHA-256, журнал
  `order.guest_link_reissued` без токена; у заказа клиента — отказ `not_guest`. Карточка показывает новую ссылку один раз
  с предупреждением; следующий запрос компонента её сбрасывает. Ссылку страницы оплаты для клиента добавит W-16.
- `App\Livewire\Admin\Customers`: зарегистрированные клиенты (без администраторов) с числом заказов; карточка
  (`?customer=<id>`): контакты, «Addresses & company details» из `customer_profiles`, заказы; «Guest contacts» — контакты
  гостевых заявок со ссылкой на заказ. Без CSV и массовых операций (ссылку на Google Таблицу добавит W-18).
- Подписи событий заказа в журнале (`admin.audit.events.order.*`).
- `tests/browser/OrderFactory.php`: гостевая заявка через OrderSubmission (два куба), цена 60 + 55 + 5 + доставка 30 EUR
  подтверждена, запрос на 50 EUR, трек DPD — через OrderWorkflow; вызывается из `BrowserDataSeeder`.
- `bin/browser-check`: `orders`, `customers` в ADMIN_PAGES; `check_admin_order` на 390 (LT) и 1440 (EN).

### Тексты

- Из прототипа (app.js, dom.html admin orders/overview): «Orders & production review», «Search by order number»,
  «Order search», колонки, «Manufacturing», «Manage», стадии (Technical review, Approved for production, In production,
  Quality inspection, Ready to ship, Shipped, Completed), «Files & production settings», notice проверки файла,
  «Next stage», «Bambu Studio staff handoff», «Staff job handoff», текст листа задания, «Customer response»,
  «Approve the proposed revision», «Request clarification», «Request cancellation», «Customer explanation & proposed
  solution», «Awaiting technical review», «Orders», «All orders ↗», «Delivery», «Total», «Paid», вкладки «Orders»,
  «Customers» (и пары LT).
- Нейтральные (в прототипе нет): «Cancelled»/«Atšaukta» (стадия), «Awaiting payment», «New enquiries (7 days)»,
  «Training registrations (7 days)», «Failed background tasks: …», «All», «No orders found.», File, Parameters,
  «Size and volume», Estimate, «Download STL», Quantity, Units, «Scale, %», Customer/Guest, Contacts, «Delivery method»,
  Address, «Customer card», Price, подсказка о пустой доставке, «Confirm price», «Change price», «Reason for the change»,
  «Customer agreement (e.g. …)», Payment, Outstanding, состояния оплаты и запросов, «Payment requests», «No payment
  requests.», «Amount, €», «Request payment», «Cancel request», Production, Carrier, «Tracking number», «Save tracking»,
  Messages, «No messages yet.», «Send to the customer», «Guest link» и подсказка, «Issue a new guest link», «New guest link —
  shown only once…», «Cancel order», «Cancellation reason», «Cancelled: …», тексты подтверждений, Confirm/Back, History,
  «Price revision N», сообщения об успехе, «Enter an amount in euros…», заголовок «Customers» (без «& export»), «Guest
  contacts» и подписи списка клиентов, `orders.workflow.not_guest`, подписи событий журнала заказа; LT — переводы этих фраз.

### Решения по неопределённому (простейший вариант)

- «Ждут оплаты» — заказы с открытым (creating/pending/cancelling) запросом; «в производстве» — in_production и quality_check;
  «новые» обращения и регистрации — за 7 дней (статуса «обработано» нет).
- «Неудачные фоновые задания» — `failed_jobs` + каналы outbox в failed.
- Подтверждение опасных действий — второе нажатие в карточке (проверяется сервером), а не `confirm()` браузера.
- Ссылка гостю — на языке заявки (`order_meta.locale`). Колонка «Type» всегда «Manufacturing» (покупки каталога нет, D-12).
- Тест `AuthTest` Overview: Livewire-компонент добавляет к `Cache-Control` «no-cache, must-revalidate, max-age=0»; проверка
  изменена на наличие `no-store` и `private` (как в AdminShellTest).

### Проверки

- `tests/Feature/AdminOrdersTest.php` → 17/17 (362 утверждения): вкладки только администратору (гость и клиент 404,
  компоненты для клиента 404), тексты прототипа без демо-фраз; метрики и порядок последних заказов, предупреждение (2 =
  событие с ошибкой письма + failed_jobs), переадресация `?order=`; поиск и фильтр; подтверждение цены с доставкой (ревизия
  1, итог 150 EUR, событие); отказы правил OrderWorkflow видны без изменений (цена 0, запрос больше долга, цена ниже
  оплаченного); изменение цены — подтверждение, «Back», затем ревизия 2 с основанием; запрос и отмена без intent, запрос с
  intent не отменяется, чужой запрос — 404; начало производства при долге — подтверждение, без долга — сразу; стадии, shipped
  без трека — отказ, трек, самовывоз ready → completed; отмена — подтверждение, причина обязательна, открытый запрос → void;
  сообщение и три ответа клиента в карточке (EN и LT); ссылки STL — только `/stl/<id>`, скачивание администратором —
  attachment, гостю 404; лист Bambu — параметры, пожелания, ссылки, без пути storage, без форм и кнопок передачи;
  перевыпуск: старая ссылка 404, новая 200, токена нет в журнале, после следующего запроса ссылка не показывается; у заказа
  клиента перевыпуска нет; клиенты, число заказов, карточка с профилем, гостевые контакты, администратор — 404.
- Мутации (восстановлены): отмена без подтверждения, ссылка не сбрасывается, старт без подтверждения долга, ссылки на
  storage, изменение цены без подтверждения — тесты падают.
- `PrototypeRoutesTest`: строки orders/customers больше не пропускаются (200 администратору, 404 гостю и клиенту).
- `bin/check` → **OK**: PHPUnit 536 (524 passed, 12 skipped), JS 36/36, Vite.
- `bin/browser-check --signed-in /en/contact` → **OK (3150 проверок)**: все вкладки админки, включая orders и customers,
  на 320–1440; `check_admin_order` 390/1440: карточка фабричного заказа без расползания, маркеров и ошибок консоли/CSP,
  стадия, открытый запрос, трек, ссылки листа задания и скачивание, подтверждение отмены и «Back», на 1440 — новая гостевая
  ссылка открывается в чистом браузере и после перезагрузки не показывается. Первый прогон нашёл маркер «example» в
  подписи согласования — заменено на «e.g.».
- `git status --porcelain aiqme-site`: изменённые и новые файлы только в app/, lang/, resources/views/admin/, tests/, bin/
  и docs/progress.md; бинарных файлов, .env, ключей и новых пакетов нет.

### Не проверено

- Визуальная сверка карточки с прототипом — ждёт проверки пользователем (кадры: `build/verification/browser-check/shots/
  admin-order-card-390.png`, `-1440.png`).
- Параллельная работа двух вкладок администратора с одним заказом — отдельного теста нет (блокировки — в OrderWorkflow).
- Полный `bin/browser-check` без путей (все публичные страницы) не запускался: изменены только страницы админки.

## W-15: Кабинет клиента и гостевая страница заказа — выполнено (2026-10-21, Claude Code, ночной прогон)

### Сделано

- Вкладки кабинета прототипа (`account()` app.js): `/lt/paskyra/…` · `/en/account/…` — корень и `overview`, `orders`,
  `orders/<номер>` (карточка), `models`, `addresses` (W-07), `invoices`; меню в порядке прототипа, иконки прототипа, выход.
  Один шаблон `account/index` + `account/tabs/*` (прежний `account/addresses.blade.php` стал `tabs/addresses`). Гостю на
  любой вкладке и на карточке — форма входа (200, §4.6); `auth.blade.php` передаёт параметры адреса (номер карточки) в
  canonical и переключатель языка.
- Overview: «Welcome, :name.», реальные счётчики заказов и моделей, последний заказ. Orders: номер, дата, «Manufacturing»,
  стадия и состояние оплаты, сумма, «View». Saved models: STL заказов клиента (без скрытых) — «Download STL» (`/stl/<id>`),
  «Recalculate» (`/en/quote?models=<id>`), «Remove from list» (POST с CSRF → `stl_files.hidden_at`, файл и строка заказа
  остаются; чужая или уже скрытая — 404). Invoices — пустое состояние (список — W-17).
- Карточка заказа (`account/order`, разметка `orderDetail()`): «Order progress», номер и стадия, timeline стадий (без
  «Shipped» у самовывоза; у отменённого — причина вместо timeline); сообщения администратора — «Your decision is needed», текст,
  ответы клиента с датой и три кнопки (у последнего сообщения) POST `/{lt/uzsakymas|en/order}/<номер>/reply`; «Order contents»
  со ссылками защищённой выдачи; «Payment & delivery»: до подтверждения — «Estimated total», после — Total/Paid/Outstanding,
  состояние оплаты, открытые платёжные запросы (состояние и сумма, без ссылки — кнопку добавит W-16), поступившие платежи и
  возвраты из успешных транзакций Lunar (дата, вид, сумма — подтверждение аванса D-04), способ получения и адрес, трек или
  «Tracking information appears after shipment preparation.»; владельцу — «Reorder with current pricing» (калькулятор с
  `?models=<id файлов заказа>`). Карточка в кабинете — только владельцу (`OrderAccess::owns`), иначе 404.
- Гостевая страница перенесена из `Public\OrderRequestController` в `Account\OrderController::guest` (та же карточка без
  повторного заказа); шаблон-заготовка `public/order-guest` удалён. Верный токен пишет в сессию право «заказ + SHA-256 токена»
  (`OrderAccess::grantGuest`); `OrderAccess::allows` и новый `isCustomer` (владелец или гость с правом) сверяют хеш с текущим
  хешем заказа — после перевыпуска ссылки право не действует; у заказа клиента права гостя нет. По этому праву гость скачивает
  STL (`/stl/<id>`, токена в адресе нет) и отвечает администратору. `OrderWorkflow::recordCustomerReply` проверяет
  `isCustomer` (гость — `user_id` = null). Гостевая страница — `throttle:guest-orders` (30 запросов в минуту с IP,
  `aiqme.orders.guest_per_minute`), превышение — 429.
- Калькулятор: `resources/js/calculator/saved-models.js` — id из `?models=` (числа, без повторов, ≤ max_files), скачивание
  `/stl/<id>` в ArrayBuffer, имя из Content-Disposition, `Quote.addBuffer` (тот же разбор ядра); ответ не 200 и сбой сети —
  пропуск без сообщения. Единицы/масштаб/количество — по умолчанию (mm, 100 %, 1), оценка — по текущим настройкам; отправка —
  обычный multipart (новые `stl_files`).
- Миграция `2026_10_21_500000_add_hidden_at_to_stl_files`.
- Вне touches (минимально): `Public/OrderRequestController` (удалён метод `guest`), `config/aiqme.php` и
  `AppServiceProvider` (лимит гостевой страницы), `app/Models/StlFile.php` (hidden_at), `bin/browser-check`, тесты W-07/W-02
  (корень кабинета теперь Overview).

### Тексты (rules[8])

Из app.js (`account()`, `orderDetail()`, `orderList()`): вкладки «Apžvalga/Overview», «Užsakymai/Orders», «Išsaugoti
modeliai/Saved models», «Sąskaitos/Invoices»; «Your orders and next steps, at a glance.», «Latest order», «Order history»,
«View», «No saved models yet», «Upload your first model», «Recalculate», «Order progress», «Current status», «Pending»,
«Payment & delivery», «Tracking information appears after shipment preparation.», «Your decision is needed», «Your choice has
been recorded…», подписи трёх ответов (как в W-14), «Order contents» (W-12), колонки и стадии (W-14). С удалённым словом
«Demo» (D-21): «Welcome, :name.» (было «Welcome, Demo customer.»), метрики «Orders»/«Užsakymai» и «Saved models» (было
«Demo orders», «Models in this session»), «Done»/«Atlikta» (было «Demo completed»/«Demo atlikta»), «Tracking»/«Sekimas»
(было «Demo tracking»), «Reorder with current pricing»/«Pakartoti su aktualia kaina». Не перенесены: метрика скидки
(персональная скидка не реализуется), «DEMONSTRATION ORDER» (вместо него eyebrow калькулятора «AIQME / MANUFACTURING ORDER»),
notice имитации оплаты, «View demo invoice», «View the staff workflow», «Prepaid collection», пояснения прототипа о
метаданных сессии (Saved models) и о макетах превью (Invoices). Нейтральные (в прототипе нет): «No orders yet.»/«Užsakymų dar
nėra.», «Download STL»/«Atsisiųsti STL», «Remove from list»/«Pašalinti iš sąrašo», «The model was removed from the list. The
order file is kept.»/«Modelis pašalintas iš sąrašo. Užsakymo failas išsaugotas.», «No invoices yet.»/«Sąskaitų dar nėra.»,
«Payments»/«Mokėjimai», «Payment»/«Mokėjimas», «Refund»/«Grąžinimas»; суммы и состояния оплаты — строки W-14.

### Решения по неопределённому (простейший вариант) — ждут проверки пользователем

- Повторный заказ загружает только файлы: единицы, масштаб и количество прежнего заказа не переносятся (адрес `?models=`
  содержит только id). Повторный заказ создаёт новые строки `stl_files`, поэтому в Saved models появляется ещё одна копия той
  же модели (без объединения по sha256).
- Кнопки ответа — у последнего сообщения администратора и остаются после ответа (можно ответить ещё раз; все ответы видны
  администратору). Ответ на отменённый заказ не запрещён (W-13 не запрещает).
- Лимит гостевой страницы — все запросы (не только неверные) 30/мин с IP; лимит ответа отдельно не ограничен.
- Карточка заказа в кабинете — только владельцу; администратор смотрит заказы в админке (404 в кабинете).
- Номер заказа клиента в кабинете — `/orders/<номер>` (как DEMO-1047 прототипа, `whereNumber`); `/orders/DEMO-1047` — 404.

### Проверки

- `tests/Feature/CustomerAccountTest.php` → 9/9 (210 утверждений): вкладки только со своими заказами и моделями (клиент A,
  клиент B, гостевой заказ), счётчики, последний заказ, строки истории (тип, стадия, оплата, дата), ссылки моделей, пустые
  состояния, Invoices; карточка — владельцу, клиенту B, администратору и на гостевой заказ — 404, гостю — вход; карточка до и
  после подтверждения: стадия, 7 шагов timeline, итог/оплачено/долг, открытый запрос 40 EUR без ссылки и без адресов оплаты,
  платёж и возврат (дата, сумма; неуспешная транзакция скрыта), трек, ссылки STL, ссылка повторного заказа с id файлов;
  гостевая страница: посетитель, клиент B и гость с правом на другой заказ — 404 на адреса без токена/с чужим токеном, на STL
  (GET, HEAD, Range) и на ответ, записей нет; верный токен — no-store, noindex, токена нет в main, STL по праву в сессии
  (200/HEAD/206), после перевыпуска ссылки — 404, по новой ссылке — снова 200; лимит 3/мин — 4-й запрос 429, верный токен с
  того же IP 429, другой IP 200, через минуту 200; сообщение и три кнопки, ответы клиента B, администратора, посетителя — 404,
  неизвестный вид — ошибка, без записей; ответ владельца записан (заказ не отменён), гостя — с `user_id` null, гость на чужой
  заказ — 404; скрытие модели: чужая — 404, посетитель — на вход, своя — скрыта, файл на диске, в карточке и скачивается,
  повтор — 404; калькулятор с чужим id не раскрывает имя, `/stl/<чужой>` — 404.
- `tests/js/calculator-saved-models.test.js` → 3/3: разбор `?models=`, имя из Content-Disposition, 404 и сбой сети
  пропускаются, модели проходят разбор ядра в Quote.
- Мутации (восстановлены): право без сверки хеша, без записи права, скрытие чужой модели, ответ без проверки прав, без
  лимита, чужие заказы в списке, карточка через `allows` — тесты падают. Не ловится: удаление условия «у заказа клиента права
  гостя нет» (право на такой заказ получить нельзя — у него нет гостевого токена; условие оставлено как защита).
- Обновлены: `PrototypeRoutesTest` (строки §4.6 вкладок overview/orders/models/invoices больше не пропускаются — гостю
  форма входа, клиенту своя вкладка), `AuthTest`, `AccountProfileTest` (корень кабинета — Overview).
- `bin/check` → **OK**: Pint passed; Node 39/39; PHPUnit 545 (541 passed, 4 skipped); Vite.
- `bin/browser-check --signed-in /en/quote` → **OK (3997 проверок)**: 12 адресов кабинета на 320–1440 (ширина, §7.1, маркеры
  D-21, консоль); новая `check_customer_orders` на фабричных заказах (`OrderFactory::clientOrderWithMessage` — куб 10 мм,
  цена подтверждена, аванс 10 EUR, сообщение; гостевой заказ W-14 со ссылкой из сидера): карточка клиента на 390 (LT) и
  1440 (EN) — стадия, оплачено, платёж, три кнопки, ответ «Request clarification» записан; «Perskaičiuoti» из Saved models
  (390, LT) и «Reorder» из карточки (1440, EN) — модель загружена в калькулятор, PLA, 100 %, qty 1 → итог «5,12 €» · «€5.12»,
  отправка — подтверждение 512 центов, счётчики заказов и моделей +1; гостевая страница 390/1440 — карточка без ссылок оплаты
  и повторного заказа, скачивание STL по праву в сессии, чистый браузер — 404. `check_admin_order` открывает теперь самый
  старый (гостевой) заказ списка. Первый прогон один раз упал на `ui_text @1440: текст файла не вернулся` (проверка W-26,
  которую задача не меняла; вероятно, ожидание «Saved.» срабатывает по сообщению первого сохранения) — повтор прошёл.
- `bin/browser-check /lt/kalkuliatorius` → **OK (276)**: гостевая страница после отправки — h1 «Užsakymo eiga», h2 «Užsakymas
  Nr. N».
- `git status --porcelain aiqme-site`: изменения и новые файлы только в app/, bin/, config/, database/migrations/, lang/,
  resources/, routes/, tests/ и docs/progress.md; бинарных файлов, .env, ключей и новых пакетов нет; experiments/ не изменён.

### Не проверено

- Визуальная сверка с прототипом — ждёт проверки пользователем (кадры `build/verification/browser-check/shots/
  account-order-card-*.png`, `guest-order-*.png`, `*paskyra*-signed-in-*.png`, `*account*-signed-in-*.png`). Эталонных
  кадров вкладок после входа в evidence нет (снимки — форма входа); карточка сверена с DOM `en__account__orders__DEMO-1047`.
- Полный `bin/browser-check` без путей (все публичные страницы) не запускался: публичные шаблоны, кроме гостевой страницы и
  калькулятора (только JS загрузки), не менялись.
- Кнопка оплаты открытых запросов и тест «не выводится при APP_ENV=demo» — W-16; список счетов — W-17.

### Продолжение после остановки сторожем сети (2026-10-21, Claude Code)

- Работу из прерванной сессии сверил с done[0–8] задачи: всё сделано, кода не менял.
- Перепроверка: `bin/check` → **OK** (Pint; Node 39/39; PHPUnit 545: 541 passed, 4 skipped; Vite);
  `bin/browser-check --signed-in /en/quote` → **OK (3997 проверок)**, в том числе сценарий кабинета, карточки, гостевой
  страницы, «пересчитать» и повторного заказа (итог 5,12 · 5.12); `bin/browser-check /lt/kalkuliatorius` → **OK (276)**;
  `git status --porcelain aiqme-site`: новые файлы только в app/, database/migrations/, resources/, tests/.
- «Не проверено» — как выше (визуальная сверка ждёт проверки пользователем).
