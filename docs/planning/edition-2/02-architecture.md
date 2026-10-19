# 2. Архитектура на Laravel + Lunar

[← План](README.md). Основание: [сравнение Lunar](../../research/lunar-vs-laravel-comparison-20261017.md), код [aiqme-site/experiments/lunar-aiqme](../../../aiqme-site/experiments/lunar-aiqme/README.md) и [orders-comparison/shared](../../../aiqme-site/experiments/orders-comparison/README.md). **Рекомендация** — выбор разработчика плана для неутверждённой части; меняется без пересмотра остальной архитектуры.

## 2.1. Стек и версии

| Компонент | Версия | Источник |
|---|---|---|
| PHP | 8.4 (локально системный 8.4.24 + изолированные расширения эксперимента 8.4.26: bcmath, gd, intl, pdo_pgsql, pgsql, zip; `exif` есть в системном PHP). Образ W-20 — те же расширения плюс `exif` (нужен Lunar и medialibrary) и `opcache` | `aiqme-site/experiments/lunar-aiqme/bin/php`, `runtime-packages.txt` |
| Laravel | 13.37.0 | `aiqme-site/experiments/lunar-aiqme/app/composer.lock` |
| Lunar core/admin | 1.5.0 | там же. `lunarphp/stripe` из `composer.json` эксперимента **не ставится** (его публичный `POST /stripe/webhook` записывал бы оплату мимо §3.3); `stripe/stripe-php` — прямой зависимостью (Lunar core от `lunarphp/stripe` не зависит). Webhook AIQME — `/webhooks/stripe` и `/webhooks/telegram` |
| Livewire | 3.8.10 | там же |
| Filament | 4.15.2 (зависимость панели Lunar) | там же |
| Stripe PHP SDK | 16.6.0 | там же |
| Уже в дереве зависимостей Lunar | spatie/laravel-medialibrary 11.23.9, spatie/laravel-activitylog 4.12.3, barryvdh/laravel-dompdf 3.1.2 (dompdf 3.1.6), laravel/scout 10.25.0 | используем вместо новых пакетов |
| Laravel Boost (dev) | 2.10.3; агенты по D-17 (по умолчанию Codex и Claude Code) | `boost.json`; MCP-конфигурации локальные, не в Git |
| БД | PostgreSQL 17 в эксперименте; в рабочей среде — **Рекомендация** PostgreSQL 17 (D-02) | эксперимент |
| Фронтенд | Blade + Livewire; Vite; JS-модули калькулятора, просмотра STL на Canvas 2D (без WebGL и пакета `three`), видео и лент. Разбор STL binary/ASCII — собственный в ядре W-10 по образцу `calculator.js`; координаты приводятся к float32 (`Math.fround` в JS, `unpack('g', pack('g', $x))` в PHP), чтобы JS и PHP давали одинаковый объём; STLLoader из `legacy-landing/tools/stl/` (пакет `three@0.186.1`) — только образец, в приложение не попадает | `legacy-landing/site/assets/`, V2 |

Смена любой версии — после проверки совместимости и полного набора тестов. Новые пакеты — только при доказанной необходимости; заранее разрешён `google/auth` `^1.55` для Sheets (§5.3) с зависимостями `firebase/php-jwt` и `psr/cache` (точные версии — в `composer.lock` приложения и `progress.md`).

**Локальный PHP (W-01).** У системного `/usr/bin/php` нет расширений для Lunar, Filament и PostgreSQL; они только в `aiqme-site/experiments/lunar-aiqme/runtime/` (исключён `.gitignore`). `aiqme-site/bin/php` повторяет обёртку эксперимента: `/usr/bin/php` с `PHP_INI_SCAN_DIR` и `LD_LIBRARY_PATH` на этот runtime, который только читается. Это локальная зависимость машины (записывается в `progress.md`); на сервере — образ W-20 `php:8.4-fpm` + `docker-php-ext-install` с теми же расширениями; GD — `docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp` с `libfreetype-dev`, `libjpeg62-turbo-dev`, `libpng-dev`, `libwebp-dev`; apt из `deb.debian.org` — только внутри `docker build` (D-20). На рабочей машине `apt`/`sudo` не используются. Dev-PostgreSQL — порт 55440 (55439 занят экспериментом).

