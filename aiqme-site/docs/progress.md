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
