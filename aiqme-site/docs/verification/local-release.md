# Итоговая локальная проверка — уровень L (W-21, 2026-10-23)

Итог: **L-готово, внешние подключения не проверены.** Всё ниже проверено на этом компьютере: PostgreSQL dev-compose,
локальный Mailpit, локальный prod-compose (W-20). Stripe, Google, Telegram и SMTP не вызывались: на уровне L они заменены
моками, фейками и подписанными фикстурами — это **не интеграция** (её проверяют W-22 и W-23). Ручной визуальный
просмотр — **ждёт проверки пользователем**. Публикации и push не было.

Код приложения после W-29 не менялся; W-21 добавила проверки (тесты и `bin/browser-check`) и пересобрала `demo/`.

## 1. Сводка проверок

| Проверка | Результат |
|---|---|
| `bin/check` (Pint `--test`, Node-тесты ядра, PHPUnit, Vite, границы Git и compose) | **OK**: Node 39/39; PHPUnit **656 passed, 0 skipped** (74 215 проверок); Vite собран |
| `bin/browser-check` (Playwright, bundled Chromium) | **OK**, 5 частей, **15 694 проверки, 0 ошибок**; запросов к чужим хостам нет; расхождений текста `main` с эталоном нет |
| Таблица §4.6 — 184 адреса route-manifest | **OK**: `PrototypeRoutesTest` — 184 адреса + проверка фикстуры, без пропусков |
| Словарь §7.2 (нет LT-строк ui_text на EN-страницах) — все страницы | **OK** (`PublicTextsTest`) |
| Маркеры D-21 по всем отрендеренным страницам | **OK**: совпадений вне `tests/fixtures/marker-allowlist.json` нет |
| Lighthouse 13.5.0 (mobile) | **OK**, см. §6 |
| Контролируемая поломка (6 из перечня + вариант 4б) | каждая роняет свой тест, см. §5 |
| Демо (`bin/demo-build`, `bin/demo-check`) | **OK**: 100 страниц, 11 189 проверок, 0 провалов; разделов оплаты и счетов нет |
| Сквозные сценарии §9.3 (W-30) | 5 из 5 пройдены, см. §4 |
| Стоп-лист §9.5 | пройден на уровне L, кроме пункта о рабочей среде (он для W-23), см. §7 |

`bin/browser-check` целиком идёт около 20 минут, а сессия ночного агента ждёт команду не дольше 10 минут. Поэтому прогон
разбит на 5 частей с теми же проверками. Каждая часть заново готовит БД `aiqme_browser`, данные фабрик и вход:
- часть 0: `--signed-in /en/contact` — вход, кабинет, админка, /ru/, карточки заказов, гостевая страница, оплата, сброс
  пароля (4 369 проверок);
- части 1–3: 97 публичных адресов из полного списка (3 436 + 3 994 + 3 354);
- часть 4: калькуляторы `/lt/kalkuliatorius` и `/en/quote` (541).

Журналы, кадры и отчёты сравнения каждой части лежат в игнорируемом `aiqme-site/build/verification/w21/`
(`part*.log`, `part*-frames/report.html`, `part*-frames/shots/`). Предыдущий полный прогон одной командой
(W-29, 2026-10-23 17:44) — тоже OK, 15 200 проверок.

## 2. Тесты §9.2 по областям

Все тесты ниже входят в `bin/check` и зелёные (656/656).