**Каталог приложения.** Laravel-приложение — прямо в `aiqme-site/` рядом с `README.md` и `experiments/`. Скелет создаётся в игнорируемом `aiqme-site/build/skeleton/` (`composer create-project` не работает в непустом каталоге) и переносится без замены README и experiments. `pint.json` (`"exclude": ["experiments", "build", "demo"]`), `phpunit.xml`, Vite и `@source` Tailwind не включают `experiments/` (иначе Pint переформатирует эталон).

**Boost и агенты (D-17).** Boost 2.10.3 в `aiqme-site/` настраивается на `codex` и `claude_code`: `.codex/config.toml` и `.mcp.json` с абсолютными путями (`artisan boost:mcp` через `bin/php`) исключены корневым `.gitignore`. Boost генерирует `aiqme-site/AGENTS.md` и `CLAUDE.md`; корневой `AGENTS.md` не перезаписывается. Уточнения правил Boost для автономной работы (в том числе отказ от `search-docs` и команды PHP через `bin/php`) — в [tasks.json](tasks.json) `rules`. Инструменты `database-schema` и `browser-logs` пригодны. Claude Code по D-28 запускается из корня с `--mcp-config aiqme-site/.mcp.json` (в `.mcp.json` — абсолютные пути к `aiqme-site/bin/php` и `artisan`: Boost пишет относительный `artisan` без `cwd`); Codex берёт `aiqme-site/.codex/config.toml` только при запуске из `aiqme-site/` в доверенном проекте (W-01). Boost MCP необязателен.

## 2.2. Граница торгового ядра и сервисов AIQME

| Lunar отвечает за | AIQME отвечает за |
|---|---|
| Корзина как конвейер создания заказа (`Cart::add/updateLine/remove/createOrder`), валидатор перед созданием заказа | Приём STL, оценка, порядковый номер, гостевой доступ |
| Заказ и строки заказа с суммами в центах EUR, клиенты (`Customer`) и адреса заказа | Подтверждение и изменение цены, история согласования, состояние производства |
| Транзакции (`lunar_transactions`) как учёт поступивших денег и возвратов | Платёжные запросы (аванс, остаток), Stripe-адаптер, webhook, сверка |
| Валюта EUR, налоговые классы (**нулевая ставка**, чтобы Lunar не добавлял демо-НДС; налоги считает счёт AIQME). Базовые данные Lunar (канал, языки lt/en, EUR по умолчанию, группа клиентов, налоговая зона и класс без ставок) создаёт идемпотентная команда `aiqme:bootstrap` (W-12), а не штатный установщик Lunar и не сидер-фикстуры эксперимента | Счета: реквизиты, VAT, нумерация, PDF |
| — | Весь контент, включая товары и бренды каталога; переводы; публикация; Telegram; Sheets; почта |

**Каталог.** Товары, бренды и категории — в контентной модели AIQME, не в каталоге Lunar: нужна публикация по каждому языку (В-017), общие редакторы веба и Telegram и заглушка, а продажа не утверждена (D-12); каталог Lunar публикуется одним статусом на все языки. Включение продажи — отдельной задачей (связь карточки с вариантом Lunar).

Lunar не форкается, `vendor/` не редактируется. Используются только документированные точки расширения: `Purchasable`, валидаторы корзины, генератор номера заказа, транзакции.

## 2.3. Роль штатной панели Lunar

Админка одна — собственная, в оформлении прототипа (Blade/Livewire). Штатная Filament-панель Lunar регистрируется **только в локальной среде** (флаг `AIQME_LUNAR_PANEL=true`, по умолчанию выключен) как инструмент разработчика. В рабочей среде маршрута `/lunar` нет: действия панели (смена статуса, правка транзакций) обходили бы правила заказа. Расширение `ManufacturingOrderExtension` из эксперимента не переносится. Модель `Staff` не нужна: администратор — `User` с флагом `is_admin`.

