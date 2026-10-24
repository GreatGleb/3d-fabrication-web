# Тестовые подключения — уровень T (W-22)

Проверка с реальными тестовыми ключами и адресатами на этом компьютере. Ключи — только в `aiqme-site/.env` (не в Git),
в отчёте их нет. ID таблицы, chat/user id и адреса почты маскируются (видны последние 4 символа); идентификаторы
Stripe test (`pi_…`, `evt_…`) пишутся полностью.

Стенд: локальное приложение `bin/php artisan serve` на `http://127.0.0.1:8010` (`APP_URL` переопределён в окружении
процессов: порт 8000 занят другим проектом), PostgreSQL dev-compose, обработчик `queue:work --queue=default,mail,sheets`.
Перед проверкой к локальной базе применены 4 миграции от 21.10, не применённые после ночного прогона (код не менялся).

| Подключение | Статус | Дата | Тестовый объект |
|---|---|---|---|
| Telegram-бот | **T-готово** | 2026-10-24 | бот `@aiqme_test_1_bot`, чат `…7183`, проект id 37 |
| Google Таблица | **T-готово** | 2026-10-24 | таблица `…csM8`, заявка `contact-1`, заказ 1 |
| Stripe test | **T-готово частично**: возврат и отмена до webhook не проверены | 2026-10-24 | заказ 1; `pi_3UU6GeRsOySOxLMy1k3Hjbus`, `pi_3UU6IVRsOySOxLMy1HRDsB5X`, `pi_3UU6NgRsOySOxLMy0uUjCkDs` |
| Почта (SMTP) | **нет доступа** (D-15: ящик не выбран; отложено пользователем) | 2026-10-24 | — |

## Telegram-бот

Бот создан пользователем в @BotFather, токен — в `TELEGRAM_BOT_TOKEN`. Доставка — `aiqme:telegram-poll` (W-19,
`APP_ENV=local`), без публичного адреса и webhook (`getWebhookInfo`: адрес не задан). `getMe`: `ok`.

| Шаг | Результат |
|---|---|
| Привязка | код с экрана Integrations → `/start <код>` в личном чате бота → «Chat linked»; в `telegram_bindings` user и chat `…7183`, `bound_at` 2026-10-24 11:27:48 UTC, хеш кода стёрт, `last_error` пуст; экран Integrations — «Connected» |
| Код не тому боту | `/start <код>` в @BotFather — привязки нет (ожидаемо); код после этого заменён новым |
| Создание черновика | Projects → Add a draft → заголовок и URL (LT) → проект id 37, LT, черновик |
| Правка | поле Task → «Saved», ревизия LT 2 |
| Медиа | «+ Image» → JPEG 21 414 Б в коллекции `images`, файл на диске; конверсии w480, w960, w1600 выполнены очередью |
| До публикации | `/lt/projektai/testas-w22` и `/en/projects/testas-w22` — 404 |
| Публикация LT | «Published: LT»; `published_revision` = 2; LT-страница — 200, заголовок, текст Task и изображение WebP (200); в списке `/lt/projektai` проект есть; EN — по-прежнему 404 |