| Область §9.2 | Тесты | Статус |
|---|---|---|
| Ядро STL (JS и PHP) | `tests/js/*.test.js` (Node, 39), `StlAnalyzerLimitsTest`, `StlParityTest` (паритет JS ↔ PHP на корпусе), `EstimateServiceTest` | L-готово |
| Заявка | `OrderSubmissionTest`, `OrderNumberConcurrencyTest`, `OrderCartTest`, `CalculatorPageTest`; повторный заказ и «пересчитать» — `bin/browser-check` | L-готово |
| Права | `StlDownloadTest`, `CustomerAccountTest`, `PaymentRequestsTest`, `InvoicesTest`, `AuthTest`, `AdminShellTest`, `PrototypeRoutesTest` | L-готово |
| Цена и производство | `OrderWorkflowTest`, `AdminOrdersTest` | L-готово |
| Оплата | `PaymentRequestsTest`, `StripeWebhookTest`: мок `StripeClientInterface` и подписанные фикстуры с фиктивным секретом | L-готово; Stripe test — W-22 |
| Счета | `InvoicesTest` (PDF, `pdftotext`, номер, реквизиты TEST) | L-готово; реальные реквизиты — W-23 |
| Контент | `ContentServiceTest`, `AdminContentEditorTest`, `AdminEditorsTest`, `PublicSectionsTest`, `UiTextTest`, `PublicTextsTest`; сверка текста `main` с эталоном — `bin/browser-check` | L-готово |
| SEO | `PublicSeoTest`, `SitemapTest`, `LocaleRoutesTest`, `PrototypeRoutesTest` | L-готово |
| Формы | `FormsTest`; письма в Mailpit — `bin/browser-check` | L-готово; SMTP — W-22 |
| Sheets | `SheetsTest` (`Http::fake`, заглушка поставщика токена) | L-готово на фейке; тестовая таблица — W-22 |
| Telegram | `TelegramBotTest` (фикстуры апдейтов, `Http::fake`) | L-готово на фикстурах; тестовый бот — W-22 |
| Оформление | `bin/browser-check`, `ShellTest`, `ShellComponentsTest`, `HomePageTest`, `DefaultMediaTest`, `SecurityHeadersTest` | L-готово автоматически; ручной просмотр ждёт пользователя |
| Производительность | Lighthouse (§6); крупный STL (~25 МБ) в Web Worker и 500 000 треугольников — `bin/browser-check` | L-готово |
| Эксплуатация | `OperationsTest`, `TrustProxiesTest`, `BrowserCheckProdTargetTest`, `PhpRuntimeTest`; prod-compose, копия и восстановление — W-20/W-29 (`progress.md`) | L-готово локально; сервер — W-23 |

## 3. Матрица маршрутов и сверка оформления

- **LT/EN, публичные:** 99 адресов (все GET-страницы /lt и /en из `route:list`, сущности из `sitemap.xml`, поиск,
  фильтры каталога, сравнение 4 товаров, две 404) × 320/390/768/1024/1440. На каждой ширине: HTTP-статус, ширина документа
  = окну, консоль, нарушения CSP, ответы ≥ 400 на ресурсы.
- **Кабинет** (12 адресов) и **админка** (30 адресов) после входа через форму × 5 ширин; карточки заказа клиента и админки,
  гостевая страница и страница оплаты — на 390 и 1440.
- **RU:** 19 адресов /ru/ у администратора × 5 ширин: 200, `X-Robots-Tag` и meta `noindex`, ширина документа (добавлено
  W-21). Гостю и клиенту — 404.
- **getComputedStyle §7.1** — на всех шаблонах (публичные, кабинет, админка) на 1440/768/390. **Порядок блоков** — страницы
  W-05 и статьи W-06 из `tests/fixtures/prototype-blocks.json`.
- **Кадры** «наш рядом с эталоном» (размеры `verified-route-screens.json`) — `build/verification/w21/part1…4-frames/report.html`.
  Порога pixel-diff нет; ручной просмотр — **ждёт проверки пользователем**.

## 4. Сквозные сценарии §9.3 (из W-30)

Подробно — [scenarios-l.md](scenarios-l.md). Это Feature-тесты `tests/Feature/Scenarios/`, они входят в `bin/check` и зелёные
в этом прогоне.