## 2.4. Модули приложения

Приложение — `aiqme-site/`; `aiqme-site/experiments/` в него не входит. Своего Git у `aiqme-site/` нет: весь корень — публичный репозиторий (D-14), приватное и секреты исключены корневым `.gitignore`. Push без разрешения запрещён.

```
aiqme-site/app/
  Domain/Orders/      OrderSubmission, OrderWorkflow, ProductionStage, PriceRevision, OrderNumber
  Domain/Pricing/     StlAnalyzer (PHP), EstimateService, CalculatorSettings
  Domain/Payments/    PaymentRequestService, StripeInstallments, StripeWebhookHandler, Reconciliation
  Domain/Invoices/    InvoiceService, InvoiceNumberer, SellerSettings, InvoicePdf
  Domain/Content/     ContentService, ContentTypes, Publication, Placeholders, UiTexts, MediaService
  Domain/Forms/       ContactRequest, TrainingRegistration
  Domain/Integrations/ IntegrationEvents (outbox), SheetsProjector, Mailer, TelegramBot
  Domain/Audit/       AuditLog (поверх spatie/activitylog)
  Http/               публичные контроллеры, кабинет, админка (Livewire), webhook-и
```

Контроллер, Livewire-компонент, обработчик Telegram и консольная команда вызывают **один и тот же сервис** и не содержат бизнес-правил. Права проверяются в сервисе (единственный администратор — `Gate::define('admin')`; клиент — владелец объекта: вошедший и с непустым `user_id` объекта, §6.1; гость — право в сессии по секретному токену заказа).

## 2.5. Данные (новые таблицы AIQME)