**Найденная ошибка (не исправлялась в W-22).** Первую EN-версию записи с адресом (service, project, article, product,
category) через бот создать нельзя: бот сохраняет поле по одному (`TelegramBot::saveField`), а `ContentTypes::rules`
требует сразу `title` и `slug`; ответы «Invalid value: slug» / «Invalid value: title». Воспроизведено на проекте id 37
в транзакции с откатом (EN → 1 Title → текст; EN → 2 URL → текст). Обход — первая EN-версия в веб-админке; ограничение
описано в [инструкции администратора](../admin/telegram-bot.md#английская-версия-en). Остальные шаги Telegram от этого не зависят.

**Исправлено 24.10.2026.** Для первой версии перевода у типов со slug бот спрашивает заголовок, затем URL и сохраняет
их одним черновиком (как при создании LT-записи); тест `test_first_en_version_of_an_item_with_slug_asks_title_then_url`,
ограничение из инструкции администратора убрано. Перепроверено на тестовом боте (коммит `b7ee495`): проект id 37 →
EN → поле Task → бот запросил заголовок, затем URL → «Draft saved.», EN ревизия 1 → Publish EN;
`/en/projects/test-w22` — 200, hreflang LT/EN указывают на обе версии, LT-страница не изменилась.

Тестовый проект id 37 остаётся в локальной базе (черновик/LT); в рабочую базу не переносится.

## Google Таблица

Тестовая таблица пользователя (`…csM8`) с листами Orders, Requests, Contacts, Payments, Events; сервисный аккаунт
отдельного проекта Google Cloud с правом редактора, ключ JSON — вне репозитория, в `.env` — `GOOGLE_SHEETS_KEY_BASE64`
и `GOOGLE_SHEETS_SPREADSHEET_ID`. Обработчик очереди перезапущен после записи ключей.

| Шаг | Результат |
|---|---|
| Доступ | токен сервисного аккаунта получен; `spreadsheets.get` — 200, видны все 5 листов |
| `aiqme:sheets-rebuild` | `RebuildSheets` выполнен за 4 с; на всех 5 листах записаны заголовки (данных в локальной базе ещё не было) |
| Контактная форма `/lt/kontaktai` с 1 вложением | событие 1 `contact_request.created`, mail и sheets — `sent`; через ~3 с строки: Requests (`contact-1`, contact, вложений 1, ссылка в админку), Contacts (`…com`, не зарегистрирован), Events (1) |
| Вложение формы | PNG 98 453 Б в `storage/app/private/contact-requests/` под UUID, SHA-256 совпадает с записанным; в таблицу — только число вложений; по `/storage/…` и по ссылке админки без входа — 404 |
| Заказ через калькулятор (`cube-10mm-binary.stl`) | заказ 1, событие 2 `order.submitted`, mail и sheets — `sent`; Orders: стадия review, оплата unpaid, доставка ship, ссылка в админку; Events: 2, сумма 5.04; Contacts — та же строка обновлена (имя, телефон, последняя активность), дубля нет |
| Payments | запросы `pending` → `void` при отмене → `paid` со ссылкой на charge после оплаты; Orders — оплачено/долг и `partial`; Events — `payment.succeeded`, `payment.failed` (см. Stripe) |

Тестовые заявка и заказ остаются в локальной базе и в тестовой таблице.

## Stripe test

Тестовый аккаунт (sandbox, `acct_…xLMy`, LT, EUR, `livemode=false`); ключи `pk_test`/`sk_test` — в `.env`. **Версия API
аккаунта и событий — `2026-09-30.endive`** (новее 2022-11-15, `--latest` не нужен). Stripe CLI:
`stripe listen --events payment_intent.succeeded,payment_intent.payment_failed,charge.refunded --forward-to
http://127.0.0.1:8010/webhooks/stripe` (новая версия CLI требует явный `--events`; это три типа, которые обрабатывает
сайт); секрет `listen` — в `STRIPE_WEBHOOK_SECRET`. Заказ 1 (калькулятор, гость), цена подтверждена — 20,04 EUR.

| Сценарий | Результат |
|---|---|
| Доступ к оплате | ссылка `/lt/apmokejimas/<public_id>`: администратору — страница без формы оплаты; гостю без права в сессии — 404; гость по гостевой ссылке заказа (перевыпуск в карточке, письма клиенту нет — В-052) — форма Payment Element, способ — только `card` (D-33) |
| Отмена запроса без intent | запрос 9,00 EUR → `void`; событие `payment_request.cancelled` в Sheets |
| Успех (`4242…`), аванс 10,00 | `pi_3UU6GeRsOySOxLMy1k3Hjbus`, `evt_3UU6GeRsOySOxLMy1wNTP7PS` → 200, `applied`; capture `ch_3UU6GeRsOySOxLMy1TKKmlxr`; заказ: оплачено 10,00, долг 10,04, `partial` |
| Отказ (`4000…0002`) | charge `ch_3UU6IVRsOySOxLMy1zC4ayyB` `card_declined`; `evt_3UU6IVRsOySOxLMy1WHUrTjZ` (`payment_intent.payment_failed`) → 200, `ignored`; запрос остался неоплаченным, событие `payment.failed` |
| 3D Secure (`4000 0027 6000 3184`), тот же запрос 1,04 | 3DS `authenticated`; `pi_3UU6IVRsOySOxLMy1HRDsB5X`, `evt_3UU6IVRsOySOxLMy1pC8nmvJ` → 200, `applied`; capture `ch_3UU6IVRsOySOxLMy1nOdiu7R` |
| Повтор события | `stripe events resend evt_3UU6GeRsOySOxLMy1wNTP7PS` → 200; новых payment_events, транзакций и событий outbox нет |
| Остаток 9,00 | `pi_3UU6NgRsOySOxLMy0uUjCkDs`, `evt_3UU6NgRsOySOxLMy0mRiEAWk` → 200, `applied`; capture `ch_3UU6NgRsOySOxLMy0LXLnNJ9`; заказ оплачен полностью (20,04 / 0) |
| Отмена оплаченного запроса | «Cancel request» в необновлённой карточке **после** webhook: отмены нет, запрос остаётся `paid` (ответ «нельзя отменить»). Ветка «отмена раньше webhook → Stripe `payment_intent_unexpected_state` → запись оплаты, ответ «Stripe: payment received and recorded.»» **не проверена** (нужен остановленный `stripe listen`) |
| Возврат в Dashboard (`charge.refunded`) | **не проверен** (отложено пользователем) |
| CSP | заголовок: `script-src … https://js.stripe.com https://*.js.stripe.com`, `frame-src https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com`, `connect-src 'self' https://api.stripe.com`; сверено с docs.stripe.com/security/guide (раздел Content Security Policy, Stripe.js) 24.10.2026 — совпадает; `maps.googleapis.com` (Address Element) и Link (`link.com`) не нужны — не используются. Консоль Firefox при оплате с 3D Secure: нарушений CSP нет (только предупреждения Stripe об HTTP/Apple Pay и блокировка `m.stripe.network` защитой от отслеживания Firefox) |

Счёт после полной оплаты не выпущен — ожидаемо: реквизиты продавца в Settings не заполнены (предупреждение Overview).

## Почта (SMTP)

**Нет доступа**: рабочий ящик домена не выбран (D-15), тест через личный ящик пользователь отложил. Письма сайта
(заявка, заказ) уходят в локальный Mailpit — уровень L. Для проверки: SMTP-данные в `.env` (`MAIL_*`) и одно письмо на
разрешённый адрес; на info@aiqme.com — только по разрешению владельца.

## Найдено попутно (передано в отдельную сессию)

- Тесты читают реальные ключи интеграций из `.env` (в `phpunit.xml` они не обнуляются): при заполненном `.env`
  `AdminOrdersTest::test_overview_counts_…` падает («Failed background tasks: 8»), с пустыми ключами — 18/18. Внешних
  вызовов не было (`Http::preventStrayRequests`, в Stripe только платежи этой проверки).
- Подсказка платёжного запроса (`fb5906c`) у полностью оплаченного заказа пишет «доступно €0.00, меньше минимума» —
  лучше «заказ оплачен полностью».
- Иллюстрации услуг на главной — фрагменты спрайта прототипа 267×121 px (D-43), растянутые на карточку; лучшего
  исходника нет, нужны фото заказчика. Подпись прототипа «Demonstracinė iliustracija» на сайте не выводится.