| № | Сценарий | Итог на уровне L |
|---|---|---|
| 1 | Контактная форма с 2 вложениями → письмо на info@aiqme.com → Requests → админка | пройден (почта — `Mail::fake`, Sheets — фейк) |
| 2 | Гость: 2 STL → гостевая ссылка → 150 EUR → аванс 40 → производство при долге 110 → остаток → счёт → shipped → completed | пройден (оплата — подписанные фикстуры) |
| 3 | Клиент: регистрация → заявка → аванс 40 → отмена → «оплачено 40 EUR, отменён», возврата нет | пройден |
| 4 | Восстановление пароля, прежние сессии завершены | пройден; в браузере через Mailpit — `bin/browser-check` |
| 5 | Проект из Telegram только LT → 404 на EN → публикация EN → hreflang | пройден (фикстуры апдейтов) |

Сценарий 2 без шагов оплаты в локальном prod-compose пройден в W-20/W-29 (`bin/browser-check --base-url`, 83 проверки).
В W-21 он не повторялся: код приложения с тех пор не менялся.

## 5. Контролируемая поломка

Метод: временная правка → полный PHPUnit → возврат байт-в-байт (`git diff --quiet` после каждой). Итоговый `bin/check` после
всех поломок — зелёный.

| № | Поломка | Падающие тесты |
|---|---|---|
| 1 | Снятие проверки владельца у скачивания STL (`StlDownloadController`: только `$order !== null`) | `StlDownloadTest::test_client_order_stl_is_delivered_to_owner_and_admin_only`, `StlDownloadTest::test_guest_order_stl_has_no_owner_visitor_and_client_get_404`, `CustomerAccountTest::test_guest_page_grants_the_session_right_and_others_get_404_on_page_files_and_reply`, `CustomerAccountTest::test_calculator_with_models_query_downloads_only_own_files`, `AdminOrdersTest::test_stl_links_go_only_through_the_protected_download` |
| 2 | Отключение проверки подписи webhook Stripe (`Event::constructFrom` вместо `Webhook::constructEvent`) | `StripeWebhookTest::test_unsigned_foreign_secret_expired_or_malformed_events_get_400_without_a_record`, `StripeWebhookTest::test_route_is_outside_the_web_group_without_cookies_and_csrf` |
| 3 | `/webhooks/stripe` в группе `web` | `StripeWebhookTest::test_route_is_outside_the_web_group_without_cookies_and_csrf`. Тест сначала проверяет список middleware. Поведенческие части проверены отдельно, со снятой строкой структурной проверки: в группе `web` — **419** вместо 400 при включённом CSRF; в `web` без CSRF-middleware — **Set-Cookie** в ответе |
| 4 | Выдача второго номера счёта при повторе: снята проверка «счёт уже есть» в `InvoiceService::issueIfDue` | `InvoicesTest::test_overpayment_issues_the_invoice_on_the_confirmed_price_and_the_surplus_stays_for_review`, `CustomerAccountTest::test_card_shows_stages_payment_open_requests_transactions_tracking_and_reorder`. Второй номер при этом не выдаётся: уникальный индекс `invoices.order_id` откатывает выпуск вместе с номером |
| 4б | То же и без уникального индекса `invoices.order_id` в миграции: второй номер действительно выдаётся | `InvoicesTest::test_full_payment_in_two_steps_…` (повтор события и задания: «2 records were found»), `…test_overpayment_…`, `…test_webhook_queues_the_job_after_commit_…`, `…test_buyer_address_comes_from_the_request_or_the_profile_…` (запуск `InvoicesTest`) |
| 5 | Снятие идемпотентности токена отправки заявки (нет `repeat()` ни до, ни после конфликта уникальности) | `OrderSubmissionTest::test_double_submit_with_same_token_returns_same_order_and_reissues_guest_link`, `OrderSubmissionTest::test_same_token_with_other_data_is_rejected_without_writes` |
| 6 | Запись `paid` по `return_url` (`redirect_status=succeeded` ставит `PAID`) | `PaymentRequestsTest::test_return_url_and_paid_parameter_do_not_mark_the_request_paid` |