| Таблица | Назначение | Ключевые ограничения |
|---|---|---|
| `users` (+`is_admin`), Lunar `customers` | Клиент и администратор | Саморегистрация не ставит `is_admin`; администратора создаёт команда `aiqme:make-admin` (скрытый ввод или `--password-stdin` без терминала, D-29) |
| `customer_profiles` | Профиль «Addresses & company» клиента: имя, компания, код компании, VAT-код, адрес, город, индекс — всё необязательно (W-07) | Один на `users.id`; подставляется в форму заявки (W-12); выпущенные счета хранят свой снимок (§3.5) |
| `stl_files` | Приватный STL: владелец (user или гостевой заказ), путь, исходное имя, размер, sha256, результат анализа (объём, габариты, треугольники, версия анализатора) | Путь назначает сервер; файл вне `public/` |
| `stl_parts` | `Purchasable` строки заказа: ссылка на `stl_files`, параметры, пожелания, оценка | Как `StlPart` эксперимента |
| `order_charges` | `Purchasable` служебные строки заказа: вид `fee` (плата за заказ и за файлы, W-12) или `delivery` (доставка, W-13), сумма в центах, ссылка на заказ | Как `StlPart`; правила — §3.1 |
| `counters` | Счётчики номеров: заказы (W-12) и счета по серии (W-17) | Выдача — атомарный `UPDATE … SET value = value + 1 RETURNING value` в транзакции (§3.1, §3.5) |
| `order_meta` | Номер заказа, гостевой токен (хеш), токен отправки `submission_token` и отпечаток данных отправки `submission_digest` (§3.1), снимок настроек калькулятора, контакты, способ получения, локаль заявки (`lt`/`en`; язык счёта, §3.5) | Номер уникален; `submission_token` уникален (нарушение уникальности — ответ «тот же заказ»); гостевой токен хранится только как хеш |
| `production_states` | Стадия, `confirmed_at`, `started_at`, ревизия цены | Изменения цены запрещены после `started_at` |
| `price_revisions` | Было/стало по строкам, основание и ссылка на согласование, автор | Уникально `(order_id, revision)` |
| `payment_requests` | `public_id` (UUID: адрес страницы оплаты, ключ идемпотентности `aiqme-request-<public_id>`, `metadata.request_id`), `order_id`, сумма, валюта, `state` ∈ `creating/pending/cancelling/void/paid` (автомат — §3.3), флаг `needs_review` и `review_reason`, `stripe_intent_id` (пуст до первого открытия страницы оплаты), idempotency key, `intent_first_attempt_at` (ставится в транзакции 1 только при первом `pending → creating`, не меняется повторами; от него 24 часа §3.3). Заряды не хранятся — они на транзакции Lunar `capture` (§3.3 «Запись») | `public_id` уникален. Ограничения суммы — §3.3 «Создание запроса» (в центах: ≥ 50, остаток после запроса 0 или ≥ 50). Частичный уникальный индекс `lunar_transactions (reference) WHERE type = 'capture'` |
| `payment_events` | Входящие события Stripe: `event_id` PK, digest (только для журнала: повтор `event_id` с действительной подписью и другим телом — без эффекта и 200; W-28), ссылка на запрос (nullable), `result` (`applied/ignored/rejected/needs_review`), `reviewed_at` | Повтор события — без эффекта (кроме повтора `charge.refunded` с прежним `needs_review`, §3.3 «Возвраты»); исходы — §3.3 «Ответ webhook» |
| `order_messages` | Сообщение администратора и ответ клиента (`agree` / `question` / `cancel_request`, §1.3; сервис — W-13, админка — W-14, кабинет и гостевая страница — W-15) | Ответ ничего не меняет автоматически; пишет только владелец или гость с правом на заказ (§6.1) |
| `invoices`, `invoice_lines` | Номер, серия, вид, снимок реквизитов продавца и покупателя, суммы, VAT, путь PDF | Уникально `(series, number)`; уникально основание выпуска |
| `content_items`, `content_translations`, `content_revisions`, `ui_texts` | Контент всех типов; перевод на локаль со своим slug, полями, SEO, `published_at`, `revision`; прежние версии | Уникально `(locale, type, slug)`; сохранение с устаревшей ревизией отклоняется |
| `contact_requests`, `training_registrations` | Заявки форм и вложения | — |
| `integration_events` | Outbox для Sheets и почты: тип, сущность, данные (включая `summary` и `amount` для листа `Events`); доставка **по каналам** — `mail_status` и `sheets_status` (`pending/sending/sent/failed/none`), у каждого свои попытки, последняя ошибка и время захвата (`mail_claimed_at`, `sheets_claimed_at`) | Пишется в транзакции изменения (§2.7). Канал `sheets` создаётся `pending` уже в W-09, обрабатывается с W-18; повтор одного канала не повторяет другой |
| `telegram_bindings`, `telegram_dialogs`, `telegram_updates` | Привязанный chat id администратора, состояние диалога, дедупликация `update_id` | Один привязанный чат |
| `settings` | Ключ → JSON-значение (таблицу создаёт W-11, W-17 и W-18 добавляют свои ключи): реквизиты продавца, VAT, нумерация, ссылка на Google Таблицу (редактируется в Integrations, W-18), настройки калькулятора (версионный снимок) | Секреты сюда не пишутся — только в `.env` |

Деньги — целые центы EUR; правило округления оценки — §3.1. Время — UTC, вывод в часовом поясе `Europe/Vilnius`. Удаление аккаунта не каскадирует на заказы, платежи и счета.

## 2.6. Что переносится из эксперимента и с какими исправлениями

Код эксперимента — проверенная наработка, не production-код: переносится с исправлениями и тестами.

