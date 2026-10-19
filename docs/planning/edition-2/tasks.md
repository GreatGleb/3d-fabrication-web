# Задачи для workflow реализации

[← План](README.md). Источник истины — [tasks.json](tasks.json); этот файл сгенерирован из него (`python3 docs/planning/workflow/render_tasks.py`). Задачи последовательные, каждая рассчитана на одну рабочую сессию агента (D-17). Workflow реализации **не запущен**.

## Общие правила

- Задачи выполняются по порядку массива; следующая — только после выполнения критериев завершения предыдущей или явной отметки о блокировке в progress.md. Задачи, транзитивно зависящие (depends) от заблокированной, пропускаются с отметкой «пропущено: зависит от заблокированной W-xx»; независимые продолжаются.
- Без человека (в том числе ночью) выполняются только задачи с track = L; на задачах T и P (W-22, W-23) прогон останавливается.
- Каждая задача — одна рабочая сессия агента (Codex или Claude Code, D-17). Час без заметного прогресса — остановиться, описать препятствие и предложить более простой способ.
- Запуск — run/night-run.sh (D-39, вариант «г», выбран пользователем 19.10, как ночь Flexiron): учётная запись и вход Claude Code пользователя, этот checkout на ветке auto/night-*, push на время ночи отключён, push делает только пользователь. Оркестратор run/night.py запускает на каждую задачу одну сессию claude --print --permission-mode bypassPermissions с запретами (git push/commit/checkout/stash/reset, gh, правка legacy-landing/, experiments/, плана и AGENTS.md, чтение legacy-landing/instance/, WebFetch, WebSearch) и после проверки коммитит задачу сам (D-18): агент не делает commit, push, stash и не меняет ветку. Первый шаг W-01 — bash docs/planning/edition-2/run/preflight.sh (W-01 done[0]). Ограничения D-20 и этих правил действуют в любом режиме.
- Приложение — только в aiqme-site/; aiqme-site/experiments/ — эталонный эксперимент, не изменять (Pint, Vite/Tailwind и PHPUnit приложения его не обрабатывают). Не изменять legacy-landing/ (instance/ не читать), baseline, docs/customer/client, корневой AGENTS.md. Chrome пользователя, CDP, google-chrome и скрипты docs/customer-prototype/tools/ (в том числе capture_prototype_chrome.py) не используются, даже если туда направляют корневой AGENTS.md, docs/requirements.md или docs/customer-prototype/ (D-30): прототип — только сохранённые docs/customer-prototype/evidence/ и тексты source-backup (правило о текстах ниже); браузерные проверки — bundled Chromium Playwright.
- На уровне L: не публиковать, не делать push, не арендовать сервер, не вызывать внешние сервисы. Сеть — только загрузки пакетов и образов для версий плана из реестров D-20; Stripe, Google, Telegram, внешние SMTP-серверы и любые записи во внешние системы запрещены (локальный Mailpit из docker-compose.dev.yml разрешён). В тестах исходящих HTTP-запросов нет: HTTP-клиент Laravel закрыт Http::preventStrayRequests() в базовом TestCase (W-01); Stripe SDK и google/auth ходят своими клиентами — только мок StripeClientInterface (W-16) и заглушка поставщика токена Google (W-18); bin/browser-check прерывает запросы к чужим хостам (W-03). Уровни T и P — только с выданными доступами и явным разрешением. git commit — только по указанию пользователя; контрольная точка после зелёного bin/check — по D-18.
- Отсутствующие реквизиты, доступы и секреты не заменять вымышленными рабочими значениями; мок не закрывает уровень T. Тестовые секреты — явно фиктивные строки, не похожие на sk_test_…/sk_live_…/whsec_…; токен Telegram в тестах — вида test-bot-token-not-real (не 123456789:AA…). Ключ сервисного аккаунта Google (JSON с private_key) в репозиторий не кладётся: в тестах — заглушка поставщика токена (W-18), при необходимости ключ генерируется тестом во временный каталог. Служебных маршрутов входа и других обходов аутентификации для проверок нет ни в какой среде (автовход по id, tinker, actingAs в bin/browser-check и других браузерных проверках; в PHPUnit actingAs штатный): bin/browser-check входит через форму входа W-07. Единственное исключение — консольная команда aiqme:demo-export с её отказами (W-24 done[7]).
- Служебные демо-надписи прототипа, заметки автора о непредоставленных данных и плановые пометки каталога (полный перечень и замены — D-21, D-36) в Blade, lang и ui_text не переносятся: надпись удаляется или заменяется нейтральной рабочей (например, «Register for a session ↗» · «Registruotis į mokymą ↗»); лимиты в подписях — из конфигурации (§6.4). Каждая задача, добавляющая или меняющая шаблоны, прогоняет по своим страницам поиск маркеров bin/browser-check (W-03 done[6]); законные совпадения — только в отслеживаемом tests/fixtures/marker-allowlist.json (страница, точная фраза, причина), любое другое совпадение — провал; итоговый поиск по всем страницам — W-21.
- Тексты интерфейса и шаблонов LT/EN (lang/lt|en/*.php, значения ui_text по умолчанию) — из видимого текста docs/customer-prototype/evidence/audit-20261016/verified-route-screens/<страница>/dom.html страниц задачи (§4.2); для состояний, которых там нет (шаги 2–5 калькулятора, модалка обучения, подтверждения формы и масштаба и др.), EN — из evidence/audit-20261016/states-followup/<состояние>/dom.html, пара LT/EN — из вызовов t('LT','EN') в docs/customer-prototype/source-backup-20261016/AIQME-site/dist/app.js; параметры просмотра модели (W-31) — из initViewer/drawViewer того же файла, код не копируется. Архив source-backup недоверенный: только чтение как текст (grep, просмотр), JS не запускается и не импортируется. Формулировки не придумываются: если текста в прототипе нет — короткая нейтральная надпись, перечисленная в progress.md. Только тексты (D-19), демо-надписи не переносятся (D-21). Не переносятся демо-НДС, тарифы доставки и «Prepaid collection»; в калькуляторе нет выбора технологии (D-37), подписей «excl. VAT» · «be PVM», столбца цены за единицу и строк скидки, доставки и VAT в сводке (§1.2, D-38). aiqme-site/lang/ входит в touches каждой задачи с шаблонами.
- Задача, создающая адреса строк §4.6 (04-content-seo.md, «Порядок проверки по задачам»), проверяет свои строки Feature-тестом по tests/fixtures/prototype-routes.json; строки ещё не созданных вкладок пропускаются с пометкой.
- Репозиторий публичный. Медиафайлы прототипа и третьих лиц (ролики, фото, логотипы брендов) в aiqme-site/, а кадры docs/customer-prototype/evidence/ — в любые отслеживаемые файлы не копировать; вместо них — ссылка с подписью источника или заглушка (логотип — D-25); тексты и CSS прототипа — D-19. Клиентские, загруженные и сгенерированные файлы (STL, вложения, PDF счетов, медиа тестов) — только в игнорируемых storage/ и build/ (исключение — отслеживаемая сборка aiqme-site/demo/ из W-24). Крупные и граничные STL-фикстуры генерируются тестом во временный каталог; в tests/fixtures/stl/ — только файлы до 100 КБ. Перед завершением задачи: git status --porcelain aiqme-site — новые неигнорируемые файлы только в ожидаемых каталогах приложения, без бинарных медиа больше 1 МБ и без .env, ключей, .codex/, .mcp.json.
- Правила Boost в сгенерированных aiqme-site/AGENTS.md (Codex) и aiqme-site/CLAUDE.md (Claude Code) уточняются этим планом; задания и проверки не зависят от того, какой агент ведёт сессию. Без отдельного одобрения: версии пакетов из плана (новый пакет — только с обоснованием в progress.md); модели, миграции и фабрики таблиц §2.5 задачи; команды из задачи, bin/check, bin/browser-check и скрипты проверок; файлы и каталоги из touches (включая новые app/Domain/, bin/, docker/, demo/, tests/js/, tests/browser/ и aiqme-site/docs/); запись progress.md и отчётов — правила Boost «Do not create models without user approval», «Stick to existing directory structure» и «only create documentation files if explicitly requested» для них не действуют. Pint в режиме исправления — только vendor/bin/pint --dirty (bin/check — с --test). Все команды PHP — через aiqme-site/bin/php (bin/php /usr/local/bin/composer …, bin/php artisan …, bin/php vendor/bin/pint …), а не системный php. Boost MCP необязателен. Boost search-docs не используется (boost.laravel.com не разрешён D-20): правило Boost «Use search-docs before changes…» заменяется чтением документации из vendor/.
- Решения D-xx применяются в варианте по умолчанию из decisions.md; отклонение — только по новому указанию пользователя.
- Каждая задача завершается записью в aiqme-site/docs/progress.md: что сделано, какие проверки запущены и их вывод, что не проверено. Ручной визуальный просмотр, который ночью некому выполнить, — «ждёт проверки пользователем», а не «готово».
- changes.md и review-*.md плана — исторические; ночной агент их не читает.
- Цель — новый сайт AIQME, повторяющий прототип заказчика. Принцип: максимально просто — только то, что просил заказчик (docs/requirements.md) и показывает прототип (эталон — rules[8]), в объёме задачи. Ничего сверх: ни функций, страниц, настроек и зависимостей, ни абстракций «на будущее». Неопределённое решается простейшим вариантом с записью в progress.md. Приёмщик ночного прогона считает лишнее замечанием.

## Срезы поставки

Каждый завершённый срез — пригодное приращение. Желаемый ориентир MVP 19.10.2026 не доказан оценкой; если к нему успевается только часть, выпускается завершённый префикс срезов, а не половина задачи.

| Срез | Содержание | Задачи |
|---|---|---|
| S1 | Публичный сайт, вход и контент из БД | W-01, W-02, W-03, W-04, W-05, W-06, W-25, W-07, W-08, W-26 |
| S2 | Формы, калькулятор, заявки, заказы, кабинет, админка заказов; демо-сборка для GitHub Pages | W-09, W-10, W-27, W-31, W-11, W-12, W-32, W-13, W-14, W-15, W-24 |
| S3 | Оплата, счета, Google Таблицы, Telegram (локально) | W-16, W-28, W-17, W-18, W-19 |
| S4 | Эксплуатационный пакет и итоговая локальная проверка | W-30, W-20, W-29, W-21 |
| S5 | Тестовые и рабочие подключения (нужны доступы) | W-22, W-23 |

## Порядок и зависимости

| Задача | Уровень | Зависит от | Открытые решения |
|---|---|---|---|
| [W-01](#w-01) Каркас приложения и инструменты | L | — | D-02, D-14, D-17, D-20, D-28, D-29, D-30, D-39 |
| [W-02](#w-02) Локали, маршруты и SEO-каркас | L | W-01 | — |
| [W-03](#w-03) Дизайн-оболочка по прототипу | L | W-02 | D-11, D-19, D-25, D-29, D-30 |
| [W-04](#w-04) Модель контента и ContentService | L | W-02, W-03 | D-11, D-19, D-21, D-32 |
| [W-05](#w-05) Публичные страницы I: главная, услуги, о нас, контакты, FAQ, политики | L | W-03, W-04 | D-11, D-26, D-32, D-40 |
| [W-06](#w-06) Публичные страницы II: проекты, информация, новости, обучение, поиск | L | W-05 | D-11, D-12 |
| [W-25](#w-25) Каталог: товары, сравнение, корзина, бренды | L | W-06 | D-12, D-36, D-41 |
| [W-07](#w-07) Вход, регистрация, восстановление и профиль | L | W-03 | D-15, D-29 |
| [W-08](#w-08) Админка: оболочка, общий редактор, услуги и страницы | L | W-04, W-07 | D-29 |
| [W-26](#w-26) Админка: остальные редакторы контента | L | W-08 | — |
| [W-09](#w-09) Формы обращений, обучение, письма и outbox | L | W-04, W-05, W-06, W-08 | D-15 |
| [W-10](#w-10) Ядро калькулятора: JS-модуль и тесты | L | W-03 | D-08, D-09, D-24 |
| [W-27](#w-27) Калькулятор: 5-шаговый интерфейс | L | W-10, W-03 | D-08, D-09, D-24, D-37, D-38 |
| [W-31](#w-31) Калькулятор: просмотр модели Canvas 2D | L | W-27 | — |
| [W-11](#w-11) Серверный анализ STL и настройки калькулятора | L | W-10, W-27, W-08 | D-08, D-09, D-24 |
| [W-12](#w-12) Отправка заявки на изготовление | L | W-11, W-27, W-09, W-07 | D-10 |
| [W-32](#w-32) Защищённая выдача STL и проверка прав на заказ | L | W-12 | — |
| [W-13](#w-13) Правила заказа: цена, производство, отмена | L | W-12, W-32 | D-06, D-07 |
| [W-14](#w-14) Админка: обзор, заказы, клиенты | L | W-13, W-32, W-08, W-09 | D-29 |
| [W-15](#w-15) Кабинет клиента и гостевая страница заказа | L | W-13, W-32, W-07 | D-10, D-04 |
| [W-24](#w-24) Демо-сборка для GitHub Pages (только фронтенд) | L | W-06, W-25, W-27, W-14, W-15 | D-16 |
| [W-16](#w-16) Платёжные запросы и Stripe: страница оплаты, intent, отмена (локальный уровень) | L | W-13, W-14, W-15 | D-06, D-33, D-42 |
| [W-28](#w-28) Webhook Stripe: исходы, аномалии, возвраты (локальный уровень) | L | W-16 | D-07 |
| [W-17](#w-17) Автоматические счета | L | W-28, W-08 | D-04, D-05, D-22, D-23, D-34 |
| [W-18](#w-18) Google Таблицы (локальный уровень) | L | W-28, W-17, W-09 | D-20 |
| [W-19](#w-19) Telegram-бот контента (локальный уровень) | L | W-08 | — |
| [W-30](#w-30) Сквозные сценарии §9.3 (локальный уровень) | L | W-18 | D-04, D-10, D-22 |
| [W-20](#w-20) Эксплуатационный пакет I: образ, prod-compose, nginx и прокси | L | W-17, W-18 | D-01, D-02, D-03, D-20, D-29, D-35, D-39 |
| [W-29](#w-29) Эксплуатационный пакет II: копии, расписание, измерения, runbook | L | W-20 | D-01, D-02, D-03, D-13, D-31 |
| [W-21](#w-21) Итоговая локальная проверка и передача | L | W-29, W-25, W-26 | D-20 |
| [W-22](#w-22) Тестовые подключения (Stripe test, тестовая таблица, тестовый бот, почта) | T | W-21 | D-15, D-39 |
| [W-23](#w-23) Рабочий запуск | P | W-22 | D-01, D-02, D-03, D-04, D-05, D-13, D-14, D-15, D-22, D-23, D-27, D-31, D-34, D-35, D-42 |

## W-01

**Каркас приложения и инструменты** · L — локально · срез S1 · зависит от: —

**Результат.** Laravel-приложение в aiqme-site/ (рядом с существующими README.md и experiments/) с Lunar 1.5.0, Livewire 3.8.10, Stripe SDK 16.6.0 (stripe/stripe-php напрямую, без lunarphp/stripe — §2.1) и версиями из composer.lock эксперимента; без отдельного Git внутри aiqme-site/ (весь корень — публичный репозиторий 3d-fabrication-web); aiqme-site/bin/php с расширениями по §2.1; pint.json, phpunit.xml и vite.config.js, исключающие experiments/; docker-compose.dev.yml (PostgreSQL 17 на 127.0.0.1:55440, Mailpit на 127.0.0.1); Boost по D-17; bin/check; команда aiqme:make-admin (скрытый ввод в терминале, без терминала — --password-stdin, D-29).

**Входы:** `aiqme-site/experiments/lunar-aiqme/app/composer.json`; `aiqme-site/experiments/lunar-aiqme/app/composer.lock`; `aiqme-site/experiments/lunar-aiqme/bin/php`; `aiqme-site/experiments/lunar-aiqme/runtime-packages.txt`; `aiqme-site/experiments/lunar-aiqme/app/boost.json`; `docs/planning/edition-2/02-architecture.md`; `.gitignore`

**Затрагивает:** `aiqme-site/ (новый, кроме README.md и experiments/)`; `aiqme-site/bin/php`; `aiqme-site/pint.json`; `aiqme-site/.codex/config.toml и aiqme-site/.mcp.json (локально, не в Git)`; `aiqme-site/docker-compose.dev.yml`; `aiqme-site/bin/check`; `aiqme-site/bin/php.d/ (лимиты §6.4 для локального PHP)`

**Критерии завершения:**

- Первый шаг — предпроверка окружения по D-28, D-30, D-39: bash docs/planning/edition-2/run/preflight.sh завершается с кодом 0 (нет FAIL; WARN по D-30 допустим); вывод скрипт сам дописывает в progress.md (запуск без перенаправления); ssh и gh, если агент выполняет их сам, — ровно в форме D-28, без перенаправлений. При любом FAIL задача блокируется и прогон останавливается с записью причины в progress.md: Docker не rootless — «Docker с правами root: изоляция ключей не обеспечена, D-39»; нет входов задач, есть legacy-landing/instance/ или archive/, PHP эксперимента без расширений или нет Python Playwright — «рабочая копия не подготовлена по D-39». Не остановка: наличие ~/.config/google-chrome/DevToolsActivePort — предупреждение (D-30; CDP запрещён в любом случае); при D-39 по умолчанию и (б) (~ — каталог учётной записи прогона) или если эту проверку не пустили права сессии — «не проверено (D-39)». Строка пользователя «D-39: вариант б» в progress.md снимает проверку rootless, «D-39: вариант в» — rootless и проверки instance/archive; без строки — вариант по умолчанию
- Скелет создан во временном каталоге aiqme-site/build/skeleton/ (composer create-project через aiqme-site/experiments/lunar-aiqme/bin/php, пока своего bin/php нет) и перенесён в aiqme-site/ без изменения README.md и experiments/, вместе с dotfiles корня скелета (.gitignore, .env.example, .editorconfig, .env); git status --porcelain aiqme-site/experiments пуст
- aiqme-site/bin/php запускает /usr/bin/php с PHP_INI_SCAN_DIR и LD_LIBRARY_PATH на aiqme-site/experiments/lunar-aiqme/runtime (только чтение; пути — от расположения скрипта, как у эксперимента, без абсолютного пути копии); php -m через обёртку показывает bcmath, intl, gd, pdo_pgsql, pgsql, zip; bin/php добавляет к PHP_INI_SCAN_DIR каталог aiqme-site/bin/php.d/ с лимитами §6.4 (upload_max_filesize=25M, post_max_size=260M, max_file_uploads=20, memory_limit=512M, max_execution_time=120): bin/php -i их показывает, дочерние процессы (artisan serve) наследуют переменную; зависимость от runtime эксперимента записана в progress.md (на сервере её заменяет образ PHP-FPM из W-20)
- pint.json исключает experiments, build, demo; @source/сканирование Vite и Tailwind и phpunit.xml не включают experiments/; bin/check запускает Pint с --test
- composer install воспроизводит версии эксперимента; отличия объяснены в progress.md; lunarphp/stripe не установлен (stripe/stripe-php 16.6.0 — прямая зависимость, §2.1), маршрута /stripe/webhook нет
- Миграции Lunar и Laravel проходят на пустой БД
- Панель Lunar доступна только при AIQME_LUNAR_PANEL=true; в production-конфигурации маршрута /lunar нет
- aiqme:make-admin создаёт единственного администратора, а если он уже есть — меняет его пароль, второго не создаёт; пароль — скрытым вводом (password() Laravel Prompts) в терминале, а без терминала (codex exec, claude -p, docker compose exec -T) — флагом --password-stdin, читающим одну строку из stdin (D-29); опции или аргумента со значением пароля нет; без терминала и без --password-stdin — ошибка с подсказкой, администратор не создаётся; тесты всех трёх путей и повторного запуска; DatabaseSeeder шаблона не создаёт тестового пользователя (test@example.com удалён), сидер контента запускается по имени класса; is_admin не входит в $fillable модели User (массовое присвоение игнорируется); HTTP-регистрация и её тест — в W-07
- Отдельный git init в aiqme-site/ не выполняется; .env, storage/app/*, public/build, public/storage, vendor, node_modules, .codex/, .mcp.json исключены корневым или вложенными .gitignore скелета (git check-ignore подтверждает); все ports: docker-compose.dev.yml (PostgreSQL, Mailpit UI и SMTP) привязаны к 127.0.0.1: в docker compose -f docker-compose.dev.yml config --format json у каждой записи ports host_ip = "127.0.0.1" (запись без host_ip — провал; поиск строки 0.0.0.0 не засчитывается); секретов в коде нет
- Boost установлен по D-17; конфигурации MCP с абсолютными путями не отслеживаются Git; корневой AGENTS.md не изменён
- Команды PHP в bin/check, конфигурациях MCP и progress.md — через aiqme-site/bin/php (composer — bin/php /usr/local/bin/composer или путь из which composer); рабочий каталог сессии записан в progress.md (Claude Code — корень, D-28; Codex — корень или aiqme-site/); в aiqme-site/.mcp.json команда и аргументы — абсолютные пути к aiqme-site/bin/php и aiqme-site/artisan; .mcp.json создаётся и исправляется только командой (boost:install через bin/php) или скриптом через python3 — Edit/Write и перенаправление в защищённые пути Claude Code (.mcp.json, .claude/) в dontAsk отклоняются (D-28); если не вышло — запись в progress.md, задачу это не блокирует; Claude Code из корня подключает его флагом --mcp-config aiqme-site/.mcp.json только в сессиях после W-01; Codex берёт aiqme-site/.codex/config.toml только при запуске из aiqme-site/ и если проект отмечен доверенным (trust_level = "trusted" в ~/.codex/config.toml) — состояние записано в progress.md; без MCP задачи выполняются без Boost MCP
- Базовый tests/TestCase.php вызывает Http::preventStrayRequests(): тест, обращающийся к внешнему адресу через HTTP-клиент Laravel без Http::fake, падает (тест)

**Проверки:**

- bin/check зелёный
- aiqme-site/bin/php -m: нужные расширения
- git status --porcelain aiqme-site/experiments пуст
- тест: маршрут /lunar отсутствует при выключенном флаге
- тест: User::create/fill с is_admin=true не даёт прав; aiqme:make-admin их выдаёт — скрытым вводом и с --password-stdin

**Ограничения:**

- Не обновлять версии без проверки совместимости
- Не копировать .env, фикстуры и LocalTestAdapter эксперимента

**Неизвестные параметры:** [D-02](decisions.md#d-02), [D-14](decisions.md#d-14), [D-17](decisions.md#d-17), [D-20](decisions.md#d-20), [D-28](decisions.md#d-28), [D-29](decisions.md#d-29), [D-30](decisions.md#d-30), [D-39](decisions.md#d-39)

**Трассировка:** old_T: T-005, T-008, T-010; R: R-009; Q: Q-29-02

## W-02

**Локали, маршруты и SEO-каркас** · L — локально · срез S1 · зависит от: W-01

**Результат.** Таблица маршрутов LT/EN с локализованными сегментами по прототипу; RU-сегменты по §4.3; / → 301 /lt/; /ru/ только для администратора; компонент SEO-head (title, description, canonical, hreflang, robots, Open Graph: og:title, og:description, og:image — когда у страницы есть медиа); 404/500 на обеих локалях; переключатель языка для статических страниц (для сущностей — в W-04/W-06); sitemap.xml (статические страницы; опубликованные сущности добавляют W-05, W-06, W-25) и robots.txt.

**Входы:** `docs/customer-prototype/audit-20261016/routes.md`; `docs/customer-prototype/evidence/audit-20261016/route-manifest.json`; `docs/planning/edition-2/04-content-seo.md (§4.3, §4.6)`

**Затрагивает:** `aiqme-site/routes/`; `aiqme-site/app/Http/Middleware/`; `aiqme-site/app/Support/Seo/`; `aiqme-site/lang/`; `aiqme-site/tests/fixtures/prototype-routes.json`

**Критерии завершения:**

- Статические публичные адреса route-manifest, которые по §4.6 должны отвечать 200, отвечают через общие шаблоны-заготовки; адреса сущностей (услуги, статьи, проекты, товары) проверяются в W-05/W-06/W-25 после сидера W-04, slug в маршруты не зашиваются
- Адреса из §4.6 с ожидаемым 404 (демо-товары, /developer/, неканонические дубли статей, голый checkout, условные кабинетные адреса) — 404
- Неизвестный URL — 404, не главная
- /ru/... без входа администратора — 404; с входом — noindex в заголовке и meta
- hreflang выводится только для существующих альтернатив; x-default → LT; canonical, hreflang, sitemap и внутренние ссылки строятся только через route() (без завершающего «/», §4.4); адрес со «/» отвечает тем же содержимым с тем же canonical (тест)
- Из route-manifest один раз извлечён отслеживаемый tests/fixtures/prototype-routes.json — только path и ожидаемый статус/строка §4.6, без текстов, заголовков и ссылок (D-19); Feature-тесты W-02…W-21 и W-24 читают только его и работают на чистом клоне без docs/customer-prototype/

**Проверки:**

- Feature-тест по таблице §4.6 в объёме W-02 (порядок — §4.6): статические страницы, 301 и 404; адреса сущностей, кабинет и админка — в своих задачах; адрес без строки в таблице — провал теста; тест читает только отслеживаемый tests/fixtures/prototype-routes.json
- тест /ru/ для гостя и администратора (у администратора — EN-значения lang/ при отсутствии RU, не ключи переводов, §4.3; значения ui_text из БД на /ru/ проверяет W-04)
- проверка sitemap.xml без XSD и сети: XML разбирается, корень urlset в пространстве имён sitemaps.org, адреса абсолютные от APP_URL, пары xhtml:link взаимные, нет /ru/, кабинета, админки и поиска, адресов не больше 50 000

**Ограничения:**

- Не сохранять старые URL github-pages
- Ссылки — обычные <a href>

**Неизвестные параметры:** нет

**Трассировка:** old_T: T-030, T-035; R: R-001, R-030; Q: Q-08-02, Q-08-03; AC: AC-08-01, AC-08-02, AC-08-09

## W-03

**Дизайн-оболочка по прототипу** · L — локально · срез S1 · зависит от: W-02

**Результат.** Blade-компоненты шапки, второго ряда CTA, мобильного меню, подвала, хлебных крошек, кнопок, карточек, форм, модальных окон, таблиц, пустых состояний, заглушки, cookie-плашки, постоянного блока статуса (§7.2); логотип по D-25; CSS прототипа с токенами и брейкпоинтами (D-19); Arial-стек; исправления overflow и aria-expanded; middleware заголовков безопасности §6.6 (CSP с nonce); bin/browser-check с автоматической сверкой оформления §9.1.

**Входы:** `docs/customer-prototype/audit-20261016/visual-and-responsive.md`; `docs/customer-prototype/evidence/audit-20261016/resources/3b84c1552d750b1f9d1d94c1ede102fc5cb443bf8e0b98963fb38cdd600aa5d8.css (style.css прототипа; соответствие имён — resources.json)`; `docs/customer-prototype/evidence/audit-20261016/verified-route-screens.json`; `legacy-landing/design-previews/animation-v2/shared/app.js`; `legacy-landing/design-previews/animation-v2/shared/responsive.css`; `docs/planning/edition-2/07-design.md`; `docs/planning/edition-2/06-security-files.md (§6.6)`; `legacy-landing/site/assets/images/ (logo-dark.png, logo-light.png — логотип по D-25, только копия)`

**Затрагивает:** `aiqme-site/resources/views/components/`; `aiqme-site/resources/css/`; `aiqme-site/resources/js/menu.js`; `aiqme-site/app/Http/Middleware/SecurityHeaders.php`; `aiqme-site/bin/browser-check`; `aiqme-site/tests/browser/`; `aiqme-site/tests/fixtures/marker-allowlist.json`

**Критерии завершения:**

- bin/browser-check сверяет оболочку на 1440/768/390 через getComputedStyle с §7.1 (шрифт, размеры и веса заголовков, цвета токенов, высоты шапки и второго ряда, поля контейнера) с допуском ±2 px / точное совпадение цвета; все сверки проходят
- Кадры оболочки сняты в размерах verified-route-screens.json; отчёт «наш кадр рядом с эталоном» сохранён в игнорируемом aiqme-site/build/verification/ (кадры прототипа не копируются в отслеживаемые файлы; без evidence/ отчёт пропускается с пометкой «не проверено (нет эталона)», сверка getComputedStyle выполняется); ручной просмотр отмечен «ждёт проверки пользователем»
- Escape закрывает меню и выставляет aria-expanded=false, фокус возвращается
- Ширина документа равна окну на 320/390/768/1024/1440 для оболочки
- Заголовки §6.6 (CSP enforce с nonce по §6.6: style-src-elem с nonce, style-src-attr 'unsafe-inline' (атрибуты style прототипа и Livewire), без 'unsafe-inline' для script-src и элементов <style>; X-Content-Type-Options, Referrer-Policy, frame-ancestors) отдаются на всех страницах; в консоли браузера нет нарушений CSP
- Надписи оболочки прототипа (D-21, пункт «Надписи оболочки»: шапка, подвал, noscript, cookie-плашка, группа служебных ссылок подвала целиком) отсутствуют
- bin/browser-check ищет маркеры служебных демо-надписей D-21 без учёта регистра (demo, demonstr, illustrativ, iliustr, prototyp, prototip, example, pavyzd, sample) в видимом тексте и атрибутах placeholder, aria-label, title, alt, value отрендеренных страниц (LT/EN; без demo/); маркеры заметок автора прототипа — not been provided, nepateikt, fictional, išgalvot, will appear here, čia bus, а также плановых пометок каталога — planned range, planuojam, to be confirmed, tikslinam, official representation, atstovavim (D-21); путь страниц задаётся аргументом (каждая задача проверяет свои страницы); совпадение исправляется или вносится в отслеживаемый tests/fixtures/marker-allowlist.json (страница, точная фраза, причина — например, услуга «prototipai», не надпись о демонстрации); bin/browser-check завершается с ошибкой при любом совпадении вне этого файла (тест скрипта на странице с маркером); progress.md списком исключений не служит
- Изображения прототипа не скопированы: url('/assets/…') в CSS прототипа заменены заглушкой или удалены; в консоли нет ответов 404 на ресурсы; логотип — по D-25
- bin/browser-check работает на своей БД aiqme_browser dev-PostgreSQL (не aiqme и не _test): перед прогоном migrate:fresh, сидер контента W-04 (с W-04) и данные задачи из фабрик (tests/browser/); создаёт администратора (aiqme:make-admin --password-stdin, D-29) и клиента (фабрика из tests/browser/, запуск по имени класса) со случайным паролем на каждый запуск; фабрики tests/browser/ дают e-mail на домене aiqme.test, а имена и тексты — без слов-маркеров; сервер и подготовка данных идут с QUEUE_CONNECTION=sync (конверсии medialibrary и задания — сразу); пароль не пишется в отслеживаемые файлы и журналы; формы входа до W-07 нет, поэтому страницы кабинета, админки и /ru/ в W-03 отмечаются «пропущено: вход появится в W-07» (это не провал), а вход через форму добавляет W-07; локальный сервер запускается через bin/php (лимиты §6.4) с явным --port (фиксированный порт скрипта, не 8000; если он занят — отказ до старта, а не другой порт), проверки ходят только на этот порт; запросы к любым хостам, кроме проверяемого, прерываются (Playwright route.abort) и перечисляются в отчёте (D-20)

**Проверки:**

- bin/browser-check: overflow, меню, фокус, сверка §7.1, нарушения CSP в консоли, поиск маркеров D-21
- Feature-тест: в отрендеренной оболочке LT и EN нет надписей оболочки D-21, в том числе «Prototipo aplinkos» и «Prototype workspaces»
- Feature-тест заголовков безопасности
- текстовая сводка сверки в aiqme-site/docs/verification/
- Feature-тест: в CSP есть style-src-attr 'unsafe-inline' и nonce в script-src/style-src-elem, нет 'unsafe-inline' в script-src и style-src-elem

**Ограничения:**

- Эффекты V2 не переносить
- Не включать озвучивание и системные флаги доступности

**Неизвестные параметры:** [D-11](decisions.md#d-11), [D-19](decisions.md#d-19), [D-25](decisions.md#d-25), [D-29](decisions.md#d-29), [D-30](decisions.md#d-30)

**Трассировка:** old_T: T-003, T-030; R: R-002, R-003; AC: AC-09-03, AC-09-06, AC-10-03; audit: F-39, F-46

## W-04

**Модель контента и ContentService** · L — локально · срез S1 · зависит от: W-02, W-03

**Результат.** Таблицы content_items/translations/revisions/ui_texts, типы контента §4.1, публикация по локали, проверка ревизии, заглушки разделов, медиабиблиотека (spatie/medialibrary, WebP-варианты), журнал публикации (activitylog), сидер исходного контента из прототипа по D-11, читающий только отслеживаемый database/seeders/data/prototype-content.json.

**Входы:** `docs/planning/edition-2/04-content-seo.md`; `aiqme-site/experiments/lunar-aiqme/app/app/Services/ContentPublisher.php`; `docs/customer-prototype/evidence/audit-20261016/data-from-source.json`; `docs/customer-prototype/evidence/audit-20261016/verified-route-screens/ (только тексты из dom.html каталогов lt, en, lt__apie-mus, en__about, lt__duk, en__faq, lt__paslaugos, en__services, lt__paslaugos__3d-skenavimas, en__services__3d-scanning, lt__kontaktai, en__contact, lt__mokymai, en__training, lt__privatumas, en__privacy, lt__slapukai, en__cookies, lt__pristatymas, en__delivery, lt__grazinimas, en__returns)`

**Затрагивает:** `aiqme-site/app/Domain/Content/`; `aiqme-site/app/Domain/Audit/`; `aiqme-site/database/migrations/`; `aiqme-site/database/seeders/`; `aiqme-site/database/seeders/data/prototype-content.json`; `aiqme-site/app/Providers/ (загрузчик переводов ui_texts)`

**Критерии завершения:**

- ContentService — единственная точка записи контента; права — только администратор
- Публикация LT не публикует EN; устаревшая ревизия отклоняется
- Импортированы тексты услуг, главной, о нас, FAQ, 3 статей (D-19): статьи — раздел «библиотека», slug — id из data-from-source.json (как в адресах прототипа), категория — из cat, дата — момент импорта; 5 категорий оборудования главной (categories из data-from-source.json: названия LT/EN, slug как в ссылках ?category= прототипа) импортируются опубликованными — это навигация, а не демо-ассортимент; блок команды «О нас» не импортируется (D-21, D-32); демо-товары, новости, бренды, цены и медиа третьих лиц не импортированы; проекты и политики — по D-11, обучение — по D-21
- Журнал фиксирует публикацию и снятие с публикации с каналом
- Переключатель языка для сущностей ведёт на опубликованную альтернативу, иначе — на список раздела или главную другой локали
- Тексты прототипа один раз извлечены в отслеживаемый database/seeders/data/prototype-content.json: услуги с их FAQ, 3 статьи и проекты — из data-from-source.json; главная, о нас, общий FAQ, контакты, обучение и 4 политики (LT и EN) — из текстов dom.html verified-route-screens (в data-from-source.json их нет). Копируются только тексты — без разметки, атрибутов, медиа и персональных данных (D-19); политики и проекты (D-11), темы и даты обучения (D-21) — черновики, переносятся дословно и не правятся; в публикуемых текстах (главная, о нас, контакты, общий FAQ, услуги с их FAQ; LT и EN) удалены или заменены только фразы явной таблицы D-21, всё остальное перенесено дословно; каждая правка перечислена в progress.md с пометкой «ждёт проверки пользователем». FAQ услуг — faq_item с группой услуги; общий вопрос всех страниц услуг «Nuo ko priklauso terminas?» · «What determines the lead time?» (из dom.html страниц услуг) — один faq_item группы services; в группе general — только вопросы страницы FAQ, которых нет в FAQ услуг (2 вопроса: аккаунт и самовывоз), без копий; у service нет полей процесса, примера и FAQ (§4.1), но есть краткое описание short (data-from-source.json), подпись и три пункта блока услуги (видимый текст главной и lt__paslaugos/en__services, §4.1); вводный текст страницы обучения — публикуемый page_text (заголовок и абзац панели «Programa jums arba jūsų komandai…» · «A programme for you or your team…»; W-05 выводит его на странице обучения и на странице услуги обучения). Сидер читает только этот JSON и работает на чистом клоне и в образе W-20 без docs/customer-prototype/; повторный запуск на непустой БД ничего не перезаписывает
- Переопределение ui_text подключено на уровне переводчика Laravel (загрузчик, читающий ui_texts поверх lang/): __() в любом шаблоне, включая оболочку W-03, учитывает значение из БД; тест — значение из БД видно в шапке; на /ru/ у администратора — EN-значение ui_text из БД, не ключ (fallback_locale, §4.3; тест)

**Проверки:**

- Unit/Feature: публикация по локали, заглушки, ревизии, санитизация разметки
- тест: неадминистратор не может опубликовать через сервис
- тест сидера: вопросы faq_item (LT и EN) не повторяются между группами
- тест сидера: элементы training, политики и проекты — черновики; в публикуемых элементах prototype-content.json нет ни одной исходной фразы таблицы D-21 (LT и EN; для часов работы — слов tikslinamas и to be confirmed), а заменённые фразы таблицы есть в новом виде; список фраз в тесте совпадает с таблицей D-21

**Ограничения:**

- Без конструктора страниц
- Произвольный HTML/JS запрещён

**Неизвестные параметры:** [D-11](decisions.md#d-11), [D-19](decisions.md#d-19), [D-21](decisions.md#d-21), [D-32](decisions.md#d-32)

**Трассировка:** old_T: T-026, T-027, T-031; R: R-004, R-005, R-006, R-018, R-025, R-026, R-030; Q: Q-04-05, Q-04-06, Q-15-02; AC: AC-15-06, AC-15-07, AC-15-08

## W-05

**Публичные страницы I: главная, услуги, о нас, контакты, FAQ, политики** · L — локально · срез S1 · зависит от: W-03, W-04

**Результат.** Серверные страницы главной, списка и 6 страниц услуг, о нас, контактов (форма без серверной обработки до W-09), FAQ, 4 политик на LT/EN из контента; hero-видео с паузой и reduced-motion; ленты брендов/новостей с заглушкой; сцена «СКАН → CAD → ДЕТАЛЬ» в MVP не выводится (D-40).

**Входы:** `docs/planning/edition-2/01-scope.md`; `docs/customer-prototype/audit-20261016/screen-inventory.md`; `legacy-landing/design-previews/animation-v2/ (видеоконтроллер)`; `docs/planning/edition-2/07-design.md`; `docs/customer-prototype/evidence/audit-20261016/verified-route-screens/ (только dom.html страниц этой задачи — для однократного извлечения tests/fixtures/prototype-blocks.json, видимых текстов шаблонов по rules и сверки текста done[8], если каталог есть локально)`

**Затрагивает:** `aiqme-site/resources/views/public/`; `aiqme-site/resources/js/hero-video.js`; `aiqme-site/resources/js/tickers.js`; `aiqme-site/app/Http/Controllers/Public/`; `aiqme-site/tests/fixtures/prototype-blocks.json`; `aiqme-site/tests/fixtures/text-allowlist.json`; `aiqme-site/tests/browser/ (фабрики данных задачи: бренды, новости, медиа hero)`

**Критерии завершения:**

- bin/browser-check сверяет страницы на 1440/768/390 через getComputedStyle с §7.1 (hero, заголовки, карточки услуг) и порядок ключевых блоков по отслеживаемому tests/fixtures/prototype-blocks.json (один раз извлечён из dom.html эталона: страница → упорядоченный список прямых дочерних элементов main в виде «тег + class», например section.hero, section.section.dark, без текстов; у элемента, класс которого содержит wrap и page-content (на FAQ и политиках — div.wrap.page-content.article), — ещё и его прямые дети; демо-блоки div.notice и div.notice.warning и блок команды div.inset.gap на «О нас» (D-21, D-32) исключены; на услугах div.section-head примера сверяется, только если у услуги есть опубликованный проект (§7.1); политики-заглушки в сверку блоков не входят — §7.1; Blade-секции сохраняют классы прототипа; сверка блоков не читает evidence/); все сверки проходят; отчёт с кадрами — в aiqme-site/build/verification/ (без evidence/ — «не проверено (нет эталона)»), ручной просмотр — «ждёт проверки пользователем»
- Иллюстрации услуг, постер hero и фото — собственная заглушка (SVG/CSS в resources/) до медиа заказчика; заглушка или медиа отрисованы (naturalWidth > 0 либо видимый SVG); изображения прототипа не скопированы, подпись иллюстрации услуги прототипа не выводится (§7.2, F-47, D-21)
- Страница услуги: процесс — общий блок главной; пример — карточка опубликованного проекта этой услуги, без него заголовок «Application example» и карточка не выводятся, а div.grid.two с панелью «One point of contact…» (ui_text) остаётся; FAQ — группа услуги, затем группа services (§4.1). Страница услуги обучения: после первого div.grid.two.gap — блок div.gap «Mokymų temos ir datos» · «Training topics and dates» (компонент, общий со страницей обучения: список опубликованных training — в W-05 заглушка, список подключает W-06; панель «Programa jums arba jūsų komandai…» · «A programme for you or your team…» с кнопкой в контакты: заголовок и абзац — page_text «вводный текст обучения» W-04 (один источник для обеих страниц, в lang не дублируется), кнопка — по rules; ярлык «DEMONSTRACINIS TVARKARAŠTIS» · «ILLUSTRATIVE SCHEDULE» не выводится, D-21; §1.2). Страница FAQ — группы услуг в порядке услуг, затем general, без группы services (8 вопросов, как в эталоне)
- Лента главной — только опубликованные статьи раздела «новости»; пока их нет — заглушка без ленты (D-26)
- SEO-поля переводов применяются в title/description; заголовок с переносом строки — в h1 перенос строки, в хлебных крошках и заголовке документа (title) пробел; description без SEO-поля — из вводного абзаца страницы, не из слогана подвала (§7.2, тест)
- Сцена «СКАН → CAD → ДЕТАЛЬ» (gear.js V2) в MVP не выводится (D-40); строка hero «01 SCAN → 02 DESIGN → 03 MANUFACTURE» — текст прототипа, а не место сцены; крыльчатки нет
- sitemap.xml содержит опубликованные страницы и услуги этой задачи с alternates и не содержит разделов-заглушек и черновиков; Feature-тест: публикация EN-перевода добавляет адрес и alternate, снятие — убирает; страницы выводят Open Graph из SEO-полей (тест)
- Вывод медиа по §4.4 — один компонент: изображение медиатеки выводит WebP srcset/sizes, width/height, loading=lazy ниже первого экрана и alt из подписи; видео hero — preload=metadata и мобильный источник ≤700 px; подпись источника — из поля «источник» media (§4.1); тест на фабричном медиа
- Тексты перенесены дословно и полностью: bin/browser-check сравнивает видимый текст main страниц задачи (LT/EN) с текстом main dom.html эталона, если docs/customer-prototype/evidence/ есть локально (иначе — «не проверено (нет эталона)», не провал; Feature-тесты от evidence/ не зависят); пробелы нормализуются; направление — каждая фраза эталона (после исключений) есть в нашем main в том же порядке, лишний текст нашей стороны (данные фабрик: бренды, новости) не проверяется; из эталона исключаются исходные фразы таблицы D-21 (с учётом их замен), блоки, исключённые из prototype-blocks.json, условные блоки без данных (пример проекта на страницах услуг, §7.1; расписание тем и дат обучения вместе с ярлыком «ILLUSTRATIVE SCHEDULE» — исключается всегда, с опубликованными элементами и без них: темы и даты черновики, D-21), страницы-черновики (политики, D-11) и служебные надписи D-21; любая другая отсутствующая фраза — провал; законные отличия (нейтральные надписи по rules, заглушки лент и медиа без данных, исправленные дефекты прототипа §7.2 — например, LT-текст на EN-странице или видимый <br> — с причиной) — только в отслеживаемом tests/fixtures/text-allowlist.json (страница, точная фраза эталона, причина); отчёт расхождений — в aiqme-site/build/verification/

**Проверки:**

- SEO-тесты маршрутов
- overflow LT/EN на всех ширинах
- reduced-motion на данных браузерной проверки (W-03; фабрики: 2 опубликованных бренда, 2 новости раздела «новости», медиа hero — настоящий короткий ролик, который тест создаёт во временном каталоге через ffmpeg; без ffmpeg — «не проверено» с причиной): с prefers-reduced-motion — video.paused === true, нет autoplay, виден постер, у лент нет анимации и дублей; без него — video.paused === false, лента движется, кнопка паузы её останавливает
- Feature-тест по §4.6: 6 услуг со slug своей локали — 200, slug другой локали — 404 (slug из БД после сидера W-04)
- Feature-тест по таблице D-21: на главной, о нас, контактах, FAQ и страницах услуг (LT и EN) нет ни одной исходной фразы таблицы, заменённые фразы выводятся в новом виде; aria-label ленты брендов главной — «Prekių ženklai» · «Technology brands», без «Planuojami prekių ženklai» · «Planned technology brands» (абзац D-21 под таблицей)
- Feature-тест: пока проекты — черновики, на страницах услуг нет ссылок на /lt/projektai/<slug>/ и /en/projects/<slug>/
- автотест по словарю: на EN-страницах задачи нет LT-строк ключей ui_text (§7.2)
- Feature-тест: на «О нас» нет блока команды и фраз заметки автора (D-21)
- сверка видимого текста main с эталоном (done[8]; без evidence/ — «не проверено (нет эталона)»)

**Ограничения:**

- Медиа без подтверждённых прав (ролик Bosch / The Apic и др.) в aiqme-site/ не копировать: постер-заглушка; подпись источника со ссылкой — только если у медиа hero задан источник, у заглушки подписи нет (§7.3)

**Неизвестные параметры:** [D-11](decisions.md#d-11), [D-26](decisions.md#d-26), [D-32](decisions.md#d-32), [D-40](decisions.md#d-40)

**Трассировка:** old_T: T-030, T-031; R: R-001, R-002, R-004, R-006, R-025, R-026; audit: F-37, F-40, F-41, F-45, F-47

## W-06

**Публичные страницы II: проекты, информация, новости, обучение, поиск** · L — локально · срез S1 · зависит от: W-05

**Результат.** Проекты с фильтрами и карточкой (EN-тексты из EN-перевода; вводный текст и notice списка — по D-21); информация/библиотека/новости с вкладками, поиском, категорией и заглушкой новостей (фильтр «Language» прототипа не переносится: материалы другой локали не показываются; панель техдокументов — заголовок и заглушка «Informacija ruošiama» · «Information is being prepared» вместо заметки автора, D-21); список обучения — в компоненте блока тем и дат из W-05 на странице обучения и на странице услуги обучения (детальной страницы обучения нет, как в прототипе: запись — в списке, регистрация — модалка W-09); серверный поиск по опубликованному контенту. Каталог — W-25.

**Входы:** `docs/planning/edition-2/01-scope.md`; `docs/customer-prototype/audit-20261016/features.md (F-02, F-36, F-38)`

**Затрагивает:** `aiqme-site/resources/views/public/`; `aiqme-site/app/Http/Controllers/Public/`; `aiqme-site/app/Domain/Content/Search.php`; `aiqme-site/tests/browser/ (фабрики данных задачи)`; `aiqme-site/tests/fixtures/text-allowlist.json (записи статей)`

**Критерии завершения:**

- Без опубликованных новостей и проектов показывается согласованная заглушка; после публикации — контент без изменения кода
- EN-страница проекта без EN-перевода — 404 и отсутствует в списках
- Любая статья (и раздела «новости», и библиотеки) доступна только по основному URL библиотеки /lt/biblioteka/<slug>/ · /en/library/<slug>/ (§4.1); раздел влияет только на списки; дубли /informacija/<slug>/ и /naujienos/<slug>/ — по §4.6
- Поиск — по опубликованным service (с short), article и product текущей локали, как в прототипе (тип product есть с W-04; тест на фабричном товаре); не находит черновики и материалы другой локали
- Карточка статьи выводит «Specific manufacturing limits are set for each project.» без фразы «Manufacturer technical documents and translations are not added until provided.» (и LT-пары); панель техдокументов — заглушка (D-21, тест)
- sitemap.xml содержит опубликованные статьи (адрес библиотеки) и проекты с alternates, а страницу списка обучения /lt/mokymai/ · /en/training/ — только при ≥1 опубликованном обучении; без черновиков и разделов-заглушек; Feature-тест публикации и снятия, как в W-05

**Проверки:**

- Feature-тесты заглушек, фильтров проектов, поиска
- браузерная проверка на опубликованных данных: фабрики tests/browser/ на БД aiqme_browser — 1 проект LT+EN, связанный с услугой, 1 обучение, 1 новость; поиск маркеров D-21, ширина документа на 320/390 и нарушения CSP — по спискам, карточкам, странице списка обучения, странице услуги обучения и страницам проекта и статьи и по странице услуги с примером проекта (div.section-head «Application example»), а не только по заглушкам
- Feature-тест по §4.6: 3 статьи по адресу библиотеки — 200, дубли /informacija/, /naujienos/ (/information/, /news/) — 404, демо-проекты — по D-11
- сверка видимого текста main трёх статей с эталоном — как W-05 done[8] (без evidence/ — «не проверено (нет эталона)»)

**Ограничения:**

- Не добавлять функций сверх прототипа

**Неизвестные параметры:** [D-11](decisions.md#d-11), [D-12](decisions.md#d-12)

**Трассировка:** old_T: T-030, T-031; R: R-001, R-006, R-030; Q: Q-08-01; audit: F-02, F-36, F-38

## W-25

**Каталог: товары, сравнение, корзина, бренды** · L — локально · срез S1 · зависит от: W-06

**Результат.** Товары: заглушка, после публикации — список, фильтры категории (?category=) и производителя (бренда), фильтр бренда — ?brand=<id бренда>, сортировки «Recommended» (по дате публикации перевода, новые первыми) и «Name A–Z», поиск по названию (D-41: фильтров технологии и цены и сортировки по цене нет), карточка, сравнение до 4, корзина с пустым состоянием (заголовок прототипа и ссылка на калькулятор; демо-фраза «Try the purchase flow…» не выводится); проверка ленты брендов главной после публикации бренда (вывод и заглушку ленты делает W-05).

**Входы:** `docs/planning/edition-2/01-scope.md`; `docs/customer-prototype/audit-20261016/features.md (F-03, F-04, F-05, F-06)`

**Затрагивает:** `aiqme-site/resources/views/public/products*`; `aiqme-site/app/Http/Controllers/Public/`; `aiqme-site/tests/browser/ (фабрики данных задачи)`

**Критерии завершения:**

- Без опубликованных товаров и брендов показывается согласованная заглушка; после публикации — контент без изменения кода
- Покупка товара недоступна; «Запросить предложение» ведёт в контактную форму со ссылкой по контракту предзаполнения §1.2 (product=<slug>); форма читает параметры в W-09
- Сравнение принимает не больше 4 опубликованных товаров; таблица при ≥2
- sitemap.xml содержит опубликованные товары с alternates, без заглушек; Feature-тест публикации и снятия, как в W-05
- Плановые пометки каталога не выводятся (D-21, D-36): notice списка товаров и brand-note удалены целиком, ярлыки «PLANUOJAMA/PLANNED RANGE», «<бренд> / PLANUOJAMA» · «<бренд> / PLANNED» (страница товара), «Tiekimas tikslinamas/Supply to be confirmed», «Tiekimo būsena: tikslinama/Supply status: to be confirmed» и подпись «Schematic category illustration…» не выводятся, панель техдокументов товара — заглушка «Informacija ruošiama» · «Information is being prepared»; «Pagal užklausą/On request» и «Gauti pasiūlymą/Request a quote» остаются; notice совместимости страницы товара остаётся, в LT без слова «Prototipe» (D-21); Feature-тест: этих фраз нет (LT и EN) в списке, карточке, странице товара и сравнении

**Проверки:**

- Feature-тесты заглушек, фильтров, поиска, сравнения (≤4)
- браузерная проверка на опубликованных данных: фабрики tests/browser/ на БД aiqme_browser — 4 товара с брендом и категорией, сравнение из 4 товаров; поиск маркеров D-21, ширина документа на 320/390 и нарушения CSP — по списку, карточке, странице товара и сравнению

**Ограничения:**

- Демо-ассортимент не импортировать
- Не добавлять функций каталога сверх прототипа

**Неизвестные параметры:** [D-12](decisions.md#d-12), [D-36](decisions.md#d-36), [D-41](decisions.md#d-41)

**Трассировка:** old_T: T-030, T-031; R: R-001, R-004, R-005; audit: F-03, F-04, F-05, F-06

## W-07

**Вход, регистрация, восстановление и профиль** · L — локально · срез S1 · зависит от: W-03

**Результат.** Экраны входа, регистрации и восстановления по прототипу с реальной логикой; письмо сброса через очередь (Mailpit локально); сессии в БД; ограничение попыток; вкладка «Addresses & company»; вход администратора и защита /admin, /ru/.

**Входы:** `docs/customer-prototype/audit-20261016/forms.md (auth-form, address-form)`; `docs/planning/edition-2/06-security-files.md`; `docs/planning/edition-2/02-architecture.md (§2.5 customer_profiles)`

**Затрагивает:** `aiqme-site/app/Http/Controllers/Auth/`; `aiqme-site/resources/views/account/`; `aiqme-site/app/Mail/`; `aiqme-site/routes/`; `aiqme-site/database/migrations/ (customer_profiles)`; `aiqme-site/lang/`; `aiqme-site/bin/browser-check (вход через форму)`; `aiqme-site/tests/browser/`; `aiqme-site/config/session.php и aiqme-site/.env.example (SESSION_ENCRYPT)`

**Критерии завершения:**

- Сброс пароля: нейтральный ответ, одноразовый токен со сроком, письмо в Mailpit, старые сессии завершаются механизмом Laravel AuthenticateSession (middleware auth.session на маршрутах кабинета и админки, §6.3; от драйвера сессий не зависит): тест — вход через форму → смена пароля сбросом по ссылке из Mail::fake → следующий запрос той же сессии ведёт на вход
- Выход инвалидирует сессию; скопированная cookie после выхода не работает
- Профиль клиента (Addresses & company: имя, компания, код компании, VAT-код, адрес, город, индекс — всё необязательно) сохраняется в таблице customer_profiles (§2.5); подстановка в форму заявки — в W-12
- Пароль: минимум 8 в Password::defaults() и minlength=8 в формах, autocomplete current-password/new-password; отдельного экрана смены пароля нет (§6.3)
- bin/browser-check (W-03) входит администратором и клиентом через форму входа этой задачи; тест скрипта: вход обоих, страницы кабинета и админки отвечают 200, /ru/ — 200 с noindex; пометки «пропущено: вход появится в W-07» сняты
- Сессии шифруются (§6.3): SESSION_ENCRYPT=true в .env.example и .env приложения (в шаблоне скелета false), по умолчанию true и в config/session.php; тест: config('session.encrypt') === true, а после GET ссылки сброса пароля с токеном в строке sessions (драйвер database; phpunit.xml ставит array — тест переключает драйвер сам) base64_decode(payload) не содержит токена (столбец хранится в base64, поиск без декодирования зелёный при любом значении; при SESSION_ENCRYPT=false тест падает)

**Проверки:**

- Feature: вход/ошибка/throttle, регистрация без прав, сброс, logout
- тест изоляции профиля двух клиентов

**Ограничения:**

- MFA и подтверждение почты не добавлять

**Неизвестные параметры:** [D-15](decisions.md#d-15), [D-29](decisions.md#d-29)

**Трассировка:** old_T: T-013, T-017; R: R-010, R-011, R-012; Q: Q-14-01, Q-14-02, Q-20-01; AC: AC-14-01, AC-14-02, AC-14-03, AC-20-03, AC-20-04; audit: F-10, F-11, F-13

## W-08

**Админка: оболочка, общий редактор, услуги и страницы** · L — локально · срез S1 · зависит от: W-04, W-07

**Результат.** Оболочка админки в оформлении прототипа (без переключателя ролей), общий Livewire-компонент редактора контента (поля LT/EN/RU, SEO, публикация по языкам, конфликт ревизии), редакторы услуг и текстов страниц (page_text), медиабиблиотека, экран журнала (вкладка audit: публикации; события заказов и оплаты добавляют W-13, W-16 и W-28); заготовка Overview. Остальные типы — W-26.

**Входы:** `docs/customer-prototype/audit-20261016/screens-and-scenarios.md#администрирование`; `docs/customer-prototype/audit-20261016/forms.md (content-edit-form, product-edit-form, category-form)`; `docs/planning/edition-2/01-scope.md#14-админка`

**Затрагивает:** `aiqme-site/app/Livewire/Admin/`; `aiqme-site/resources/views/admin/`; `aiqme-site/app/Domain/Content/ContentTypes.php`; `aiqme-site/app/Domain/Content/ (проверка типов медиа)`

**Критерии завершения:**

- Услуги и тексты страниц создаются, редактируются, публикуются и снимаются с публикации по языкам через общий компонент
- Конфликт ревизии показывает сообщение, введённый текст не теряется
- Админка без overflow на 390 px; в консоли нет нарушений CSP
- Лимит загрузки медиа — значение конфигурации (по умолчанию 20 МБ), заданная и в livewire.temporary_file_upload.rules (§6.4); медиа — только JPEG, PNG, WebP и MP4 по сигнатуре, SVG не принимается: проверка — в одном классе aiqme-site/app/Domain/Content/ (не только в правилах Livewire), его же вызывает бот W-19 (тест); имя файла медиа назначает сервер — UUID с расширением по сигнатуре (.jpg/.png/.webp/.mp4), исходные имя и расширение не используются (тест: PNG с именем x.html сохраняется как .png, §6.4)
- Любой адрес админки и действие без входа администратора (гость и клиент) — 404; по §4.6 проверяются только вкладки W-08 (оболочка, overview-заготовка, services, content, audit), остальные — в своих задачах, вся таблица — в W-21
- Поля и проверки типов контента описаны в одном месте — Domain/Content/ContentTypes (общий источник редактора и Telegram-бота W-19); W-08 регистрирует там свои типы, W-26 добавляет остальные

**Проверки:**

- Livewire-тесты редакторов
- браузерная проверка админки 390/1440 (вход — администратор bin/browser-check, W-03, через форму W-07)

**Ограничения:**

- Вкладки Permissions, Telegram-имитатор и CSV не создавать

**Неизвестные параметры:** [D-29](decisions.md#d-29)

**Трассировка:** old_T: T-025, T-026; R: R-017, R-018; Q: Q-02-05, Q-15-01, Q-15-02; audit: F-28, F-29, F-30, F-43, F-44

## W-26

**Админка: остальные редакторы контента** · L — локально · срез S1 · зависит от: W-08

**Результат.** Редакторы проектов, статей/новостей, обучения, товаров, брендов, категорий, FAQ, текстов интерфейса и переводов на общем компоненте W-08.

**Входы:** `docs/customer-prototype/audit-20261016/forms.md (content-edit-form, product-edit-form, category-form)`; `docs/planning/edition-2/01-scope.md#14-админка`; `docs/planning/edition-2/04-content-seo.md#41-модель-контента`

**Затрагивает:** `aiqme-site/app/Livewire/Admin/`; `aiqme-site/resources/views/admin/`; `aiqme-site/app/Domain/Content/ContentTypes.php (регистрация своих типов)`

**Критерии завершения:**

- Каждый тип контента §4.1 создаётся, редактируется, публикуется и снимается с публикации по языкам
- Редакторы не дублируют логику общего компонента; поля и проверки типов зарегистрированы в Domain/Content/ContentTypes (W-08); права — только администратор
- Опубликованная новость появляется в списке новостей, а её карточка открывается по адресу библиотеки (§4.1); /naujienos/<slug>/ и /en/news/<slug>/ — 404
- Правка текста интерфейса (ui_text) в редакторе видна в шапке или подвале сайта без изменения кода (тест; загрузчик — W-04)

**Проверки:**

- Livewire-тесты редакторов
- браузерная проверка админки 390/1440

**Ограничения:**

- Вкладки Permissions, Telegram-имитатор и CSV не создавать

**Неизвестные параметры:** нет

**Трассировка:** old_T: T-025, T-026; R: R-017, R-018, R-025; Q: Q-15-02; audit: F-28, F-29, F-30, F-43, F-44

## W-09

**Формы обращений, обучение, письма и outbox** · L — локально · срез S2 · зависит от: W-04, W-05, W-06, W-08

**Результат.** Серверная обработка контактной формы (несколько вложений, приватное хранение), регистрации на обучение, предзаполнения из калькулятора и товара по контракту §1.2 (GET service, product, message); honeypot и throttle; письмо на info@aiqme.com через очередь; таблица integration_events с доставкой по каналам (mail_status и sheets_status, §2.5; канал sheets создаётся pending и ждёт W-18) и повтор недоставленных каналов; список заявок в админке.

**Входы:** `docs/customer-prototype/audit-20261016/forms.md (contact-form, training-form)`; `legacy-landing/design-previews/animation-v2/shared/lead.js`; `docs/planning/edition-2/05-integrations.md`; `docs/planning/edition-2/08-operations.md (§8.4)`; `docs/customer-prototype/evidence/audit-20261016/states-followup/ (training-modal, training-confirmation, contact-confirmation — тексты по rules)`

**Затрагивает:** `aiqme-site/app/Domain/Forms/`; `aiqme-site/app/Domain/Integrations/IntegrationEvents.php`; `aiqme-site/resources/views/public/contact*`; `aiqme-site/app/Livewire/Admin/Requests*`; `aiqme-site/routes/console.php (повтор каналов)`; `aiqme-site/resources/views/public/training* (модалка регистрации)`; `aiqme-site/tests/browser/`

**Критерии завершения:**

- Валидная форма создаёт запись, событие и письмо в Mailpit; невалидная — ничего
- Сбой почты не отменяет заявку и виден в админке
- Тексты успеха описывают фактический результат (без «демо»)
- Вложения формы — непрозрачные приватные файлы по §6.4: размер, число, расширение; PDF/JPEG/PNG — по сигнатуре; STL/STEP/OBJ не разбираются и выдаются только как attachment с nosniff
- Предзаполнение по контракту §1.2: GET service, product, message (≤2000 символов; длиннее — обрезается до 2000, а не 4xx) экранируются, неизвестный slug игнорируется, файлы не передаются (тест)
- Команда повтора недоставленных каналов integration_events зарегистрирована в планировщике каждые 5 минут (тест расписания) и берёт каналы pending/failed старше 5 минут и sending, захваченные больше 5 минут назад; задание канала атомарно захватывает его одним UPDATE (status = sending и время захвата, только из pending/failed или зависшего sending, §2.7), при 0 строк выходит, отправляет вне транзакции и ставит sent/failed (тест: два задания одного события — одно письмо; зависший sending подбирается); канал sheets до W-18 не обрабатывается и не помечается failed
- Модалка регистрации на обучение на странице списка обучения и на странице услуги обучения (кнопки компонента W-05/W-06; поля forms.md training-form; Escape и возврат фокуса — компонент модального окна W-03) отправляет форму; браузерная проверка на фабричном обучении (W-06) на обеих страницах

**Проверки:**

- Feature-тесты форм, вложений, лимитов, honeypot
- тест: отказ mail-транспорта → заявка сохранена, событие в повторе; повтор канала mail не трогает канал sheets

**Ограничения:**

- Писем клиенту не отправлять
- Вложения не прикладывать к письму

**Неизвестные параметры:** [D-15](decisions.md#d-15)

**Трассировка:** old_T: T-016, T-017, T-012; R: R-007, R-008, R-023; Q: Q-01-03, Q-02-01, Q-10-02, Q-16-01; AC: AC-16-01, AC-16-02, AC-16-05; audit: F-07, F-08, F-09

## W-10

**Ядро калькулятора: JS-модуль и тесты** · L — локально · срез S2 · зависит от: W-03

**Результат.** JS-модуль ядра на основе calculator.js без привязки к DOM: собственный разбор binary/ASCII STL из ArrayBuffer (без STLLoader и пакета three; координаты приводятся к float32 — Math.fround, как в binary STL), лимиты, объём, габариты, единицы, масштаб, оценка по §3.1 с округлением до центов, новая проверка замкнутости (каждое ребро встречается ровно дважды) и габарита оборудования (одно число из конфигурации, по умолчанию 250 мм, D-24; каждый размер после единиц и масштаба; только предупреждение); Node-тесты на корпусе фикстур. Интерфейс — W-27.

**Входы:** `legacy-landing/site/assets/calculator.js`; `legacy-landing/site/assets/vendor/stl-loader.js`; `legacy-landing/tools/stl/`; `docs/customer-prototype/audit-20261016/screens-and-scenarios.md#калькулятор-и-оформление-производственного-заказа`; `legacy-landing/scripts/check_stl_pricing.py (как образец проверок)`

**Затрагивает:** `aiqme-site/resources/js/calculator/core/`; `aiqme-site/tests/js/`; `aiqme-site/tests/fixtures/stl/`; `aiqme-site/bin/check (запуск Node-тестов)`

**Критерии завершения:**

- Контрольный пример 1 см³ PLA, 100 %, qty 1, 0,10 EUR/г, 5 EUR → 1,24 г, 512 центов (5,12 EUR) воспроизводится; масса и центы — по формулам §3.1 (заполнение 0,2 + 0,8 × infill/100, floor(x + 0,5) в double): 20 % → 0,4464 г, qty 3 → 3,72 г и строка 37 центов; объём и объём в см³ с единицами и масштабом — по формуле §3.1 (первая вершина как начало, k × k × k / 1000), порядок операций тот же, что в PHP (W-11)
- Открытый тетраэдр даёт явное предупреждение «незамкнутая сетка» (новая проверка рёбер, в calculator.js её нет)
- Пользовательская плотность не принимается: плотность берётся из материала в настройках
- Мелкие фикстуры корпуса §9.2 (до 100 КБ) — в tests/fixtures/stl/, среди них ASCII и binary куб 10 мм (1 см³ — контрольный пример done[0], его использует W-27); граничные и крупные (binary 500 000 и 500 001 треугольников; файлы ровно 26 214 400 байт и на 1 байт больше — §9.2) генерируются тестом во временный каталог; корпус пригоден для паритета W-11
- Разбор STL — свой код ядра: binary читается DataView.getFloat32, ASCII — число только по общему с PHP шаблону ^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$ (иное, например 0x10, — отказ), затем Number() и Math.fround; -0 нормализуется в 0 до построения ключей рёбер (фикстуры с -0 и hex-токеном); пакет three, STLLoader и бандл legacy-landing/site/assets/vendor/stl-loader.js в приложение не попадают (legacy-landing/ — только образец)
- bin/check запускает Node-тесты ядра встроенным раннером (node --test aiqme-site/tests/js/, Node 22, ESM) без новых npm-пакетов; без node bin/check падает, а не пропускает; node находится явно (nvm, §9.1), путь экспортируется в переменную окружения NODE_BINARY для PHPUnit

**Проверки:**

- Node-тесты ядра (корпус §9.2)

**Ограничения:**

- Только STL
- Не заменять формулу объёма демо-формулой габаритов
- Цена — предварительная оценка
- Пакет three и STLLoader не добавлять: разбор — свой код ядра

**Неизвестные параметры:** [D-08](decisions.md#d-08), [D-09](decisions.md#d-09), [D-24](decisions.md#d-24)

**Трассировка:** old_T: T-018, T-019; R: R-013; Q: Q-02-03, Q-03-03, Q-11-01, Q-11-04, Q-11-05; AC: AC-11-01, AC-11-02, AC-11-04; audit: F-48

## W-27

**Калькулятор: 5-шаговый интерфейс** · L — локально · срез S2 · зависит от: W-10, W-03

**Результат.** 5-шаговый интерфейс прототипа на ядре W-10: несколько STL, EUR, единицы, масштаб с подтверждением, количество, материал, заполнение, пожелания без влияния на цену (шаг 2 — только FDM без выбора технологии, D-37; шаг 4 и сводка — без VAT и цены за единицу, D-38), место просмотра модели (сам просмотр на Canvas 2D — W-31), ветка специалиста — ссылка в контактную форму по контракту предзаполнения §1.2 (message с описанием моделей и параметров).

**Входы:** `aiqme-site/resources/js/calculator/core/`; `legacy-landing/site/assets/vendor/stl-loader.js`; `legacy-landing/tools/stl/`; `docs/customer-prototype/audit-20261016/screens-and-scenarios.md#калькулятор-и-оформление-производственного-заказа`; `legacy-landing/scripts/check_stl_pricing.py (как образец проверок)`; `docs/customer-prototype/evidence/audit-20261016/states-followup/ (quote-settings, quote-review, quote-risk, quote-price, quote-checkout, scale-confirm — тексты EN по rules)`; `docs/customer-prototype/source-backup-20261016/AIQME-site/dist/app.js (только чтение как текст: пары t('LT','EN'); не запускать)`

**Затрагивает:** `aiqme-site/resources/js/calculator/`; `aiqme-site/resources/views/public/calculator*`; `aiqme-site/config/aiqme.php (раздел calculator: значения D-09, D-24, лимиты §6.4)`; `aiqme-site/app/Http/Controllers/Public/Calculator*`

**Критерии завершения:**

- Расчёт и отправка не зависят от просмотра модели: место просмотра есть, сам просмотр добавляет W-31; без него и при недоступном canvas расчёт и отправка работают
- Поздний результат анализа старого файла не перезаписывает новый
- Файл 25 МиБ не блокирует интерфейс без индикации; при заметной блокировке разбор вынесен в Web Worker
- Оценка на экране — те же центы, что вернуло ядро, в формате EUR с 2 знаками
- Подпись лимита — из конфигурации (25 МиБ, §6.4), без «in this demo»; демо-надписей калькулятора и кнопок файлов-примеров нет (D-21)
- До W-11 тарифы и габарит оборудования — значения по умолчанию D-09 и D-24 из конфигурации, переданные в JS через data-атрибуты; W-11 заменяет их настройками админки без изменения интерфейса
- Интерфейс добавляет модель и из ArrayBuffer (та же функция, что для выбранного файла) — точка входа для повторного заказа и «пересчитать» W-15
- Шаг 5 — форма контактов и доставки без отправки: до W-12 кнопка отправки недоступна с нейтральной подписью, имитации успеха нет (D-21); отправку добавляет W-12
- Шаг 2 по §1.2 и D-37: кнопок технологии нет; материал — список из настроек (D-09/W-11); заполнение — список прототипа 20/40/60/100 %, к которому добавляется значение по умолчанию из настроек, если его там нет, выбрано значение по умолчанию, подпись «· rekomenduojama» · «· recommended» — только у него; цвет, качество, слой, обработка и «Requested schedule» («Standard» / «Priority» без сроков в днях) — пожелания с вариантами FDM прототипа, подписанные как не влияющие на оценку
- Шаг 4 и сводка по §1.2 и D-38: таблица со столбцами «Part», «Quantity», «Estimate» (итог строки в центах ядра, без цены за единицу); сводка — строки моделей, плата за заказ (и за файлы, если > 0), «Estimated total» и подпись «предварительная оценка; доставку и окончательную цену подтверждает администратор»; в таблице шага 4 и сводке нет строк скидки, доставки и VAT и подписей «excl. VAT» · «be PVM»; поле «PVM kodas (neprivaloma)» · «VAT number (optional)» шага 5 сохраняется (§1.2, §2.5 customer_profiles); итог равен сумме показанных строк
- Ветка специалиста: message собирается по моделям и обрезается до 2000 символов на стороне калькулятора с пометкой о числе неуказанных моделей (нейтральная надпись — в progress.md); тест на 10 моделях
- Шаги 1 и 3 по §1.2: OBJ и STEP — сообщение со ссылкой на контактную форму, без разбора; шаг 3 показывает предупреждения ядра (незамкнутая сетка, габарит) и не блокирует переход и отправку; флажка «Try an issue scenario…», фраз «Payment is blocked…», «Resolve the review warnings first…» и «…watertightness and actual manufacturability are not automatically analysed» нет; материал и заполнение общие для заявки, количество, единицы и масштаб — по модели, как в прототипе

**Проверки:**

- браузерная проверка 5 шагов, ошибок формата и лимитов на 390 и 1440; на шагах 2–5 нет кнопок технологии; в таблице шага 4 и сводке нет строк скидки, доставки, VAT и подписей «excl. VAT»/«be PVM» (поле VAT-кода покупателя шага 5 есть)
- браузерная проверка контрольного примера: куб 10 мм (1 см³) из tests/fixtures/stl/ (W-10), PLA, заполнение 100 %, qty 1, значения D-09 → на шаге 4 итог «5,12» · «5.12», итог равен сумме показанных строк (done[3], done[9])
- тест (Node или браузер): модель из ArrayBuffer даёт тот же результат, что из File (done[6]); при выборе второго файла до окончания разбора первого на экране остаётся результат второго (done[1])
- браузерная проверка шага 3: флажка сценария нет; открытый тетраэдр — предупреждение «незамкнутая сетка», переход дальше доступен (done[11])

**Ограничения:**

- Только STL
- Цена — предварительная оценка
- Поле пользовательской плотности не выводить
- Новых npm-пакетов не добавлять (в том числе three): разбор STL — ядро W-10, просмотрщик — W-31; legacy-landing/tools/stl/ и vendor/stl-loader.js — только образец

**Неизвестные параметры:** [D-08](decisions.md#d-08), [D-09](decisions.md#d-09), [D-24](decisions.md#d-24), [D-37](decisions.md#d-37), [D-38](decisions.md#d-38)

**Трассировка:** old_T: T-018, T-019; R: R-013; Q: Q-07-01, Q-10-03; AC: AC-11-10, AC-10-11; audit: F-16, F-17, F-18

## W-31

**Калькулятор: просмотр модели Canvas 2D** · L — локально · срез S2 · зависит от: W-27

**Результат.** Просмотр модели на Canvas 2D как в прототипе: вращение мышью и стрелками (шаг ±0,15 рад), масштаб кнопками +/- и колесом в пределах 0,3–3, сброс вида; рисуется не больше ~8 500 треугольников (равномерное прореживание) с сортировкой по глубине; без WebGL и пакета three. W-11 и W-12 от неё не зависят.

**Входы:** `aiqme-site/resources/js/calculator/`; `docs/customer-prototype/audit-20261016/features.md (F-16, F-48)`; `docs/customer-prototype/evidence/audit-20261016/more-states/viewer-keyboard/dom.html (тексты по rules)`; `docs/customer-prototype/source-backup-20261016/AIQME-site/dist/app.js (только чтение как текст: initViewer/drawViewer — параметры поведения; код не копируется и не запускается)`

**Затрагивает:** `aiqme-site/resources/js/calculator/viewer/`; `aiqme-site/resources/js/calculator/ (только передача сетки разбора в просмотр)`; `aiqme-site/resources/views/public/calculator* (место просмотра)`; `aiqme-site/lang/`

**Критерии завершения:**

- Просмотр вращается, масштабируется и сбрасывается мышью и клавиатурой (F-16, F-48), параметры — как в прототипе (result)
- Модель предельного размера (500 000 треугольников, генерируется тестом во временный каталог) рисуется прореженной без заметной блокировки вкладки; время кадра записано в progress.md
- Если canvas недоступен, расчёт и отправка работают (тест)

**Проверки:**

- браузерная проверка на кубе из tests/fixtures/stl/ на 390 и 1440: после загрузки на canvas есть пиксели модели (не только фон), после ArrowRight снимок toDataURL меняется, после сброса совпадает с исходным

**Ограничения:**

- Новых npm-пакетов не добавлять (в том числе three)
- Код app.js не копировать: только параметры поведения (D-19)

**Неизвестные параметры:** нет

**Трассировка:** old_T: T-018, T-019; R: R-013; audit: F-16, F-48

## W-11

**Серверный анализ STL и настройки калькулятора** · L — локально · срез S2 · зависит от: W-10, W-27, W-08

**Результат.** StlAnalyzer на PHP (binary/ASCII, координаты приводятся к float32 так же, как в JS: binary — unpack('g'), ASCII — unpack('g', pack('g', $x)), лимиты, объём, габариты, проверка рёбер, предупреждения) с паритетом с JS; EstimateService с округлением до центов по §3.1, одной общей ценой за грамм (как data-price-per-gram в calculator.js), платой за заказ и за файл и снимком настроек; экран настроек калькулятора в админке; настройки отдаются в JS.

**Входы:** `aiqme-site/resources/js/calculator/`; `aiqme-site/tests/fixtures/stl/`; `docs/planning/edition-2/03-orders-payments.md#31-от-stl-до-заказа`

**Затрагивает:** `aiqme-site/app/Domain/Pricing/`; `aiqme-site/app/Livewire/Admin/CalculatorSettings*`; `aiqme-site/tests/js/ (скрипт выдачи результатов ядра для паритета)`; `aiqme-site/resources/views/public/calculator*`; `aiqme-site/app/Http/Controllers/Public/Calculator*`; `aiqme-site/database/migrations/ (settings и начальные значения калькулятора)`

**Критерии завершения:**

- Паритет JS ↔ PHP на всём корпусе (координаты в обоих ядрах приведены к float32, порядок суммирования треугольников одинаковый): объём и масса — допуск 1e-9 относительный; оценка в центах и предупреждения — точное совпадение; PHPUnit-тест паритета при каждом запуске вызывает Node-скрипт ядра из tests/js/ и сравнивает его JSON по корпусу (отслеживаемые эталоны, сгенерированные PHP, не используются; node — из переменной окружения NODE_BINARY, которую выставляет bin/check; без неё или без исполняемого node тест проваливается с понятным сообщением, а не пропускается); ASCII-числа — тот же шаблон, что в W-10, -0 → 0 перед ключами рёбер; оценка — floor(x + 0,5) от mass × rate × 100 в double (§3.1), bcmath не используется; корпус прогоняется с матрицей параметров: единицы mm/cm/in, масштаб 1, 37 и 1000 %, заполнение 20 и 100 %, qty 1 и 3
- Лимиты проверяются до полного разбора; тест анализатора на сгенерированном binary-файле 500 000 треугольников (и границы §9.2: 500 001 треугольник, 26 214 400 байт и на 1 байт больше) выполняется в подпроцессе php -d memory_limit=512M (значение §6.4 и W-20) и проходит; пиковая память записана в progress.md
- Тарифы и габарит оборудования (по умолчанию 250 мм, D-24) помечены в админке как «не подтверждено», пока не изменены администратором; заполнение по умолчанию — в %, коэффициенты 0,2/0,8 — константы ядра (§3.1)
- Feature-тест: после изменения в CalculatorSettings цены за грамм, плат, плотности материала, заполнения по умолчанию и габарита страницы /en/quote/ и /lt/kalkuliatorius/ выводят новые значения в data-атрибутах, а EstimateService считает по тем же значениям; после W-11 страница калькулятора эти значения из config не читает; лимиты файлов (§6.4) остаются в config/aiqme.php и в админке не редактируются (в прототипе их нет)
- Начальные значения настроек калькулятора (D-09, D-24, заполнение по умолчанию 20 %) записываются в таблицу settings (её создаёт W-11: ключ → JSON-значение, §2.5; W-17 и W-18 добавляют свои ключи) миграцией данных W-11, только если их там нет; тест: на чистой БД после migrate страница калькулятора выводит значения D-09 в data-атрибутах (рабочая БД и aiqme_browser без отдельного сидера)

**Проверки:**

- Unit-тесты анализатора
- тест паритета
- Livewire-тест настроек
- Feature-тест data-атрибутов страницы калькулятора после изменения настроек (done[3])

**Ограничения:**

- Демо-цены технологий и скидки прототипа не переносить

**Неизвестные параметры:** [D-08](decisions.md#d-08), [D-09](decisions.md#d-09), [D-24](decisions.md#d-24)

**Трассировка:** old_T: T-018, T-019; R: R-013; Q: Q-11-02, Q-11-05, Q-17-02, Q-22-04; AC: AC-11-05, AC-11-06, AC-11-07, AC-22-03

## W-12

**Отправка заявки на изготовление** · L — локально · срез S2 · зависит от: W-11, W-27, W-09, W-07

**Результат.** OrderSubmission: multipart-приём, StlFile в приватном хранилище, корзина Lunar → заказ через валидатор (гость и клиент), порядковый номер, токен отправки, гостевой токен, страница подтверждения (защищённую выдачу STL делает W-32) и гостевая страница-заготовка (/lt/uzsakymas/… · /en/order/…), письмо на info@aiqme.com, события для Sheets; идемпотентная команда aiqme:bootstrap — базовые данные Lunar (канал, языки lt/en, валюта EUR по умолчанию, группа клиентов, налоговая зона и класс по умолчанию без ставок — §2.2).

**Входы:** `aiqme-site/experiments/lunar-aiqme/app/app/Services/BasketService.php`; `aiqme-site/experiments/lunar-aiqme/app/app/Services/RequestCartValidator.php`; `aiqme-site/experiments/lunar-aiqme/app/app/Models/StlPart.php`; `aiqme-site/experiments/orders-comparison/shared/Tests/`; `docs/planning/edition-2/03-orders-payments.md`; `docs/planning/edition-2/06-security-files.md (§6.1, §6.2, §6.4)`

**Затрагивает:** `aiqme-site/app/Domain/Orders/`; `aiqme-site/app/Models/StlFile.php`; `aiqme-site/app/Models/StlPart.php`; `aiqme-site/app/Http/Controllers/Public/OrderRequest*`; `aiqme-site/app/Console/Commands/`; `aiqme-site/app/Models/OrderCharge.php`; `aiqme-site/resources/js/calculator/ (отправка заявки: FormData по §3.1, токен отправки — UUID формы)`; `aiqme-site/routes/`; `aiqme-site/database/migrations/ (stl_files, stl_parts, order_meta, order_charges, counters)`; `aiqme-site/bin/browser-check (подготовка aiqme_browser: aiqme:bootstrap)`

**Критерии завершения:**

- Перенесены и адаптированы тесты отправки и изоляции из эксперимента
- Двойная отправка и повтор с тем же токеном отправки — один заказ (уникальный order_meta.submission_token, отпечаток данных submission_digest; другой набор данных с тем же токеном — отказ, §2.5, §3.1); для гостевого заказа повтор перевыпускает гостевой токен и показывает новую ссылку один раз, прежняя перестаёт работать (тест: после повтора старая ссылка — 404, новая открывает заказ); номер выдаётся атомарным UPDATE … RETURNING счётчика (таблица counters, §2.5, §3.1), параллельные отправки — разные номера
- Сбой после записи файла не оставляет сирот
- Цена строки — серверная оценка в центах по §3.1; плата за заказ и за файлы — служебная строка OrderCharge вида fee (§3.1; при сумме 0 не создаётся, лимит 10 строк валидатора — только по StlPart); клиентская сумма игнорируется; строка StlPart в корзине и заказе Lunar — quantity = 1, количество — в parameters.quantity (проверка 1–100 валидатора — по нему), цена строки = итог строки (§3.1; тест qty 3 → 37 центов); сумма заказа Lunar (сумма всех строк) равна сумме, показанной на экране
- Профиль вошедшего клиента подставляется в форму заявки
- Локаль заявки (lt/en — язык страницы отправки) сохраняется в order_meta: по ней W-17 выбирает язык счёта (§2.5, §3.5; тест)
- aiqme:bootstrap идемпотентна (повторный запуск ничего не меняет), используется в тестах и при развёртывании §8.3; ставок налога Lunar нет — сумма заказа равна сумме строк; фикстуры эксперимента (DatabaseSeeder) не копируются
- Honeypot (ложного успеха с номером нет) и ограничение частоты отправки заявок по IP (настройка config/aiqme.php из .env, по умолчанию 10 в час): превышение — 429 без записи файлов, заказа, письма и события (тест)
- Событие §5.1 «Заявка на изготовление отправлена» пишется в integration_events в той же транзакции, что и заказ: канал mail — письмо на info@aiqme.com, канал sheets — pending (тест)
- Файл сверх лимита PHP (upload_max_filesize/post_max_size) даёт понятное сообщение, а не пустую ошибку (тест)
- bin/browser-check после migrate:fresh базы aiqme_browser запускает aiqme:bootstrap (канал и валюта Lunar), иначе браузерный сценарий заявки не создаёт заказ

**Проверки:**

- Feature-тесты §9.2 «Заявка» и «Права» — для объектов, существующих на момент W-12 (заказ, гостевой токен; STL — W-32); страница оплаты — W-16, счёт — W-17
- браузерный сквозной сценарий гостя до подтверждения

**Ограничения:**

- Не переносить StlIntake::unit и LocalTestAdapter
- Lunar vendor не редактировать

**Неизвестные параметры:** [D-10](decisions.md#d-10)

**Трассировка:** old_T: T-015, T-020, T-021; R: R-014; Q: Q-04-01, Q-07-01, Q-11-06, Q-18-01, Q-18-02, Q-19-03, Q-20-02; AC: AC-12-01, AC-12-04, AC-14-05, AC-14-06, AC-18-03, AC-19-05, AC-20-06; audit: F-20

## W-32

**Защищённая выдача STL и проверка прав на заказ** · L — локально · срез S2 · зависит от: W-12

**Результат.** Одна проверка прав на заказ (владелец, администратор; право гостя в сессии добавляет W-15) и защищённая выдача STL по §6.4 (маршрут и контроллер), которые переиспользуют W-14, W-15, W-16 и W-17.

**Входы:** `docs/planning/edition-2/06-security-files.md (§6.1, §6.4)`; `aiqme-site/experiments/lunar-aiqme/app/app/Services/OrderWorkflow.php (authorizeView — только как образец: сравнение user_id без проверки входа не переносится)`

**Затрагивает:** `aiqme-site/app/Http/Controllers/StlDownload* (защищённая выдача STL)`; `aiqme-site/app/Domain/Orders/ (проверка прав на заказ)`; `aiqme-site/routes/`

**Критерии завершения:**

- Защищённая выдача STL по §6.4: Content-Disposition: attachment с экранированным именем, nosniff, Cache-Control: private, no-store, проверка прав на каждый запрос, включая HEAD и Range; права проверяются в сервисе (§2.4); отдельная проверка владельца, если её сделала W-12, заменена этой
- Владелец заказа — только вошедший клиент (auth()->check()) при непустом user_id заказа, равном его id (§6.1): у гостевого заказа (user_id = null) владельца нет, доступ гостя — только право в сессии (W-15); сравнение order.user_id === auth()->id() эксперимента без этой проверки не переносится (для гостевого заказа оно даёт null === null для любого посетителя)
- Тесты: клиент B и посетитель — 404 на STL заказа клиента A; посетитель без входа и клиент B — 404 на STL гостевого заказа (user_id = null); администратор скачивает; всё — и для HEAD/Range
- Маршрут шаблона Laravel `GET|PUT storage/{path}` (serve локального диска, подписанные адреса) отключён (`'serve' => false` у дисков local и private): выдача файлов — только контроллером §6.4; тест — 404 на /storage/… (замечание W-01, progress.md)

**Проверки:**

- Feature-тесты §9.2 «Права» для STL, в том числе на гостевом заказе

**Ограничения:**

- Подписанные публичные ссылки не использовать (§6.4)

**Неизвестные параметры:** нет

**Трассировка:** old_T: T-014; R: R-014

## W-13

**Правила заказа: цена, производство, отмена** · L — локально · срез S2 · зависит от: W-12, W-32

**Результат.** OrderWorkflow: подтверждение и изменение цены с основанием (цена — по всем строкам, включая служебные OrderCharge, §3.1), строка доставки OrderCharge вида delivery (OrderLine заказа внутри транзакции confirmPrice; fee может быть подтверждена с ценой 0, §3.1), стадии производства, трек; платёжные запросы без Stripe — таблица и модель payment_requests по §2.5 (все поля, включая public_id, stripe_intent_id и needs_review; заряды хранятся в транзакциях Lunar — §3.3 «Запись»), requestPayment создаёт запрос сразу в pending без intent (как в эксперименте), отмена запроса без intent (pending → void); блокировка изменения цены при открытом запросе; отмена заказа, переводящая открытые запросы pending без intent в void в той же транзакции (запросы с intent и в creating — W-16, §3.4); сообщения администратора и ответы клиента, журнал.

**Входы:** `aiqme-site/experiments/lunar-aiqme/app/app/Services/OrderWorkflow.php`; `aiqme-site/experiments/orders-comparison/evidence/trace-parity.json`; `docs/planning/edition-2/02-architecture.md#26-что-переносится-из-эксперимента-и-с-какими-исправлениями`; `docs/planning/edition-2/03-orders-payments.md#33-платёжные-запросы-и-stripe`; `docs/planning/edition-2/03-orders-payments.md#34-производство-и-передача`

**Затрагивает:** `aiqme-site/app/Domain/Orders/`; `aiqme-site/app/Domain/Audit/`; `aiqme-site/app/Domain/Payments/ (модель PaymentRequest)`; `aiqme-site/database/migrations/`

**Критерии завершения:**

- Перенесены тесты подтверждения, ревизий, производства без оплаты, блокировки после старта, отмены без возврата; «оплачено» в них создаёт только тестовая фабрика транзакции Lunar capture в tests/ (вместо receipt() и LocalTestAdapter эксперимента); рабочего метода записи оплаты в W-13 нет (constraints); строка delivery одна на заказ: ревизия цены меняет её цену, а не добавляет вторую (тест ревизии заказа с доставкой, §3.1)
- Тесты платёжных запросов без Stripe: requestPayment только после подтверждения цены и не для отменённого заказа, сумма ≥ 50 центов и ≤ остатка минус открытые запросы, остаток после запроса 0 или ≥ 50 центов (минимум Stripe для EUR, §3.3; тесты: 49 центов — отказ, запрос, оставляющий 30 центов, — отказ); ревизия цены, оставляющая остаток от 1 до 49 центов, отклоняется с сообщением (§3.2, тест); открытый запрос блокирует изменение цены (открытые — creating, pending и cancelling, §2.5; эксперимент считает только pending — так не переносить); отмена запроса без intent — pending → void без вызова Stripe; отмена заказа переводит открытые запросы pending без intent в void и не создаёт возврат
- Переходы только вперёд; shipped требует трека; самовывоз ready → completed
- Правила и права проверяются в OrderWorkflow, а не в маршруте или компоненте: прямой вызов сервиса не администратором отклоняется (тест); консольных команд заказа нет
- События §5.1 этой задачи (цена подтверждена/изменена, платёжный запрос создан/отменён, стадия, трек, отмена) пишутся в integration_events в той же транзакции, что и изменение (тест)
- Сообщения по заказу (§1.3, order_messages §2.5): OrderWorkflow::postAdminMessage (только администратор) и recordCustomerReply(order, kind ∈ agree | question | cancel_request, actor) записывают сообщение и журнал; ответ клиента ничего не меняет (тест: «прошу отменить» не отменяет заказ и запросы); автор ответа — владелец заказа по проверке прав W-32 в сервисе, иначе отказ без записи (тест); ветку гостя с правом в сессии подключает W-15 (её тест — W-15)

**Проверки:**

- Feature-тесты §9.2 «Цена и производство»

**Ограничения:**

- Автоматических напоминаний и закрытия нет
- Частичного выполнения нет
- recordVerifiedReceipt и другие публичные методы записи оплаты в рабочий код не переносить: оплату записывает только внутренний сервис записи capture по проверенным данным Stripe (создаёт W-16, переиспользует W-28; §2.6), без публичного метода, маршрута и команды

**Неизвестные параметры:** [D-06](decisions.md#d-06), [D-07](decisions.md#d-07)

**Трассировка:** old_T: T-021; R: R-014, R-017; Q: Q-02-04, Q-06-03, Q-12-01, Q-12-02, Q-12-03, Q-12-04, Q-12-05; AC: AC-12-02, AC-12-03, AC-12-06, AC-12-08, AC-12-09, AC-12-12; audit: F-24, F-25, F-26, F-27

## W-14

**Админка: обзор, заказы, клиенты** · L — локально · срез S2 · зависит от: W-13, W-32, W-08, W-09

**Результат.** Overview с метриками и предупреждением о неудачных фоновых заданиях (предупреждения «платежи на сверку» и «незаполненные реквизиты» добавляют W-28 и W-17); список и поиск заказов; карточка заказа со всеми действиями §1.4 (запрос оплаты и отмена запроса без intent — через OrderWorkflow W-13; страница оплаты, Stripe и отмена запроса с intent — W-16); лист задания Bambu; ссылка клиенту: гостю — только перевыпуск гостевой ссылки (новая показывается один раз с предупреждением, что прежняя перестаёт работать, §3.3, §6.2); ссылку страницы оплаты для зарегистрированного клиента добавляет W-16, создающая её маршрут; клиенты и карточка клиента (ссылку на Google Таблицу из настроек добавляет W-18).

**Входы:** `docs/planning/edition-2/01-scope.md#14-админка`; `docs/customer-prototype/evidence/audit-20261016/ (admin orders/overview/customers DOM)`

**Затрагивает:** `aiqme-site/app/Livewire/Admin/Orders*`; `aiqme-site/app/Livewire/Admin/Customers*`; `aiqme-site/app/Livewire/Admin/Overview*`; `aiqme-site/tests/browser/ (фабрики данных задачи: заказ с подтверждённой ценой, открытым запросом и треком)`

**Критерии завершения:**

- Все действия вызывают OrderWorkflow; в компонентах нет бизнес-правил
- Скачивание STL администратором через защищённую выдачу
- Опасные действия (отмена, изменение цены, начало производства при долге > 0 — §3.4) спрашивают подтверждение
- Перевыпуск гостевой ссылки: новая показана один раз, старая ссылка — 404 (тест)
- Действие «сообщение клиенту» и показ ответов клиента в карточке заказа — через OrderWorkflow W-13 (Livewire-тест)
- Лист задания Bambu (§3.4) показывает параметры и пожелания строк заказа и ссылки на STL только через защищённую выдачу, без пути storage; передачи на принтер нет (Livewire-тест)

**Проверки:**

- Livewire-тесты действий и прав
- браузерная проверка карточки заказа 390/1440 на фабричном заказе (tests/browser/) (вход — администратор bin/browser-check, W-03, через форму W-07)

**Ограничения:**

- Без массовых операций и CSV

**Неизвестные параметры:** [D-29](decisions.md#d-29)

**Трассировка:** old_T: T-025; R: R-017; Q: Q-15-03, Q-15-04, Q-15-06; AC: AC-15-01, AC-15-04, AC-15-15; audit: F-24, F-26, F-31

## W-15

**Кабинет клиента и гостевая страница заказа** · L — локально · срез S2 · зависит от: W-13, W-32, W-07

**Результат.** Overview, Orders, карточка заказа (стадии, оплата, долг, трек, ответы клиента, повторный заказ), Saved models (STL из заказов клиента: скачать, пересчитать, скрыть из списка), гостевая страница по токену; места для оплаты (W-16) и счетов (W-17).

**Входы:** `docs/customer-prototype/audit-20261016/screens-and-scenarios.md#вход-и-кабинет`; `docs/planning/edition-2/01-scope.md#13-вход-и-кабинет-клиента`

**Затрагивает:** `aiqme-site/resources/views/account/`; `aiqme-site/app/Http/Controllers/Account/`; `aiqme-site/resources/js/calculator/ (только загрузка ?models=)`; `aiqme-site/routes/ (ответ клиента)`; `aiqme-site/app/Domain/Orders/ (только право гостя в сессии в проверке прав W-32)`; `aiqme-site/tests/browser/ (фабрики данных задачи: заказ клиента и гостевой заказ)`

**Критерии завершения:**

- Клиент видит только свои заказы и модели; гость — только свой заказ
- Открытие гостевой страницы по верному токену записывает в сессию право на заказ (§6.2); скачивание STL гостем (выдача W-32) авторизуется этим правом (счета — W-17), токена в адресах файлов нет; гостевая страница и карточка показывают открытые запросы (сумма) без ссылки — кнопку оплаты и тест «не выводится при APP_ENV=demo» добавляет W-16, создающая маршрут оплаты; неверный гостевой токен — 404, ограничение частоты по IP: превышение — 429 (тест, §6.2); тест на гостевом заказе (user_id = null): посетитель без права, клиент B и гость с правом на другой гостевой заказ — 404 на гостевую страницу без токена и на STL, включая HEAD
- Удаление модели из Saved models скрывает её из списка и не удаляет файл заказа
- Повторный заказ и «пересчитать» открывают калькулятор с ?models=<id,…>: для вошедшего владельца файлы скачиваются через защищённую выдачу в ArrayBuffer и проходят тот же разбор ядра; оценка — по текущим тарифам; при отправке файлы уходят заново обычным multipart (новые stl_files); чужой id — пропускается без раскрытия (тест); положительный путь — браузерная проверка (checks)
- Нет overflow на 390 px
- Карточка заказа (кабинет и гостевая страница) показывает поступившие платежи и возвраты (дата, сумма) из транзакций Lunar — это «подтверждение платежа» аванса по D-04, без PDF и номера счёта (тест на тестовой фабрике capture)
- Вкладка invoices кабинета создаётся с пустым состоянием, её строка §4.6 проверяется Feature-тестом; список счетов наполняет W-17
- Карточка заказа (кабинет и гостевая страница) показывает сообщение администратора и три кнопки ответа клиента (§1.3: согласен с изменением / нужен вопрос / прошу отменить) через OrderWorkflow W-13; POST ответа — только владелец или гость с правом на этот заказ в сессии, с CSRF; клиент B, посетитель и гость с правом на другой заказ получают 404, запись не создаётся (тест)

**Проверки:**

- Feature-тесты изоляции (клиент A/B/гость, прямые URL, HEAD/Range; в том числе на гостевом заказе с user_id = null, §6.1)
- браузерная проверка кабинета и гостевой страницы на фабричных заказах (tests/browser/)
- браузерная проверка повторного заказа и «пересчитать» (done[3]): фабричный заказ клиента с кубом 10 мм из tests/fixtures/stl/ (W-10) → «пересчитать» в Saved models и «повторный заказ» в карточке → модель загружена в калькулятор; PLA, заполнение 100 %, qty 1, значения D-09 → на шаге 4 итог «5,12» · «5.12» → отправка создаёт новый заказ с новой строкой stl_files

**Ограничения:**

- Персональную скидку не реализовывать

**Неизвестные параметры:** [D-10](decisions.md#d-10), [D-04](decisions.md#d-04)

**Трассировка:** old_T: T-024; R: R-012; Q: Q-02-02, Q-14-03, Q-14-05, Q-14-07, Q-20-08; AC: AC-14-04, AC-14-09, AC-14-10, AC-20-01, AC-20-13; audit: F-12, F-14, F-15, F-22

## W-24

**Демо-сборка для GitHub Pages (только фронтенд)** · L — локально · срез S2 · зависит от: W-06, W-25, W-27, W-14, W-15

**Результат.** Команда aiqme:demo-export (обёртка bin/demo-build) рендерит те же Blade-шаблоны на демо-данных в статическую папку aiqme-site/demo/ для GitHub Pages (https://greatgleb.github.io/3d-fabrication-web/aiqme-site/demo/): публичные страницы LT/EN с заглушками, калькулятор в браузере, формы, вход, кабинет и админка без бэкенда; на каждой странице явная пометка «ДЕМО». Не заменяет полноценный сайт на сервере; публикация — отдельным разрешением.

**Входы:** `docs/planning/edition-2/01-scope.md#16-демо-сборка-для-github-pages`; `docs/planning/edition-2/08-operations.md#87-демо-сборка-для-github-pages-рекомендация`; `aiqme-site/tests/fixtures/prototype-routes.json (из W-02)`; `docs/planning/edition-2/04-content-seo.md#46-ожидаемые-статусы-адресов-прототипа`; `legacy-landing/scripts/build_github_pages_v2.py (как образец упаковки статического пакета)`; `legacy-landing/scripts/check_github_pages_ui.py (как образец проверок)`

**Затрагивает:** `aiqme-site/app/Console/Commands/DemoExport.php`; `aiqme-site/database/seeders/DemoSeeder.php`; `aiqme-site/resources/js/demo.js`; `aiqme-site/bin/demo-build`; `aiqme-site/demo/ (готовая сборка в Git, её раздаёт Pages)`

**Критерии завершения:**

- Сборка работает без PHP-сервера и БД у зрителя: папка открывается через python3 -m http.server под подкаталогом /3d-fabrication-web/aiqme-site/demo/, все внутренние ссылки и ресурсы учитывают этот базовый путь
- Присутствуют публичные адреса LT/EN, которые по §4.6 отвечают 200; контент — заглушки и демо-данные, без реальных данных клиентов
- На каждой HTML-странице видимая пометка «ДЕМО» на языке страницы, meta robots noindex и пояснение, что это не рабочий сайт
- Калькулятор STL полностью работает в браузере (загрузка, параметры, оценка); «отправить заявку» показывает демо-сообщение без сетевого запроса (отправку калькулятора W-12 перехватывает demo.js по data-demo-form на контейнере калькулятора, §8.7)
- Экраны входа, кабинета и админки — статические HTML-снимки по §8.7: без скрипта Livewire и атрибутов wire:*, действия показывают демо-сообщение через demo.js; разделов оплаты и счетов в демо нет независимо от порядка задач: маршруты экспорта их не содержат, их блоки в демо-режиме не выводятся (§8.7; W-21 пересобирает демо)
- Поиск в демо показывает демо-сообщение (серверного поиска нет), ?category=… отдаёт тот же статический список (§8.7)
- В выводе нет секретов, .env, персональных данных, переписки, медиа третьих лиц и материалов заказчика из docs/
- Демо-режим недоступен в production-конфигурации сервера: aiqme:demo-export рендерит экраны входа, кабинета и админки внутри процесса от имени демо-пользователей DemoSeeder (Auth::login в команде, без HTTP-маршрута — исключение rules) и отказывается работать при APP_ENV=production (до любых действий) и если после создания aiqme_demo и переключения на неё имя БД активного подключения не aiqme_demo (до DemoSeeder и Auth::login; тест на оба отказа); DemoSeeder пишет только в отдельную БД aiqme_demo dev-PostgreSQL, которую команда создаёт перед сборкой и удаляет после (не aiqme и не _test); рабочая БД не меняется

**Проверки:**

- браузерная проверка демо-папки: все страницы отвечают 200, нет JS-ошибок, нет запросов вне статических файлов того же origin
- тест калькулятора на кубе 10 мм (объём 1 см³, габариты 10×10×10), как в legacy-landing/scripts/check_stl_pricing.py
- скрипт: пометка «ДЕМО» и noindex на каждой HTML-странице
- поиск секретов, e-mail клиентов и путей docs/customer в выводе
- поиск wire: и livewire в выводе demo/ — совпадений нет

**Ограничения:**

- Не дублировать шаблоны: демо рендерится из тех же Blade-компонентов, отличаются только данные и обработчики форм
- Не заменяет полноценный сайт на сервере и не считается выполнением серверных функций
- Не публиковать: commit и push папки aiqme-site/demo/ — только по явному разрешению пользователя

**Неизвестные параметры:** [D-16](decisions.md#d-16)

**Трассировка:** R: R-041

## W-16

**Платёжные запросы и Stripe: страница оплаты, intent, отмена (локальный уровень)** · L — локально · срез S3 · зависит от: W-13, W-14, W-15

**Результат.** PaymentRequestService поверх модели и requestPayment из W-13: создание PaymentIntent при первом открытии страницы оплаты (pending → creating → pending с intent_id, §3.3), страница оплаты с Payment Element, StripeInstallments с вызовом вне транзакции, отмена запроса с intent (в том числе при отмене заказа), неизвестный исход и кнопка «проверить статус в Stripe»; действия в админке; мок SDK. Webhook, аномалии и возвраты — W-28.

**Входы:** `aiqme-site/experiments/orders-comparison/shared/Payments/StripeInstallments.php`; `aiqme-site/experiments/orders-comparison/shared/Tests/StripeInstallmentsTest.php`; `docs/planning/edition-2/03-orders-payments.md#33-платёжные-запросы-и-stripe`; `docs/planning/edition-2/06-security-files.md (§6.2, §6.6)`

**Затрагивает:** `aiqme-site/app/Domain/Payments/`; `aiqme-site/resources/views/public/checkout*`; `aiqme-site/app/Http/Controllers/Account/ (переход к оплате с гостевой страницы)`; `aiqme-site/app/Livewire/Admin/Orders*`; `aiqme-site/resources/views/account/ (кнопка оплаты)`; `aiqme-site/app/Domain/Orders/ (только отмена заказа: вызов отмены запросов после commit)`; `aiqme-site/routes/ (страница оплаты)`; `aiqme-site/app/Http/Middleware/SecurityHeaders.php (домены Stripe в CSP, §6.6)`; `aiqme-site/config/services.php и aiqme-site/.env.example (ключи Stripe)`; `aiqme-site/app/Providers/ (клиент Stripe)`; `aiqme-site/resources/views/admin/ (платёжные запросы)`; `aiqme-site/lang/`

**Критерии завершения:**

- Перенесены тесты checkout эксперимента (test_checkout_is_idempotent_and_does_not_create_a_second_intent, test_other_buyer_cannot_create_intent_for_someone_elses_request — с ожиданием 404 на GET страницы оплаты вместо 403 POST-checkout эксперимента); добавлены тесты на исправления §2.6 для StripeInstallments и отмены запроса с intent
- Ни одного HTTP-вызова внутри DB::transaction — только тестом: мок Stripe запоминает DB::transactionLevel() при каждом вызове и сравнивает с уровнем на начало теста (с RefreshDatabase — 1), а не с 0
- Запрос создаётся без обращения к Stripe; intent создаётся при первом открытии страницы оплаты владельцем или гостем с правом, повторное открытие продолжает тот же intent (тест); при повторном открытии intent читается (retrieve) вне транзакции: статус succeeded или processing — «платёж обрабатывается» без формы, ничего не записывается (оплату записывают webhook или кнопка администратора; тест с моком retrieve)
- Отмена запроса идёт по автомату §3.3 (без intent — pending → void без вызова Stripe; с intent — pending → cancelling → void); отмена заказа с открытым запросом с intent переводит его в cancelling, после ответа Stripe — в void, возврат не создаётся (тест); Stripe отвечает «уже оплачено» при отмене — это исключение InvalidRequestException с кодом payment_intent_unexpected_state (ErrorObject::CODE_PAYMENT_INTENT_UNEXPECTED_STATE, stripe-php 16.6.0), а не возвращённый intent: после него intent читается (retrieve) вне транзакции и при succeeded с latest_charge запрос paid, транзакция capture записана (мок бросает именно это исключение) внутренним сервисом записи по проверенным данным Stripe (§2.6; его же использует W-28); сервис под блокировкой строки запроса пропускает уже записанный latest_charge (§3.3 «Запись»; индекс добавляет W-28), при отменённом заказе — дополнительно флаг needs_review (тест); таймаут создания оставляет запрос в creating; кнопка «проверить статус» для cancelling сначала читает intent (retrieve): canceled — cancelling → void, succeeded с latest_charge — paid, отменяемый статус — повтор отмены (тесты с моком: первая отмена — таймаут, затем retrieve → canceled → void; исключение payment_intent_unexpected_state при intent в canceled → void); недопустимые переходы автомата §3.3 отклоняются
- Транзакция 2 создания intent под блокировкой строки: creating → pending и client_secret — только если запрос ещё в creating и заказ не отменён; при отменённом заказе — creating → cancelling и отмена intent, при запросе в void — intent отменяется, состояние не меняется, client_secret не выдаётся; отмена заказа при запросе в creating — после commit проверка в Stripe по §3.4 (тесты с моком)
- CSP дополнена доменами Stripe из §6.6 (js.stripe.com, *.js.stripe.com, hooks.stripe.com, api.stripe.com); страница оплаты с Payment Element без нарушений CSP в консоли (bin/browser-check прерывает запросы к js.stripe.com, поэтому страница с Payment Element на уровне L — «не проверено до W-22»; заголовок CSP проверяется Feature-тестом)
- Страница оплаты и return_url: владелец, администратор или гость с правом на заказ в сессии (§6.2; право в сессии записывает гостевая страница W-15); гость, открывший присланную гостевую ссылку в чистой сессии, с гостевой страницы доходит до страницы оплаты с Payment Element (тест); администратор видит страницу без Payment Element, intent при его открытии не создаётся (тест); клиент B, посетитель и гость без права — 404, включая HEAD; то же на гостевом заказе (user_id = null): посетитель без права и гость с правом на другой гостевой заказ — 404 (тест); client_secret чужому не отдаётся; право в сессии — пара «заказ + хеш токена», после перевыпуска ссылки старая сессия получает 404 (тест); возврат браузера на return_url и параметр paid=1 не ставят paid (тест); при redirect_status=succeeded|processing в адресе возврата страница показывает «платёж обрабатывается» без формы и ничего не записывает (тест); кнопка оплаты открытого запроса — на гостевой странице и в карточке кабинета (места — W-15), ссылка страницы оплаты для зарегистрированного клиента — в карточке заказа админки (W-14); блоки оплаты этой задачи в кабинете и админке при APP_ENV=demo не выводятся (§8.7, тест)
- Открытие страницы при creating показывает «проверяется» и не вызывает Stripe; кнопка «проверить статус в Stripe» сначала ищет intent по metadata.request_id («не найден» в пределах 24 часов — только повтор создания с тем же ключом: Search API отстаёт; мок поддерживает search), повторяет создание с тем же ключом только в пределах 24 часов от intent_first_attempt_at (§2.5; повторами не меняется), позже — creating → void с needs_review (тесты с моком); кнопка доступна и для pending с intent (webhook мог не дойти): retrieve вне транзакции, при succeeded с latest_charge capture записывается тем же сервисом записи (тест с моком)
- События §5.1 этой задачи (отмена запроса с intent; оплата прошла — по ответу Stripe «уже оплачено» при отмене) пишутся в integration_events в той же транзакции, что и изменение (тест)
- Конфигурация Stripe проверяется до транзакции 1: ключи не заданы — страница «оплата временно недоступна», состояние запроса не меняется (тест); ответ Stripe 4xx при создании intent обрабатывается как «Неизвестный исход» §3.3 (тест с моком)
- PaymentIntent создаётся с payment_method_types = ['card'] (D-33; кошельки Apple Pay и Google Pay в MVP не обещаются, D-42), без automatic_payment_methods эксперимента; мок проверяет параметр (тест)
- Запрос в creating и в cancelling — открытый (§2.5): он резервирует сумму и блокирует изменение цены — requestPayment сверх остатка и confirmPrice отклоняются (тест)

**Проверки:**

- Feature-тесты §9.2 «Оплата» в части запроса, страницы оплаты, intent и отмены
- Feature-тесты §9.2 «Права» для страницы оплаты

**Ограничения:**

- Без реальных ключей; запросов к Stripe нет
- Manual capture не использовать

**Неизвестные параметры:** [D-06](decisions.md#d-06), [D-33](decisions.md#d-33), [D-42](decisions.md#d-42)

**Трассировка:** old_T: T-022; R: R-015; Q: Q-13-01, Q-13-02, Q-13-06, Q-28-03; AC: AC-13-01, AC-13-02, AC-13-04, AC-13-06, AC-18-07; audit: F-21

## W-28

**Webhook Stripe: исходы, аномалии, возвраты (локальный уровень)** · L — локально · срез S3 · зависит от: W-16

**Результат.** Webhook-обработчик Stripe: проверка подписи и livemode, запись payment_events, исходы 1–5 §3.3 с транзакцией Lunar capture и аномалиями (флаг needs_review у запроса или result = needs_review у события), succeeded для запросов в cancelling/void, запись charge.refunded по §3.3 «Возвраты», «платежи на сверку» в Overview; подписанные фикстуры.

**Входы:** `aiqme-site/experiments/orders-comparison/shared/Payments/StripeInstallments.php`; `aiqme-site/experiments/orders-comparison/shared/Tests/StripeInstallmentsTest.php`; `aiqme-site/experiments/orders-comparison/shared/Tests/AcceptanceTest.php`; `docs/planning/edition-2/03-orders-payments.md#33-платёжные-запросы-и-stripe`; `docs/planning/edition-2/06-security-files.md (§6.3, §6.5)`

**Затрагивает:** `aiqme-site/app/Domain/Payments/`; `aiqme-site/app/Http/Controllers/Webhooks/StripeController.php`; `aiqme-site/app/Livewire/Admin/Overview*`; `aiqme-site/app/Livewire/Admin/Orders* (действие «разобрано»)`; `aiqme-site/database/migrations/ (payment_events, индекс заряда)`; `aiqme-site/routes/ и aiqme-site/bootstrap/app.php (маршрут webhook вне группы web)`

**Критерии завершения:**

- Перенесены остальные 4 Stripe-теста эксперимента (два intent и подписанные webhook на один заказ, неверная подпись и варианты суммы/валюты/intent, failed → succeeded → поздний failed, live-событие) и webhook-тесты AcceptanceTest (аванс и остаток, полная оплата, повтор события и тот же заряд, failed/requires_action, неверная сумма и валюта, неверная подпись, тот же заряд по другому запросу — test_same_charge_cannot_pay_another_request); ожидания 422, «отклонено» и исключения уникальности эксперимента заменены исходами §3.3 (тот же заряд по другому запросу — 200, без второго capture, result = needs_review); AcceptanceTest «неверная сумма» переносится с изменением только amount (исход 2: capture на amount_received, needs_review), «неверная валюта» — исход 3
- Webhook — POST /webhooks/stripe; POST /stripe/webhook — 404 (тест); маршрут регистрируется вне группы web — без сессии и cookie (тест: в ответе нет Set-Cookie); CSRF-middleware к нему не применяется (только группа web, без списка except, §6.3): тест с включённой проверкой CSRF (в PHPUnit она отключена — подменить CSRF-middleware Laravel 13.37 PreventRequestForgery подклассом с runningUnitTests() = false): POST /webhooks/stripe без CSRF-токена с неверной подписью — 400, не 419; обычный POST формы без токена — 419
- Заряд записывается сервисом записи capture из W-16 (§2.6) и хранится на транзакции Lunar capture (reference = id заряда, meta.request_id) с частичным уникальным индексом (reference) WHERE type = 'capture' (§2.5, §3.3 «Запись»); тот же заряд по другому запросу — needs_review у запроса события; второе списание по тому же запросу — своя capture (тесты)
- Тесты W-13 с оплатой (ревизия после аванса, уменьшение ниже оплаченного, отмена без возврата) дополнительно проходят с оплатой через подписанную фикстуру webhook (фикстура сначала сохраняет у запроса stripe_intent_id, как pi_local эксперимента: пустой intent у pending — несовпадение, §3.3)
- Live-событие отклоняется в нерабочей среде
- Ответ webhook по §3.3: неподписанное, с чужим секретом или live — 400 без записи; подписанное — всегда 200 с исходом по факту денег (§3.3, исходы 1–5); пять вариантов теста эксперимента — каждый отдельным тестом со своим свежим запросом 4000 в pending с intent и своим latest_charge: amount=1 и id=pi_other — capture 4000, paid, needs_review; currency=usd — без capture, result = needs_review и needs_review у запроса; amount_received=0 и status=processing — result = rejected, без capture (тесты; код 422 эксперимента не переносится)
- succeeded-событие для запроса в cancelling или void — запрос paid, транзакция capture записана, при отменённом заказе — дополнительно флаг needs_review; запрос не найден по metadata.request_id — без capture, payment_events.result = needs_review (тесты); после capture при любом исходе, если оплачено (за вычетом возвратов) больше подтверждённой цены, — needs_review у запроса с review_reason = overpaid (тест: два оплаченных запроса на один остаток — второй получает overpaid)
- Overview показывает «платежи на сверку»: запросы с needs_review и события payment_events с result = needs_review и без reviewed_at; действие администратора «разобрано» снимает needs_review у запроса и ставит reviewed_at у события, с записью в журнал (тест); при APP_ENV=demo блок не выводится (§8.7, тест)
- charge.refunded по §3.3 «Возвраты»: два частичных возврата 10 и 15 EUR одного заряда уменьшают «оплачено» на 25 EUR (транзакции refund 10 и 15), повтор события и событие с меньшим amount_refunded после большего — без эффекта, возврат лишнего второго списания находится по своей capture; заряд не найден среди транзакций capture (в том числе заряд без capture) — result = needs_review; повтор события charge.refunded, прежний result которого needs_review и reviewed_at пуст, обрабатывается заново (тест: возврат до оплаты → needs_review; оплата; повтор того же события → транзакция refund)
- События §5.1 оплаты (прошла, не прошла, на сверку, возврат) пишутся в integration_events в той же транзакции, что и изменение (тест); payment_intent.payment_failed по найденному неоплаченному запросу — result = ignored, но событие «не прошла» пишется; после paid — не пишется (тест)
- Событие с api_version старше 2022-11-15 (endpoint — версии 2025-02-24.acacia, §5.5) — result = needs_review (видно на сверке), без capture и без исхода 4 (тест: подписанное payment_intent.succeeded с api_version 2020-08-27 без latest_charge)
- Повтор event_id с действительной подписью и изменённым телом (например, другим pending_webhooks) — 200 без эффекта (кроме правила charge.refunded, done[8]): digest пишется только для журнала, несовпадение не ошибка (require «Event identity collision» эксперимента не переносится); ссылка payment_events на запрос nullable — событие без найденного запроса тоже записывается (тест)

**Проверки:**

- Feature-тесты §9.2 «Оплата» в части webhook, аномалий и возвратов

**Ограничения:**

- Без реальных ключей; запросов к Stripe нет
- Кнопки возврата нет

**Неизвестные параметры:** [D-07](decisions.md#d-07)

**Трассировка:** old_T: T-022; R: R-015; Q: Q-13-01, Q-13-03, Q-13-06, Q-28-02; AC: AC-13-03, AC-13-05, AC-13-07, AC-18-07, AC-20-09

## W-17

**Автоматические счета** · L — локально · срез S3 · зависит от: W-28, W-08

**Результат.** Настройки продавца, VAT и нумерации; InvoiceService с выдачей номера в транзакции, снимком реквизитов и PDF (dompdf, LT/EN); единая идемпотентная проверка выпуска InvoiceService::issueIfDue(order) по D-04 (по умолчанию — полная оплата); блокировка без реквизитов; скачивание в кабинете, гостевой странице и админке.

**Входы:** `docs/planning/edition-2/03-orders-payments.md#35-счета`

**Затрагивает:** `aiqme-site/app/Domain/Invoices/`; `aiqme-site/resources/views/invoices/`; `aiqme-site/app/Livewire/Admin/Settings*`; `aiqme-site/app/Livewire/Admin/Overview*`; `aiqme-site/resources/views/account/ (список invoices и скачивание счёта)`; `aiqme-site/app/Livewire/Admin/Orders* (скачивание счёта)`; `aiqme-site/app/Domain/Payments/ и aiqme-site/app/Domain/Orders/ (только точка вызова issueIfDue)`; `aiqme-site/database/migrations/ (invoices, invoice_lines, счётчик номеров в counters)`; `aiqme-site/routes/console.php (ежедневное задание «ожидающие счета»)`

**Критерии завершения:**

- Без реквизитов счёт не выпускается, в админке предупреждение
- Номер выдаётся атомарным UPDATE … RETURNING счётчика в транзакции выпуска (§3.5) и не дублируется при повторе события (тест: повтор события и повтор рендера не выдают второй номер)
- PDF содержит литовские символы корректно: шаблон задаёт шрифт DejaVu Sans (из dompdf/lib/fonts), тест извлекает текст PDF через pdftotext и находит «ąčęėįšųūž» (без pdftotext тест пропускается с причиной, записанной в progress.md); внешние ресурсы не загружаются
- Время генерации PDF записано в progress.md
- Overview показывает предупреждение «незаполненные реквизиты»; блоки счетов в кабинете и админке и это предупреждение при APP_ENV=demo не выводятся (§8.7, тест)
- Итог счёта = подтверждённая цена с VAT (D-22) всегда: VAT выделяется из строк по §3.5; при точной оплате он равен сумме capture − refund (тест), при переплате счёт выпускается на цену, а излишек остаётся на сверке (тест: итог = цене); адрес покупателя — по D-23
- После выпуска счёта цена заказа заблокирована с понятным сообщением (тест)
- После сохранения реквизитов ожидающие счета выпускаются заданием (тест)
- Событие §5.1 «Счёт выпущен» пишется в integration_events в той же транзакции, что и выпуск (тест)
- Гость с правом на заказ в сессии (§6.2, W-15) скачивает свой счёт, без права — 404, токена в адресе файла нет (тест); на гостевом заказе (user_id = null) посетитель без права, клиент B и гость с правом на другой гостевой заказ — 404, включая HEAD (тест, §6.1)
- issueIfDue(order) выполняется заданием очереди, поставленным после commit (afterCommit) записи capture и refund (одна точка вызова в сервисе записи оплаты W-16/W-28) и изменения цены (OrderWorkflow), и заданием «ожидающие счета» (после сохранения реквизитов и ежедневно по расписанию); номер, строка счёта и PDF создаются в одной транзакции задания (исключение рендера откатывает её, номер не расходуется, повтор выпускает счёт заново); исключение рендера PDF не роняет webhook, задание повторяется (тест: webhook под Queue::fake(), затем задание выполняется вручную дважды: исключение рендера, затем успех → номер один, PDF есть; рабочий код исключение не глотает); счёт выпускается, если его ещё нет, цена подтверждена, оплачено ≥ подтверждённой цены и заказ не отменён (отменённый — без счёта, флаг needs_review ставит W-28); язык счёта — локаль заявки из order_meta (W-12; тест: заявка с локалью en даёт PDF на EN, проверка через pdftotext); тесты: полная оплата, оплата сразу с переплатой, цена снижена до оплаченного; тесты W-28 остаются зелёными

**Проверки:**

- Feature-тесты §9.2 «Счета»

**Ограничения:**

- Демо-НДС 21 % не использовать
- Тестовые реквизиты только в тестах, с пометкой TEST

**Неизвестные параметры:** [D-04](decisions.md#d-04), [D-05](decisions.md#d-05), [D-22](decisions.md#d-22), [D-23](decisions.md#d-23), [D-34](decisions.md#d-34)

**Трассировка:** old_T: T-023; R: R-016, R-039; Q: Q-13-04, Q-13-05, Q-18-02; AC: AC-13-10, AC-13-11, AC-13-12, AC-18-09, AC-22-05, AC-22-06; audit: F-23

## W-18

**Google Таблицы (локальный уровень)** · L — локально · срез S3 · зависит от: W-28, W-17, W-09

**Результат.** SheetsClient — тонкий слой (getValues(range), batchUpdate(data)): реализация на HTTP-клиенте Laravel с токеном от внедряемого поставщика (google/auth), фейк эмулирует сетку листа; SheetsProjector (upsert по ключу — здесь, а не в клиенте) для Orders/Requests/Contacts/Payments/Events с RAW, очередь sheets с одним писателем и пакетной записью values:batchUpdate, команда пересборки, статус в Integrations; ссылка на Google Таблицу хранится в settings (§2.5), редактируется в Integrations и выводится в Integrations и Customers.

**Входы:** `docs/planning/edition-2/05-integrations.md#53-google-таблицы`

**Затрагивает:** `aiqme-site/app/Domain/Integrations/Sheets/`; `aiqme-site/config/aiqme.php (дополняет файл W-27)`; `aiqme-site/app/Livewire/Admin/Integrations*`; `aiqme-site/app/Livewire/Admin/Customers* (ссылка на таблицу)`; `aiqme-site/app/Domain/Integrations/IntegrationEvents.php (включение канала sheets)`

**Критерии завершения:**

- Все события §5.1, записанные в integration_events задачами W-09, W-12, W-13, W-16, W-28 и W-17, отражаются в фейковой таблице с английскими заголовками; запись событий в доменный код W-18 не добавляет
- Повтор задания не дублирует строку; задание проекции имеет middleware WithoutOverlapping с ключом сущности и перед записью перечитывает состояние из БД (тест: два задания одной сущности подряд при изменённой между ними БД дают одну строку с последним состоянием); один worker очереди sheets проверяется в W-20
- Без настроенного ключа события копятся и не теряются; админка показывает «не подключено»
- Канал sheets обрабатывается отдельно от mail: сбой или отсутствие Sheets и повтор не отправляют письмо повторно (тест)
- Registered и Last activity листа Contacts вычисляются из БД при проекции и пересборке (§5.3)
- Реализация SheetsClient проверяется через Http::fake: адрес values:batchUpdate и чтения, valueInputOption=RAW, тело запроса, повтор при 429; токен — заглушка поставщика, к oauth2.googleapis.com запросов нет (Http::preventStrayRequests, W-01); тесты upsert идут через SheetsProjector на фейке-сетке, где тест переставляет и удаляет строки
- aiqme:sheets-rebuild ставит задание в очередь sheets (единственный писатель, §5.3; тест: команда сама в лист не пишет), задание пишет заголовки и данные из БД пакетами не больше 5 000 строк на запрос и очищает строки ниже последней пустыми значениями в том же batchUpdate; тест на фейке-сетке: лишняя строка исчезает, писем нет
- После W-18 запись события сразу ставит задание канала sheets в очередь sheets (afterCommit), а повтор W-09 подбирает и канал sheets (тест); ключ сервисного аккаунта читается из переменной .env с JSON ключа в base64 (§5.3), отдельного файла и монтирования в контейнеры нет

**Проверки:**

- Feature-тесты §9.2 «Sheets»

**Ограничения:**

- Обратной синхронизации нет
- Реальных запросов к Google нет
- Новый пакет google/auth ^1.55 разрешён вместе с его новыми зависимостями firebase/php-jwt и psr/cache (нет в composer.lock эксперимента; обоснование — §5.3; загрузка — Packagist и dist-архив GitHub по D-20; версии — в composer.lock приложения и progress.md); других новых пакетов нет

**Неизвестные параметры:** [D-20](decisions.md#d-20)

**Трассировка:** old_T: T-029; R: R-022, R-023, R-024, R-029; Q: Q-02-06, Q-16-03, Q-16-04, Q-16-06; AC: AC-16-11, AC-16-12, AC-16-14, AC-16-15, AC-16-18; audit: F-32

## W-19

**Telegram-бот контента (локальный уровень)** · L — локально · срез S3 · зависит от: W-08

**Результат.** Webhook с secret_token, привязка одноразовым кодом, дедупликация update_id, диалог редактирования всех типов контента через ContentService (один общий диалог: список полей и проверки берутся из описания типов ContentTypes, общего с редактором W-08; отдельного кода на тип нет), медиа ≤20 МБ, публикация по языкам, конфликт ревизии; команды aiqme:telegram-webhook (регистрация webhook) и aiqme:telegram-poll (опрос для локальной проверки тестового бота); раздел привязки на экране Integrations (экран создаёт W-18; если её нет — W-18 заблокирована, — W-19 создаёт экран сама); тесты на фикстурах апдейтов.

**Входы:** `docs/planning/edition-2/04-content-seo.md#45-telegram-бот-контент`; `docs/customer-prototype/audit-20261016/screens-and-scenarios.md#telegram`; `docs/customer-prototype/evidence/audit-20261016/states-followup/ (telegram-0…9-*/dom.html — тексты реплик бота, EN по rules)`; `docs/planning/edition-2/06-security-files.md (§6.3, §6.5)`

**Затрагивает:** `aiqme-site/app/Domain/Integrations/Telegram/`; `aiqme-site/app/Http/Controllers/Webhooks/TelegramController.php`; `aiqme-site/app/Livewire/Admin/Integrations* (раздел Telegram)`; `aiqme-site/app/Console/Commands/ (Telegram*)`; `aiqme-site/app/Domain/Content/ContentTypes.php (только чтение)`; `aiqme-site/routes/ (маршрут webhook вне группы web)`

**Критерии завершения:**

- Правка из бота видна в вебе и наоборот
- Чужой отправитель не получает данных
- Заказы и клиенты через бот недоступны
- Код привязки по §4.5 (не меньше 128 бит, хранится SHA-256, неверные попытки ограничены по user.id): неверный код не привязывает и не раскрывает данных (тест)
- Токен бота вырезается из сообщений исключений HTTP-клиента: при сетевой ошибке его нет в журнале и в последней ошибке (тест); webhook — /webhooks/telegram без CSRF-middleware (вне группы web, §6.3): тест с включённой проверкой CSRF (как в W-28) — POST без CSRF-токена с неверным secret_token даёт 401/403, не 419; маршрут вне группы web, как у W-28 (тест: в ответе нет Set-Cookie)
- aiqme:telegram-webhook вызывает setWebhook с адресом APP_URL/webhooks/telegram, secret_token из TELEGRAM_WEBHOOK_SECRET (1–256 символов A-Z, a-z, 0-9, _ и -; иначе команда отказывает) и allowed_updates message, callback_query; aiqme:telegram-poll (только при APP_ENV=local, для тестового бота) вызывает deleteWebhook и getUpdates в цикле до остановки (тест — один проход) и передаёт апдейты тому же обработчику, что webhook (дедупликация update_id); тесты на Http::fake, токена нет в журнале
- Диалог покрывает типы, зарегистрированные в Domain/Content/ContentTypes на момент задачи (W-08, W-26); кода на отдельный тип нет
- Медиа из бота — только JPEG, PNG, WebP и MP4 по сигнатуре через ту же проверку типов Domain/Content, что у редактора W-08 (§6.4), и сохраняются тем же классом W-08: имя UUID с расширением по сигнатуре, исходное имя документа Telegram не используется; SVG-документ и файл с чужой сигнатурой отклоняются (тест)

**Проверки:**

- Feature-тесты §9.2 «Telegram»

**Ограничения:**

- Без реального токена и запросов к Telegram
- Удаление записей через бот не делать

**Неизвестные параметры:** нет

**Трассировка:** old_T: T-028; R: R-020, R-021, R-025, R-040; Q: Q-06-04, Q-15-05, Q-16-05; AC: AC-15-10, AC-15-12, AC-16-07, AC-16-09, AC-19-16; audit: F-33, F-34

## W-30

**Сквозные сценарии §9.3 (локальный уровень)** · L — локально · срез S4 · зависит от: W-18

**Результат.** Сценарии 1–5 §9.3 на уровне L — по одному Feature-тесту в tests/Feature/Scenarios/, которые собирают цепочку задач через их сервисы и маршруты: оплата — подписанные фикстуры webhook W-28, Sheets — фейк-сетка W-18 со строками Orders/Payments/Events на каждом шаге, почта — Mail::fake или Mailpit, Telegram — фикстуры апдейтов W-19.

**Входы:** `docs/planning/edition-2/09-verification.md (§9.3)`; `docs/planning/edition-2/03-orders-payments.md`; `docs/planning/edition-2/05-integrations.md (§5.1)`

**Затрагивает:** `aiqme-site/tests/Feature/Scenarios/`; `aiqme-site/docs/verification/`

**Критерии завершения:**

- Сценарий 1: контактная форма с 2 вложениями → письмо на info@aiqme.com → строки Requests, Contacts и Events на фейке-сетке → заявка видна в админке
- Сценарий 2: гость → заявка с 2 STL → гостевая ссылка → администратор скачивает STL и подтверждает 150 EUR (120 + доставка) → запрос аванса 40 EUR → подписанное succeeded → производство при долге 110 EUR (с подтверждением) → запрос остатка → оплата → счёт выпущен (тестовые реквизиты с пометкой TEST) → shipped с треком → completed; после каждого шага сверяются строки Orders, Payments и Events фейка-сетки
- Сценарий 3: регистрация клиента → заявка → аванс 40 EUR → отмена администратором → в карточке «оплачено 40 EUR, отменён», транзакции refund нет
- Сценарий 4: восстановление пароля по письму → вход новым паролем; старые сессии завершены (тот же механизм и форма теста, что W-07: следующий запрос прежней сессии ведёт на вход)
- Сценарий 5: публикация проекта только на LT через фикстуры апдейтов Telegram → 200 на /lt/, 404 на /en/; публикация EN через ContentService (как в редакторе W-26) → появляется hreflang; если W-19 или W-26 (тип project и его редактор) заблокирована или пропущена — сценарий «не проверено: W-19/W-26» с причиной
- Дефект кода прежних задач, найденный сценарием, исправляется в этой сессии с записью в progress.md, если укладывается в неё, иначе задача блокируется с описанием дефекта; итог — в aiqme-site/docs/verification/scenarios-l.md

**Проверки:**

- bin/check
- Feature-тесты tests/Feature/Scenarios/ — по одному на сценарий

**Ограничения:**

- Без реальных ключей и внешних запросов (rules)
- Тестовые адаптеры в рабочий код не добавлять

**Неизвестные параметры:** [D-04](decisions.md#d-04), [D-10](decisions.md#d-10), [D-22](decisions.md#d-22)

**Трассировка:** old_T: T-037; R: R-007, R-011, R-014, R-015, R-016, R-020, R-022

## W-20

**Эксплуатационный пакет I: образ, prod-compose, nginx и прокси** · L — локально · срез S4 · зависит от: W-17, W-18

**Результат.** Образ из чистого экспорта отслеживаемых файлов (Node-стадия сборки Vite, PHP-FPM 8.4 с расширениями §2.1), docker-compose.prod.yml (app, web, worker, scheduler, db), конфигурация nginx и пример для общего прокси (Caddy по D-35), .env.example, доверенные прокси, health-check, ротация журналов, закреплённые теги образов; проверка сквозным сценарием в локальном prod-compose. Копии, расписание, измерения и runbook — W-29.

**Входы:** `docs/planning/edition-2/08-operations.md`; `docs/planning/edition-2/06-security-files.md (§6.2, §6.3, §6.6)`

**Затрагивает:** `aiqme-site/docker/`; `aiqme-site/bin/build-context`; `aiqme-site/.dockerignore`; `aiqme-site/.env.example`; `aiqme-site/config/aiqme.php (доверенные прокси)`; `aiqme-site/app/Http/Middleware/ (доверенные прокси)`; `aiqme-site/docs/operations.md (образ и запуск)`; `aiqme-site/bin/browser-check (режим --base-url)`; `aiqme-site/tests/browser/`

**Критерии завершения:**

- Локальный prod-compose (APP_ENV=production, без тестовых адаптеров; имя проекта — name: aiqme-prod в docker-compose.prod.yml, не как у dev-compose W-01; env-файл — игнорируемый aiqme-site/build/prod-local/.env через AIQME_ENV_FILE, done[5]) проходит сквозной сценарий 2 из §9.3 без шага оплаты. build/prod-local/.env: APP_URL=http://127.0.0.1:<порт>, SESSION_SECURE_COOKIE=false, MAIL_MAILER=log, лимит заявок по IP (W-12) поднят (например до 100 в час, код не меняется), ключи Stripe, Google и Telegram пустые (их события остаются неотправленными — это ожидаемо), AIQME_ENV_FILE=build/prod-local/.env, права 0644 (синтетические значения без рабочих секретов); эти отличия от рабочего шаблона записаны в progress.md. Все локальные команды prod-compose (up, bin/browser-check --compose-file, bin/backup, bin/restore) — docker compose --env-file build/prod-local/.env -f docker-compose.prod.yml …, dev-.env не используется. Сценарий выполняет bin/browser-check --base-url <адрес prod-compose> --compose-file docker-compose.prod.yml: режим принимает только 127.0.0.1/localhost и проект aiqme-prod с build/prod-local/.env, иначе отказывается до любых действий (тест скрипта); без migrate:fresh, своей БД и запуска сервера; администратора создаёт сам, передавая случайный пароль в stdin docker compose --env-file build/prod-local/.env -f docker-compose.prod.yml exec -T -u www-data app php artisan aiqme:make-admin --password-stdin (D-29; пароль не в argv и не в журналах). Шаги: заявка, подтверждение, запрос оплаты (без обращения к Stripe, §3.3), производство, трек; оплата проверяется PHPUnit-тестами W-16 и W-28 и на уровне T в W-22; дефект кода прежних задач — как W-30 done[5], кроме записи итога в scenarios-l.md
- Образ app — php:8.4-fpm с расширениями §2.1 (bcmath, intl, gd, pdo_pgsql, pgsql, zip, exif, opcache) через docker-php-ext-install; gd — после docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp (libfreetype-dev, libjpeg62-turbo-dev, libpng-dev, libwebp-dev); в образе php -r 'var_dump(gd_info());' показывает JPEG, WebP и FreeType = true, php -m содержит список §2.1; apt-пакеты из deb.debian.org — только внутри docker build (D-20)
- Лимиты загрузки по §6.4 заданы явно: conf.d образа — upload_max_filesize=25M, post_max_size=260M, max_file_uploads=20, memory_limit=512M, php -i в образе показывает эти значения; max_execution_time=120 в conf.d и fastcgi_read_timeout 120s в nginx приложения; nginx приложения — client_max_body_size 260m; пример общего прокси — для Caddy по D-35 (request_body max_size 260MB, без директивы log — журнала доступа нет; для nginx-прокси — client_max_body_size 260m, proxy_read_timeout 120s и тот же map, что в web); сценарий prod-compose загружает сгенерированный STL ≈25 МиБ / 500 000 треугольников и получает заявку; заявка из 10 таких файлов тоже проходит, её время записано в progress.md (если оно больше 60 с — половины таймаута 120 с, — таймауты PHP, nginx и прокси увеличены до двукратного измеренного и записаны)
- Развёртывание по §8.3 после migrate --force запускает aiqme:bootstrap (W-12) и на пустой БД — однократно сидер контента W-04 по имени класса (DatabaseSeeder тестовых пользователей не содержит, W-01); в prod-compose это проверено
- В docker-compose.prod.yml у db, app, worker, scheduler нет ports:; web — только во внешней сети общего прокси или на 127.0.0.1:<порт>; в docker compose -f docker-compose.prod.yml config --format json ports есть только у web, и у каждой его записи host_ip = "127.0.0.1" (запись без host_ip — провал; поиск строки 0.0.0.0 не засчитывается, §8.1); БД и storage — именованные тома
- .env.example: APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true, SESSION_ENCRYPT=true (§6.3), LOG_CHANNEL=stderr; доверенные прокси — из .env (TRUSTED_PROXIES → config/aiqme.php), не *: подсеть внешней сети общего прокси, а при web на 127.0.0.1:<порт> — шлюз сети проекта (docker network inspect, поле Gateway; §6.6); читаются в момент запроса из config (подкласс TrustProxies), а не через env() в bootstrap/app.php; тест задаёт список через config() после загрузки приложения: X-Forwarded-For/X-Forwarded-Proto от недоверенного адреса не подменяют IP и схему, от доверенного — берутся; в prod-compose после optimize php artisan config:show aiqme.trusted_proxies в контейнере app показывает значение своего режима: подсеть сети прокси или (локально, web на 127.0.0.1) шлюз сети проекта; HSTS — пункт runbook для W-23; .env монтируется только для чтения в каталог приложения во все PHP-сервисы (app, worker, scheduler: ${AIQME_ENV_FILE:-./.env}:<каталог приложения>/.env:ro — на сервере ./.env, локально build/prod-local/.env; dev-.env W-01 не используется и не меняется), env_file не используется (FPM с clear_env = yes)
- Журнал доступа nginx приложения и примера прокси без $http_referer, гостевые адреса, ссылка сброса пароля и адрес возврата на страницу оплаты (payment_intent_client_secret) не пишутся (§6.2): после запросов гостевой страницы и ресурса с неё, ссылки сброса пароля и страницы оплаты с параметрами возврата docker compose logs web не содержит токенов; положительный контроль: журнал доступа включён и пишет в stdout — после тех же запросов docker compose logs web содержит строку обычного запроса (например, GET /lt/), иначе проверка — провал
- Образ собирается только из чистого экспорта: bin/build-context складывает в aiqme-site/build/ tar файлов git ls-files -co --exclude-standard aiqme-site без experiments/ (то, что попадёт в Git; на сервере — git archive версии, §8.3), docker build идёт из этого контекста, а не из рабочего дерева; .dockerignore исключает experiments/, build/, demo/, storage/, bootstrap/cache/*.php, public/build, public/hot, public/storage, .env* (кроме .env.example), node_modules/, vendor/, tests/fixtures/, .codex/, .mcp.json; Node-стадия получает vendor/ из PHP-стадии (COPY --from) и выполняет npm ci && npm run build; public/build копируется в образ app, а web получает весь public/ этого образа (вместе с public/build); public/storage — относительная симлинка внутри образа на ../storage/app/public (создаётся в Dockerfile), том storage подключён к web только для чтения — так nginx раздаёт публичные медиа (диск public medialibrary, /storage/…); worker и scheduler работают с user: www-data, artisan в exec, runbook и bin-скриптах вызывается как docker compose exec -T -u www-data app php artisan …; composer install --no-dev в образе; Dockerfile создаёт bootstrap/cache и пустые storage/framework/{cache/data,sessions,views} и storage/logs с правами пользователя PHP-FPM; в образе (docker run --rm <образ> find в каталоге приложения) нет .env, experiments, bootstrap/cache/config.php и симлинки public/storage на путь хоста
- nginx передаёт в PHP-FPM только location ~ ^/index\.php(/|$) и запрещает dot-файлы (location ~ /\.(?!well-known) { deny all; }); nginx добавляет X-Content-Type-Options: nosniff и Referrer-Policy: same-origin (always) к статике и /storage/ (§6.6); проверка в prod-compose: файл, положенный через docker compose exec -T -u www-data app в том storage/app/public (например, test.png), отдаётся по /storage/test.png с 200 и nosniff; затем там же x.php с маркером — GET /storage/x.php не возвращает результат исполнения (404, 403 или текст файла); без шага с файлом в томе проверка не засчитывается (§6.2)
- У всех сервисов logging.options max-size/max-file; healthcheck у web по /up (запрос проходит через app; у app своего HTTP нет, отдельного healthcheck у него нет) — инструментом, который есть в закреплённом образе web (docker run --rm <тег> which curl wget), docker compose ps показывает у web healthy; теги образов закреплены (php:8.4-fpm-<выпуск Debian>, postgres:17-<выпуск>, nginx:<версия>, node:22-<выпуск>) и записаны в progress.md; worker обрабатывает очередь sheets одним процессом (docker compose config); у app, web, worker, scheduler и db заданы deploy.resources.limits (memory, cpus) из переменных .env со значениями по умолчанию (§8.1), видны в docker compose config

**Проверки:**

- bin/check
- образ из bin/build-context проходит сценарий prod-compose
- в образе нет instance/, experiments/, фикстур, .env, bootstrap/cache/config.php
- docker compose config --format json: ports только у web, у каждой записи host_ip = 127.0.0.1
- журнал web без гостевого токена, токена сброса и client_secret, но со строкой обычного запроса
- файл из тома отдаётся по /storage/… (200), x.php из тома не исполняется

**Ограничения:**

- Сервер не арендовать
- Не придумывать нагрузку другого проекта

**Неизвестные параметры:** [D-01](decisions.md#d-01), [D-02](decisions.md#d-02), [D-03](decisions.md#d-03), [D-20](decisions.md#d-20), [D-29](decisions.md#d-29), [D-35](decisions.md#d-35), [D-39](decisions.md#d-39)

**Трассировка:** old_T: T-033, T-036; R: R-009; Q: Q-17-01; AC: AC-17-07, AC-23-14, AC-23-15

## W-29

**Эксплуатационный пакет II: копии, расписание, измерения, runbook** · L — локально · срез S4 · зависит от: W-20

**Результат.** Скрипты резервной копии и восстановления с манифестом sha256, расписание scheduler и cron хоста для копии, давность копии в Overview, измерения ресурсов AIQME для подбора сервера, runbook развёртывания; проверка восстановления локально.

**Входы:** `docs/planning/edition-2/08-operations.md (§8.2–§8.5)`; `docs/planning/edition-2/decisions.md (D-01, D-13, D-31)`

**Затрагивает:** `aiqme-site/bin/backup`; `aiqme-site/bin/restore`; `aiqme-site/routes/console.php (расписание)`; `aiqme-site/app/Livewire/Admin/Overview* (давность копии)`; `aiqme-site/docs/operations.md`; `aiqme-site/bin/browser-check и aiqme-site/tests/browser/ (только сценарий контактной заявки с вложением для режима --base-url W-20)`

**Критерии завершения:**

- bin/backup и bin/restore работают против prod-compose (локально — команда prod-compose W-20 done[0] с build/prod-local/.env, на сервере — -f docker-compose.prod.yml; проект aiqme-prod): дамп — exec -T db pg_dump, файлы storage и метка времени — через контейнер (docker compose -f … exec -T app tar … или run --rm с томом storage), а не с диска хоста; проверка — локальный prod-compose W-20 с синтетическими данными → копия → docker compose -f docker-compose.prod.yml down -v (только проект aiqme-prod; тома dev-compose W-01 не затрагиваются) → up на пустых томах → восстановление → хеши файлов совпадают; копии пишутся локально в игнорируемый aiqme-site/build/backups/, на сервере — в каталог из .env вне приложения
- pg_dump основной версии 17 берётся из контейнера db (docker compose -f docker-compose.prod.yml exec -T db pg_dump; не из образа app), копию запускает cron хоста через bin/backup, а не scheduler; порядок: сначала дамп, затем файлы; проверка комплекта при восстановлении сверяет stl_files.sha256, пути вложений и PDF счетов из дампа с файлами копии (перед копией сценарий добавляет в prod-compose контактную заявку с вложением через bin/browser-check --base-url — режим W-20 запускает только сценарий 2, сценарий заявки добавляет эта задача; вид ссылок без данных — PDF счетов, оплаты в prod-compose нет — записывается в progress.md «не проверено: нет данных», а не засчитывается); каталог копий 0700, файлы 0600; внешняя копия без дополнительного шифрования (D-31)
- Расписание scheduler по §8.4: повтор каналов outbox (W-09), ночная проверка файлов-сирот без удаления, очистка устаревших сессий и токенов сброса; ночная копия — строка cron хоста в runbook; bin/backup после успеха записывает метку времени в том storage, Overview показывает давность последней копии
- Строка требований AIQME к серверу (RAM, vCPU, диск, рост) записана с методом измерения; по измерению записаны рекомендуемые лимиты памяти и CPU контейнеров (§8.1, W-20)
- Runbook (aiqme-site/docs/operations.md): развёртывание §8.3 (git archive версии → тот же Dockerfile W-20, migrate --force, aiqme:bootstrap, сидер по имени класса на пустой БД, aiqme:make-admin --password-stdin через docker compose exec -T — только при первом развёртывании или явной смене пароля администратора, bin/browser-check против рабочего сервера не запускается (W-20), optimize, перезапуск worker), восстановление §8.5 (сначала php artisan down, затем остановка worker и scheduler), права .env (chown root:33, chmod 0640, §8.3; поэтому команды docker compose и cron bin/backup на сервере — от root: sudo и crontab root; bin/backup при нечитаемом .env завершается с явной ошибкой), смена ключей, HSTS; одной строкой — защита хоста (SSH только по ключу, UFW, автоматические обновления безопасности) — ответственность владельца сервера (D-01)
- bin/backup после успешной копии удаляет копии сверх 14 дневных и 8 недельных (недельная — последняя копия каждой ISO-недели; числа — из .env, D-13); тест — PHPUnit через Process запускает только ротацию bin/backup на временном каталоге с копиями с подделанными датами, без Docker

**Проверки:**

- bin/check
- проверка восстановления в локальном prod-compose: копия → down -v проекта aiqme-prod → восстановление на пустые тома, хеши совпадают
- тест расписания scheduler

**Ограничения:**

- Сервер не арендовать
- Не придумывать нагрузку другого проекта

**Неизвестные параметры:** [D-01](decisions.md#d-01), [D-02](decisions.md#d-02), [D-03](decisions.md#d-03), [D-13](decisions.md#d-13), [D-31](decisions.md#d-31)

**Трассировка:** old_T: T-033, T-034; Q: Q-04-04, Q-21-01, Q-21-02, Q-22-01, Q-22-02; AC: AC-21-10, AC-22-09, AC-22-10

## W-21

**Итоговая локальная проверка и передача** · L — локально · срез S4 · зависит от: W-29, W-25, W-26

**Результат.** Отчёт aiqme-site/docs/verification/local-release.md: все тесты §9.2, итог сквозных сценариев §9.3 на уровне L (тесты — W-30), матрица маршрутов LT/EN/RU × 5 ширин §9.2 (320/390/768/1024/1440; сверка с эталоном — на 1440/768/390), Lighthouse, автоматическая сверка оформления §9.1 по всем шаблонам (getComputedStyle — все шаблоны; порядок блоков — страницы W-05 из tests/fixtures/prototype-blocks.json) с отчётом кадров в aiqme-site/build/verification/ (ручной просмотр — «ждёт проверки пользователем»), контролируемая поломка по перечню (done), стоп-лист §9.5; инструкция для заказчика и разработчика.

**Входы:** `docs/planning/edition-2/09-verification.md`; `docs/customer-prototype/evidence/audit-20261016/verified-route-screens.json`

**Затрагивает:** `aiqme-site/docs/verification/`; `aiqme-site/docs/handover.md`; `aiqme-site/bin/browser-check`; `aiqme-site/tests/browser/`; `aiqme-site/demo/ (пересборка)`; `docs/requirements.md (только строка «Новый сайт»)`

**Критерии завершения:**

- Стоп-лист §9.5 пройден на уровне L; дефект кода прежних задач — как W-30 done[5], кроме записи итога в scenarios-l.md
- Поиск маркеров служебных демо-надписей D-21 (bin/browser-check из W-03, маркеры там же) по всем отрендеренным страницам рабочего приложения (LT/EN; публичные, кабинет, админка; без demo/) на данных фабрик tests/browser/ всех задач (опубликованные проекты, обучение, новости, товары и сравнение — W-06, W-25; заказы, запросы оплаты и гостевая страница — W-14, W-15), а не только на заглушках: каждое совпадение исправлено или внесено в tests/fixtures/marker-allowlist.json с причиной; совпадений вне файла нет
- Все функции имеют статус L-готово или явную причину
- Если W-24 выполнена — bin/demo-build пересобран после W-16…W-29, проверки W-24 повторены, разделов оплаты и счетов в демо нет; если W-24, W-19 или W-31 заблокированы или пропущены — их проверки (для W-31 — просмотр модели) в отчёте «не проверено» с причиной, остальное выполняется
- Автотест по словарю (нет LT-строк ключей ui_text на EN-страницах) — по всем страницам (§7.2)
- В docs/requirements.md изменена только строка «Новый сайт» таблицы «Что существует сейчас»: ссылка на local-release.md и формулировка «L-готово, внешние подключения не проверены»
- Список того, что требует уровней T и P, с нужными доступами
- Вся таблица §4.6 проверена для всех 184 адресов route-manifest (по tests/fixtures/prototype-routes.json из W-02), включая кабинет и все вкладки админки
- Контролируемая поломка (временная правка, затем возврат; итоговый bin/check зелёный): каждая из поломок роняет свой тест — снятие проверки владельца у скачивания STL; отключение проверки подписи webhook Stripe; регистрация /webhooks/stripe в группе web (роняет тесты W-28: Set-Cookie в ответе и 419 при включённом CSRF); выдача второго номера счёта при повторе события; снятие идемпотентности токена отправки заявки; запись paid по return_url; результат каждой — в отчёте
- Итог сквозных сценариев W-30 перенесён в local-release.md; если W-30 заблокирована или пропущена — сценарии §9.3 «не проверено» с причиной
- handover.md перечисляет строки, которые после прогона устарели и которые пользователь обновляет сам (агент их не меняет, rules): корневой AGENTS.md («aiqme-site/ … пока пуст»), README.md, aiqme-site/README.md («Реализация не начата»)

**Проверки:**

- bin/check
- bin/browser-check
- Lighthouse CLI по §9.1 (npx lighthouse с фиксированной версией и --chrome-path на Chromium Playwright); если недоступен — «не проверено» с причиной

**Ограничения:**

- Не публиковать
- Результаты моков не называть интеграцией

**Неизвестные параметры:** [D-20](decisions.md#d-20)

**Трассировка:** old_T: T-037, T-040; R: R-001, R-002, R-007, R-010, R-011, R-012, R-013, R-014, R-015, R-016, R-017, R-018, R-020, R-021, R-022, R-023, R-024, R-030; Q: Q-04-08, Q-09-02, Q-10-01, Q-24-01; AC: V-24-34

## W-22

**Тестовые подключения (Stripe test, тестовая таблица, тестовый бот, почта)** · T — тестовые подключения · срез S5 · зависит от: W-21

**Результат.** Для каждого доступного подключения — реальный тестовый вызов и запись в aiqme-site/docs/verification/test-connections.md: Stripe test mode (успех, отказ, 3DS, аванс + остаток, повтор события, возврат в Dashboard, отмена запроса после оплаты — ответ «уже оплачено»), тестовая Google-таблица, тестовый бот (привязка, правка, медиа), тестовая отправка письма на разрешённый адрес.

**Входы:** `aiqme-site/docs/verification/local-release.md`; `docs/planning/edition-2/05-integrations.md`

**Затрагивает:** `aiqme-site/.env (локально, не в Git; по умолчанию в копии aiqme-run, D-39)`; `aiqme-site/docs/verification/`

**Критерии завершения:**

- Каждое подключение отмечено T-готово с датой и идентификатором тестового объекта либо «нет доступа»; при «нет доступа» для всех подключений задача не считается выполненной, а остаётся заблокированной
- Отсутствие одного доступа не останавливает проверку других
- В отслеживаемом test-connections.md идентификаторы Stripe test (pi_…, evt_…) пишутся полностью, а ID таблицы, chat/user id и адреса почты — маскируются (видны последние 4 символа)
- Домены CSP Stripe (§6.6) сверены с docs.stripe.com/security/guide на дату проверки; при оплате с 3D Secure в консоли нет нарушений CSP
- Тестовый бот проверяется локально командой aiqme:telegram-poll (W-19), без публичного адреса; Stripe — через stripe listen (события в версии API аккаунта по умолчанию; если она старше 2022-11-15 — stripe listen --latest); версия API событий записана в test-connections.md; если W-19 заблокирована или пропущена — Telegram «нет кода (W-19)», а не «нет доступа»

**Проверки:**

- Сквозной сценарий 2 §9.3 в Stripe test mode через stripe listen

**Ограничения:**

- Только тестовые ключи и тестовые адресаты
- Секреты не попадают в Git и отчёты
- Письмо на info@aiqme.com — только по разрешению владельца

**Неизвестные параметры:** [D-15](decisions.md#d-15), [D-39](decisions.md#d-39)

**Трассировка:** old_T: T-037; R: R-008, R-015, R-020, R-021, R-022, R-036; Q: Q-24-03

## W-23

**Рабочий запуск** · P — рабочее подключение · срез S5 · зависит от: W-22

**Результат.** Арендованный сервер по D-01, развёрнутое приложение, aiqme.com с TLS без поломки почты, live-ключи Stripe и webhook, реальные реквизиты и нумерация, рабочие таблица и бот, внешняя копия, контрольный сценарий; запуск — только по явному разрешению.

**Входы:** `aiqme-site/docs/operations.md`; `docs/planning/edition-2/decisions.md`

**Затрагивает:** `сервер`; `DNS aiqme.com`; `аккаунты Stripe/Google/Telegram/почты владельца`

**Критерии завершения:**

- Решения из unknowns W-23 (D-01, D-02, D-03, D-04, D-05, D-13, D-14, D-15, D-22, D-23, D-27, D-31, D-34, D-35, D-42) приняты или вариант по умолчанию подтверждён и записан
- Стоп-лист §9.5 пройден в рабочей среде
- Откат кода проверен на временном поддомене или копии рабочей БД до приёма реальных заказов (§8.3)
- Реальные тексты политик опубликованы (D-27); HSTS включён после подключения домена (§6.6)
- До первых оплат: endpoint /webhooks/stripe на рабочем домене с тремя событиями §5.5, версией API 2025-02-24.acacia (§5.5) и live STRIPE_WEBHOOK_SECRET; Telegram webhook зарегистрирован aiqme:telegram-webhook после выпуска TLS (W-19); проверка livemode; администратор создан aiqme:make-admin; сидер контента выполнен один раз на пустой БД; внешняя проверка /up подключена (§8.4); защита хоста (SSH по ключу, UFW, обновления безопасности) подтверждена владельцем сервера (D-01); внешняя копия — по D-13 и D-31

**Проверки:**

- Контрольный сценарий после развёртывания — вручную по runbook W-29, без bin/browser-check (W-20)
- проверка внешней копии и восстановления

**Ограничения:**

- Не выполнять без явного разрешения пользователя
- DNS почты не менять

**Неизвестные параметры:** [D-01](decisions.md#d-01), [D-02](decisions.md#d-02), [D-03](decisions.md#d-03), [D-04](decisions.md#d-04), [D-05](decisions.md#d-05), [D-13](decisions.md#d-13), [D-14](decisions.md#d-14), [D-15](decisions.md#d-15), [D-22](decisions.md#d-22), [D-23](decisions.md#d-23), [D-27](decisions.md#d-27), [D-31](decisions.md#d-31), [D-34](decisions.md#d-34), [D-35](decisions.md#d-35), [D-42](decisions.md#d-42)

**Трассировка:** old_T: T-035, T-038, T-039; R: R-009, R-016; Q: Q-04-07, Q-23-01, Q-23-03, Q-20-05; AC: AC-23-03, AC-23-11, AC-23-12