Новый словарный тест W-21 тоже проверен поломкой: LT-строка `shell.nav.services` во вкладке Audit роняет
`test_every_english_page_has_no_lithuanian_ui_text_strings` («/en/admin/audit (admin): LT-строка…»).

## 6. Lighthouse

`npx lighthouse@13.5.0` (версия зафиксирована), `--chrome-path` — Chromium Playwright
`~/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome`, headless, мобильный профиль по умолчанию. Цель — локальный
prod-compose `http://127.0.0.1:8792` (образ W-29, `optimize`). Отчёты — `build/verification/lighthouse/`.

| Страница | Performance | Accessibility | Best practices | SEO | LCP | CLS | TBT |
|---|---|---|---|---|---|---|---|
| /lt | 99 | 94 | 100 | 100 | 1,8 с | 0 | 0 мс |
| /en | 99 | 94 | 100 | 100 | 2,0 с | 0 | 0 мс |
| /lt/paslaugos/3d-spausdinimas | 99 | 94 | 100 | 100 | 1,8 с | 0 | 0 мс |
| /en/services/3d-printing | 99 | 94 | 100 | 100 | 1,8 с | 0 | 0 мс |
| /lt/kalkuliatorius | 100 | 96 | 100 | 100 | 1,7 с | 0 | 0 мс |
| /en/quote | 100 | 96 | 100 | 100 | 1,5 с | 0 | 0 мс |

Замечания доступности: `color-contrast` (подписи `.eyebrow`, мелкий текст, хлебные крошки) и `heading-order` (`h4` карточек).
Цвета и уровни заголовков взяты из прототипа, а §7.1 требует повторять их точно. Поэтому в W-21 они не менялись.
**Вопрос пользователю:** нужно ли отступить от прототипа ради контраста? Порогов Lighthouse план не задаёт.

## 7. Стоп-лист §9.5

| Условие | Уровень L | Доказательство |
|---|---|---|
| Чужой доступ к заказу, файлу или счёту | исключён | тесты прав (§2), поломка 1; HEAD/Range, гостевой заказ, перевыпуск ссылки |
| Задвоение денег или номера счёта | исключено | idempotency key intent, дедупликация событий, номер — один раз (поломки 4/4б, 5, 6) |
| Потеря принятого заказа или файла | исключена | сбой почты/Sheets не откатывает заказ, нет файлов-«сирот» (`OrderSubmissionTest`); копия и восстановление с проверкой хешей — W-29 |
| Неподтверждённый файл помечен готовым | исключено | производство только после подтверждения цены, переходы только вперёд (`OrderWorkflowTest`) |
| Демо или имитация выдаётся за рабочую функцию | исключено | маркеры D-21 — 0 вне allowlist; демо-сборка с баннером «DEMO» и без оплаты и счетов; без ключей Stripe — «оплата временно недоступна»; моки в отчётах названы моками |
| Секреты в репозитории, логах, образе | не найдены | `bin/check` (Git-исключения); поиск по отслеживаемым файлам `aiqme-site/` (`sk_live_/sk_test_/whsec_/PRIVATE KEY/private_key/токен бота`) — 0; журнал `web` без токенов и образ без `.env` — W-20 |
| Внешняя копия и проверенное восстановление **в рабочей среде** | **не применимо на L** | восстановление проверено локально (W-29); внешняя копия на сервере — W-23 (D-13, D-31) |

## 8. Статус функций (R-001…R-041)