| Наработка | Переносим | Обязательные изменения |
|---|---|---|
| `OrderWorkflow` (подтверждение цены, запрос оплаты, производство, отмена, баланс). `recordVerifiedReceipt` не переносится: оплату записывает один внутренний сервис записи `capture` по проверенным данным Stripe (§3.3 «Подтверждение»); создаёт его W-16, webhook W-28 переиспользует; публичного метода, HTTP-маршрута и консольной команды записи оплаты нет | Правила и проверки | (1) Отмена заказа отменяет открытые запросы по автомату §3.3 (§3.4), а не блокируется навсегда; `requestPayment` создаёт запрос в `pending` без intent. (2) Поздняя оплата отменённого заказа и переплата **записываются** транзакцией с пометкой «на сверку», webhook отвечает 200 (исключение и откат эксперимента заставили бы Stripe повторять событие). (3) Стадии производства (§3.4). (4) `$fillable` вместо `$guarded = []` |
| `StripeInstallments` | Два автоматически списываемых PaymentIntent, сумма из `PaymentRequest`, metadata, idempotency key на запрос, проверка webhook | Intent — при первом открытии страницы оплаты; **сетевой вызов вне транзакции БД**, с таймаутом; транзакции 1 и 2 и неизвестный исход — §3.3 «Создание PaymentIntent», «Неизвестный исход» (новый intent не создаётся). Live-режим — только в рабочей среде по конфигурации; test/live ключи и секреты webhook раздельны |
| `BasketService`, `RequestCartValidator`, `StlPart` | Корзина Lunar → заказ без платёжного адреса | Гость (корзина без `user_id`, владелец файлов — заявка). Цена строки — серверная оценка `EstimateService`, а не 50/70 EUR из `StlIntake::unit` |
| `StlIntake` | Хранение с sha256, удаление файла при ошибке | Проверка структуры STL в PHP (`StlAnalyzer`), лимиты из конфигурации (§6.4); запись файла и строки согласованы — без «сирот» |
| `ContentPublisher` | Проверка ревизии при сохранении | Полная контентная модель (§4) |
| `LocalTestAdapter`, `comparison:demo`, фикстуры `buyer@/admin@example.test`, пароль `local-probe-only`, `.env` эксперимента | **Не переносятся**. Оплата в тестах до W-28 — тестовая фабрика транзакции `capture` в `tests/`, с W-28 — подписанные фикстуры webhook | — |
| UI эксперимента (`shared/views`) | Не переносится | Интерфейс — по прототипу заказчика |
| 38 общих тестов | Основа набора тестов с новыми именами | Плюс тесты на исправления выше |

## 2.7. Фоновое выполнение и повторы

**Рекомендация** (D-03): очередь Laravel `database`, один `queue:work` (supervisor/systemd или контейнер), `schedule:run` раз в минуту. Без Redis (В-060).

- Внешние вызовы (почта, Sheets, Telegram-ответы, Stripe) никогда не выполняются внутри транзакции БД.
- События для Sheets и почты пишутся в `integration_events` в транзакции изменения той задачей, что создаёт изменение (W-09 формы, W-12 заявка, W-13 цена, запросы и стадии, W-16 отмена запросов с intent, W-28 оплата и возвраты, W-17 счёт); W-18 только проецирует их в листы; задание ставится `afterCommit`. Планировщик раз в 5 минут подбирает (по каждому каналу) недоставленные каналы старше 5 минут и `sending`, захваченные больше 5 минут назад (защита от потерянной постановки в очередь и упавшего задания); задание канала захватывает его атомарно `UPDATE integration_events SET mail_status = 'sending', mail_claimed_at = now() WHERE id = ? AND (mail_status IN ('pending', 'failed') OR (mail_status = 'sending' AND mail_claimed_at < now() - interval '5 minutes'))` (для `sheets` — свои поля), при 0 строк выходит, отправляет вне транзакции и ставит `sent` или `failed` — письмо не уходит дважды (W-09). Канал `sheets` ставит в очередь W-18.
- Повторы: `tries`/`backoff` с нарастанием; ошибки видны в Overview админки. Сбой почты или Sheets не откатывает заказ, оплату или правку.
- Webhook Stripe и Telegram отвечают 2xx только после сохранения события; повтор события обрабатывается без эффекта.

## 2.8. Размещение рядом с другими сайтами

Подробно — [§8](08-operations.md). Своё у приложения: БД и пользователь БД, `.env` и `APP_KEY`, каталоги хранения и логов, процесс очереди и cron, домен. Общих с другими проектами секретов, сессий и кэша нет.
