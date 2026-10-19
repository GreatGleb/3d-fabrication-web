# 5. Интеграции

[← План](README.md). Обязательные подключения: **Stripe, почта, Google Таблицы, Telegram**, позже домен aiqme.com. TEJA, перевозчики и автоматизация Bambu не подключаются. Уровни L / T / P — [README](README.md#три-уровня-готовности).

## 5.1. События

| Событие | Почта | Google Таблицы | Прочее |
|---|---|---|---|
| Контактная форма принята | Письмо на info@aiqme.com | `Requests` + `Contacts` + `Events` | — |
| Регистрация на обучение | Письмо на info@aiqme.com | `Requests` + `Contacts` + `Events` | — |
| Заявка на изготовление отправлена | Письмо на info@aiqme.com | `Orders` + `Contacts` + `Events` | — |
| Цена подтверждена / изменена | — | `Orders` (обновление) + `Events` | Журнал |
| Платёжный запрос создан / отменён | — | `Payments` + `Orders` + `Events` | — |
| Оплата прошла / не прошла / на сверку / возврат | — | `Payments` + `Orders` + `Events` | Журнал |
| Стадия производства, трек, отмена | — | `Orders` + `Events` | Журнал |
| Счёт выпущен | — | `Orders` (номер счёта) + `Events` | — |
| Восстановление пароля | Письмо пользователю | — (токены никуда не пишутся) | — |

Событие пишет в `integration_events` задача, создающая изменение, в той же транзакции (§2.7); W-18 только проецирует события в листы. Других писем нет: подтверждений клиенту, напоминаний и рассылок нет (В-052). Письмо и строка таблицы создаются только после сохранения основной записи; их сбой её не отменяет и успехом не показывается.

## 5.2. Почта

- Laravel Mail. Локально — Mailpit в Docker. Тестовый и рабочий транспорт — SMTP ([D-15](decisions.md#d-15), там же проверка MX/SPF/DKIM/DMARC перед рабочим подключением).
- From — адрес домена из конфигурации; Reply-To — email клиента после валидации; пользовательский ввод с переводами строк в заголовки не попадает.
- Письмо формы: тип, имя, контакты, услуга, краткое описание, число вложений, ссылка в админку. Вложения и полный текст — только в админке.
- Письмо сброса: ссылка из `APP_URL`, одноразовый токен со сроком; GET по ссылке пароль не меняет.

## 5.3. Google Таблицы

- Односторонняя запись с сайта, на английском (В-053/054); правки в таблице сайт не меняют. Вместо экспорта — ссылка на таблицу: хранится в `settings`, редактируется в Integrations, выводится в Integrations и Customers (W-18); доступ — по правам самой таблицы, публичной она не становится.
- Сервисный аккаунт Google с доступом к одной таблице; Sheets API v4 через `google/auth` и HTTP-клиент Laravel (рекомендация: без тяжёлого `google/apiclient`); `google/auth` `^1.55` с новыми зависимостями `firebase/php-jwt`, `psr/cache` заранее разрешён ([§2.1](02-architecture.md#21-стек-и-версии); guzzle и psr7 уже в lock эксперимента). Ключ — JSON в base64 в переменной `.env`; отдельного файла и монтирования нет (`.env` уже монтируется в PHP-сервисы, §8.3); в репозиторий не кладётся.
- Листы и колонки (английские заголовки, первая строка):
  - `Orders`: Order No, Created (UTC), Updated, Customer, Email, Phone, Company, Files, Production stage, Confirmed price EUR, Paid EUR, Due EUR, Payment state, Delivery, Tracking, Invoice No, Admin link.
  - `Requests`: Request ID, Type (contact/training), Created, Name, Email, Phone, Company, Service/Training, Summary (до 200 символов), Attachments, Admin link.
  - `Contacts`: Email (ключ), Name, Phone, Company, Company code, VAT code, Registered (yes/no), Last activity. `Registered` и `Last activity` вычисляются из БД при проекции любого события с этим email и при пересборке; события регистрации нет.
  - `Payments`: Payment request ID (ключ — `payment_requests.public_id`), Order No, Amount EUR, State, Created, Paid at, Stripe reference.
  - `Events`: Event ID (ключ), Occurred (UTC), Entity, Type, Summary, Amount EUR — «история изменений».
- Upsert по ключу (номер заказа, ID заявки, email, `public_id`, ID события), не по номеру строки: ручная сортировка или удаление строки не ломают обновление, пересборка восстановит строку. Запись `RAW` (формулы во вводе остаются текстом). Деньги — строка `123.45`; «неизвестно» не пишется как 0.
- Один писатель: очередь `sheets` с одним worker-ом, `WithoutOverlapping` по сущности; перед записью задание перечитывает состояние из БД.
- Слои: `SheetsClient` — тонкий клиент (`getValues(range)`, `batchUpdate(data)`) на HTTP-клиенте Laravel; токен — внедряемый поставщик (на `google/auth`; в тестах заглушка, запросов к `oauth2.googleapis.com` нет); upsert — в `SheetsProjector`. Клиент проверяется через `Http::fake` (адрес, `valueInputOption=RAW`, тело `batchUpdate`, 429), upsert — на фейке сетки листа (тест переставляет и удаляет строки).
- `aiqme:sheets-rebuild` ставит пересборку в ту же очередь `sheets` и пересобирает листы из БД без повторных писем: пакеты ≤5 000 строк на запрос (рекомендуемый предел — 2 МБ), строки ниже последней очищаются пустыми значениями (W-18).
- Квота: 300 запросов/мин на проект и **60/мин на пользователя на проект**; сервисный аккаунт — один пользователь, значит предел 60/мин ([лимиты](https://developers.google.com/workspace/sheets/api/limits)). Обычные события его не достигают; пересборка пишет `values:batchUpdate`, 429 → повтор с нарастающей задержкой.
- Время в данных — UTC; отображение — на усмотрение владельца.

## 5.4. Telegram

Поведение — [§4.5](04-content-seo.md#45-telegram-бот-контент). Технически (команды и клиент — W-19):

- Тонкий клиент Bot API на HTTP-клиенте Laravel (sendMessage, editMessageText, answerCallbackQuery, getFile, setWebhook, deleteWebhook, getUpdates), без пакетов. Адрес содержит токен (`/bot<TOKEN>/…`): клиент перехватывает исключения HTTP-клиента и вырезает токен из сообщения до записи в журнал или последнюю ошибку (тест).
- Webhook `/webhooks/telegram` на рабочем HTTPS-домене регистрирует `aiqme:telegram-webhook` после выпуска TLS (W-23): `setWebhook` с `APP_URL/webhooks/telegram`, `secret_token` из `TELEGRAM_WEBHOOK_SECRET` (1–256 символов `A-Z a-z 0-9 _ -`), `allowed_updates` = `message`, `callback_query`.
- Локально — тесты на записанных фикстурах апдейтов. Тестовый бот (уровень T, W-22) создаётся через @BotFather на аккаунте владельца и проверяется `aiqme:telegram-poll`: только при `APP_ENV=local`, `deleteWebhook`, затем `getUpdates` в цикле до Ctrl+C, апдейты — тому же обработчику, что у webhook, с дедупликацией `update_id`. До W-23 публичного HTTPS нет; long polling в рабочей среде не используется.

## 5.5. Stripe

Поведение — [§3.3](03-orders-payments.md#33-платёжные-запросы-и-stripe).

| Уровень | Что делаем | Нужен доступ |
|---|---|---|
| L | Мок `StripeClientInterface` на границе SDK; события с `Stripe-Signature: t=…,v1=…`, где `v1` — HMAC-SHA256 от `"{t}.{payload}"` с явно фиктивным секретом вида `test-webhook-secret-not-real` (не `whsec_…`, чтобы не срабатывала защита push GitHub); проверка — настоящим `Stripe\Webhook::constructEvent` (функция подписи в SDK 16.6 приватная); live-события в нерабочих средах отклоняются | Нет |
| T | Stripe test mode на аккаунте владельца; тестовые ключи в `.env`; доставка webhook — `stripe listen --forward-to <локальный адрес>/webhooks/stripe` (события в версии API аккаунта по умолчанию; если она старше 2022-11-15 — `stripe listen --latest`; версия записывается в `test-connections.md`); карты успеха, отказа и 3DS; закрытая вкладка; повтор события из Dashboard; аванс + остаток; ручной возврат в Dashboard → `charge.refunded` | Тестовые ключи владельца |
| P | Live-ключи и отдельный webhook-секрет, endpoint на рабочем домене, контрольный платёж по согласованию владельца | Аккаунт продавца, реквизиты, разрешение |

- Секреты test и live не смешиваются: разные переменные, проверка `livemode` каждого события.
- Endpoint (T и P) — `/webhooks/stripe`, версия API `2025-02-24.acacia` (SDK 16.6.0; форму объекта задаёт версия endpoint, не SDK; `latest_charge` есть с 2022-11-15, более старое событие — `needs_review`, §3.3); подписан только на `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`. Маршрута `/stripe/webhook` нет (§2.1).
- Аккаунт Stripe считается выделенным AIQME: чужой `succeeded` без `metadata.request_id` получает `needs_review` (исход 3) — проверяется на уровне T.

## 5.6. Не подключаем

TEJA (TODO после релиза), API перевозчиков и ярлыки, автозапуск Bambu Studio, Stripe Tax, Stripe Invoicing, внешние антивирусы и аналитика. Блок в демо-админке обязательства не создаёт.