| R | Функция | Статус |
|---|---|---|
| R-001 | Структура сайта | L-готово |
| R-002 | Оформление по прототипу | L-готово автоматически; ручной просмотр ждёт пользователя |
| R-003 | Типографика | L-готово |
| R-004…R-006 | Заглушки брендов, товаров, новостей | L-готово |
| R-007 | Работающие формы | L-готово (локальный Mailpit) |
| R-008 | Письма на info@aiqme.com | L-готово; реальная доставка — T (W-22, D-15) |
| R-009 | Домен aiqme.com | не проверено: уровень P (W-23, D-01, D-35) |
| R-010…R-012 | Регистрация, вход и сброс пароля, кабинет | L-готово |
| R-013 | Калькулятор (с просмотром модели W-31) | L-готово |
| R-014 | Заказы | L-готово |
| R-015 | Оплата картой | L-готово на моке и подписанных фикстурах; Payment Element и Stripe test — W-22; live — W-23 |
| R-016 | Автоматические счета | L-готово с тестовыми реквизитами TEST; реальные реквизиты и налоги — W-23 (D-04, D-05, D-22, D-23, D-34) |
| R-017, R-018 | Веб-управление заказами и контентом | L-готово |
| R-019 | Заказы через Telegram | исключено (В-046) |
| R-020, R-021, R-040 | Контент через Telegram, права | L-готово на фикстурах; тестовый бот — W-22 |
| R-022…R-024, R-029 | Google Таблицы | L-готово на фейке; тестовая таблица — W-22 |
| R-025, R-026 | Фото и видео заказчика | L-готово с медиа по умолчанию D-43; свои медиа заказчик загружает в админке |
| R-027, R-028 | Маркетплейс, генерация 3D | вне MVP / исключено |
| R-031…R-035, R-037, R-038 | Прежняя облачная архитектура | исключено (свой сервер, Laravel, PostgreSQL, dompdf) |
| R-036 | Почтовый сервис | требуется решение D-15 (W-22) |
| R-039 | Хранение PDF счёта | L-готово; сроки хранения — D-13 |
| R-041 | Демо для GitHub Pages | L-готово (пересобрано, `bin/demo-check` OK); публикация — только по разрешению (D-16), не выполнялась |

## 9. Не проверено

- Ручной визуальный просмотр кадров (страницы, кабинет, админка, демо) — ждёт проверки пользователем.
- Stripe Payment Element, 3-D Secure, CSP Stripe — только с ключами Stripe test (W-22).
- Реальные Stripe, Google Таблица, Telegram-бот, SMTP — уровни T/P (§10).
- Сервер, домен, TLS, внешняя копия, одновременная нагрузка — W-23.
- Safari, Firefox, реальные мобильные устройства.

## 10. Что требует уровней T и P и какие нужны доступы

| Уровень | Что | Нужные доступы и решения |
|---|---|---|
| T (W-22) | Stripe test: успех, отказ, 3DS, аванс + остаток, повтор события, возврат в Dashboard, отмена после оплаты; CSP Stripe | тестовый аккаунт Stripe: `STRIPE_KEY`, `STRIPE_SECRET` (test), Stripe CLI (`stripe listen`) и его секрет в `STRIPE_WEBHOOK_SECRET` |
| T (W-22) | Google Таблица: строки Orders/Payments/Requests/Contacts/Events | тестовая таблица и сервисный аккаунт Google с правом редактора: `GOOGLE_SHEETS_KEY_BASE64` (ключ вне репозитория), `GOOGLE_SHEETS_SPREADSHEET_ID` |
| T (W-22) | Telegram-бот: привязка, правка, медиа (`aiqme:telegram-poll`) | токен тестового бота от BotFather (`TELEGRAM_BOT_TOKEN`), личный аккаунт Telegram администратора |
| T (W-22) | Почта: тестовая отправка на разрешённый адрес | SMTP-провайдер и учётные данные `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` (D-15), разрешённый адрес получателя |
| P (W-23) | Рабочий запуск | сервер (D-01…D-03), домен aiqme.com с DNS и TLS без поломки почты (D-35), live-ключи Stripe и webhook, реальные реквизиты, VAT и нумерация (D-04, D-05, D-22, D-23, D-34), рабочие таблица и бот, внешняя копия (D-13, D-31), тексты политик (D-27), явное разрешение на запуск |
