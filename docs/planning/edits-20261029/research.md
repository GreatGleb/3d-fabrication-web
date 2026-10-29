# Правки заказчика 28.10.2026 — исходные данные для плана

Первичные материалы (приватно, вне Git): `docs/customer/client/edits-20261028/` — `edits.txt` (переписка с подписями
к снимкам; снимок указан строкой пути, текст под ним — подпись к нему) и 11 снимков WhatsApp. Сайт работает на
https://aiqme.com с 28.10 (код 68af859, см. `aiqme-site/docs/verification/production-launch.md`); реальные заказы не открыты.

Ниже — что выяснено при изучении кода 29.10 и решения пользователя (29.10) по неоднозначным местам.

## П1. «Apie mus» на телефоне — текст без боковых полей

Снимок 19.28.27: `/lt/apie-mus` на телефоне, текст прижат к левому краю, справа обрезан.
Причина подтверждена на рабочем сайте (Playwright, 29.10): у `<div class="wrap page-content">` боковой отступ 0.
В `resources/css/prototype/style.css` правило `.page-content{padding:44px 0 72px}` (стр. 124) стоит после
`.wrap{…padding:0 48px}` (стр. 26), а в `@media(max-width:600px)` `.page-content{padding:28px 0 50px}` (стр. 383) идёт
после `.wrap{padding-left:20px;padding-right:20px}` (стр. 342). Замеры x/ширина `.page-content` и колонки:
390 px → 0/390 (поля нет); 600 → 0/600; 601 → колонка x=28 (работает, спасает правило 1150 px);
1400 → колонка x=40, а заголовок `.page-top` x=88 (сдвиг на 1321–1440 px). Значит, затронуты все страницы
с `.wrap.page-content`, не только «О нас». CSS прототипа по договорённости правится не здесь, а в `resources/css/shell.css`
(«Исправления оболочки (§7.2)»). Существующая проверка bin/browser-check сверяет только ширину документа и не ловит
это — нужна проверка отступа контента от края.

Снимок 19.43.33 (админка «Apžvalga», подпись — что теперь работает) — не правка. На нём «Nepavykusių foninių užduočių: 1» —
на сервере есть одна упавшая фоновая задача; посмотреть отдельно (не часть плана, доступ к серверу — у пользователя).

## П2. «Шторка» шапки, как на 3dbonum.com

Снимки 19.45.15 и 19.45.24 — шапка 3dbonum.com. Разметка сайта (получена 29.10): два логотипа `logo-white.svg` и
`logo-black.svg`; вверху страницы шапка прозрачная поверх тёмного баннера, белые логотип и пункты; при прокрутке —
белый фон, тёмные логотип и пункты. Просьба заказчика (пересказ): у нас переход из чёрной шапки в белую, логотип на чёрной — белый.

**Решение пользователя:** на всех страницах. Вверху страницы шапка тёмная (на главной — прозрачная поверх тёмного
видео hero, на остальных — сплошная тёмная, ниже светлый `.page-top`), логотип и пункты белые; при прокрутке шапка
плавно становится белой с обычным логотипом. Второй ряд (`x-header-cta`: подпись и «Įkelti modelį ir sužinoti kainą»)
тёмный вместе с ней.

Код: `resources/views/components/header.blade.php`, `header-cta.blade.php`, `layout.blade.php`; шапка
`.header{background:#fff;…position:sticky;top:0}` в `resources/css/prototype/style.css:27`; у логотипа белая подложка
(`.logo{background:#fff}`, стр. 636/682/683). Меню ≤900 px — `resources/js/menu.js`. Hero главной — `.hero` (тёмный,
`--dark:#0a1119`). Логотип — только `public/defaults/logo.jpg` 1280×320 (чёрный текст, синяя деталь «Q», белый фон) и
необязательный свой файл из админки (`ContentTypes` home → `logo`, форматы jpg/png/webp). Белого варианта и SVG нет:
его нужно изготовить (прозрачный PNG/SVG: чёрное → белое, синее сохраняется, белый фон → прозрачный) и дать в админке
слот для своего светлого логотипа. Учесть: админка и кабинет используют ту же шапку; prefers-reduced-motion; без JS
страница должна оставаться читаемой; кадры bin/browser-check и сверка §7.1 с прототипом (шапка прототипа — белая).

## П3. Капча на формах

Заказчик (пересказ): нужна капча на форме, через которую присылают запросы, — со старого сайта постоянно пишут боты.
Сейчас: honeypot `website_hp` (`resources/views/public/partials/honeypot.blade.php`,
`app/Http/Controllers/Public/FormController.php`) и лимит по IP `throttle:forms` / `throttle:orders`.

**Решения пользователя:** ALTCHA — своя проверка proof-of-work без внешнего сервиса и ключей заказчика (новая
зависимость PHP и JS этим разрешена). Формы: контактная «Projekto užklausa» (`/lt/kontaktai`, `/en/contact`),
регистрация на обучение, регистрация кабинета. Заказ из калькулятора — без капчи. Учесть CSP
(`app/Http/Middleware/SecurityHeaders.php`, nonce; воркеры виджета), D-20 (никаких внешних запросов), LT/EN тексты
виджета, защиту от повторного использования решения, ключ HMAC в `.env` (рабочий сервер — шаг пользователя),
содержательные тесты (без решения — отказ без записи; верное решение — запись; повтор — отказ).

## П4. Убрать нумерацию «01 / AIQME» у услуг

Снимки 20.15.24, 20.15.24 (1) — выделены подписи «● 01 / AIQME», «● 03 / VIENETINIAI GAMINIAI, PROTOTIPAI IR SERIJINĖ
GAMYBA» и т. д.; снимок 20.17.09 — макет желаемого вида: подписи над названием услуги нет, маленький номер «01» в углу
картинки и заголовок раздела «03 / PASLAUGOS» остаются (белые/серые прямоугольники на макете — следы редактирования
картинки, не элементы дизайна). Код: строка `<div class="eyebrow">… {{ sprintf('%02d', …) }} / {{ $service->field('label') }}</div>`
в `resources/views/public/partials/service-features.blade.php` (общая для главной и `/paslaugos`).

## П5. Карта на «Kontaktai» видна сразу

Снимок 20.21.43 (1): блок с одной ссылкой «Atidaryti žemėlapį ↗» (стрелка заказчика); 20.21.43: Google Maps, спутник,
«AIQME, UAB 3D INŽINERIJA IR TECHNOLOGIJOS», Metalo g. 4, въезд с J. Dobkevičiaus g.; заказчик: клиентам трудно найти фирму.
Сейчас в `resources/views/public/contact.blade.php` сознательно нет встроенной карты («внешний сервис, CSP и D-20»);
ссылка — `lang/*/public.php` `contact.map_url` = `https://www.google.com/maps?q=54.641192%2C25.268099`.
Cookie-плашка — информационная, только необходимые cookie (D-11).

**Решение пользователя:** встроенная карта Google Maps (iframe) сразу при открытии страницы, со ссылкой на Google Maps
рядом. Следствия: разрешить источник в CSP `frame-src`, исключение из D-20 только для этого iframe, дописать про cookie
Google Maps в тексты cookie (плашка/страница cookie), проверить браузерную проверку (она прерывает внешние запросы и
перечисляет их — iframe Google должен стать ожидаемым исключением, а не ошибкой).

## П6. Калькулятор, шаг 02 «Gamybos pasirinkimai»

Снимок 23.37.38 — макет желаемого шага 2; текст в `edits.txt` — списки вариантов. Окончательный вариант
«Detalės paskirtis» — последний в тексте (заказчик отметил его как лучший): Vizualiniams tikslams / Praktiniam naudojimui / Reikia konsultacijos.

Сейчас (`resources/views/public/calculator.blade.php:51-77`, `lang/*/calculator.php` → `settings`):
Medžiaga, Spalva, Kokybės lygis (`quality`: standard/fine), Papildomas apdirbimas (`finish`: none/smooth);
блок «FDM parametrai»: Sluoksnio aukštis (`layer`: 0.12/0.2/0.28), Užpildas (`infill`: 20/40/60/100 ∪ default_infill);
`fdm_note`; «Pageidaujama darbų eiga» (`term`: standard/priority) + `preferences_note`; «Detalių kiekiai».

Макет заказчика (сверху вниз):
1. Medžiaga, Spalva — без изменений.
2. **Detalės paskirtis** (на месте «Kokybės lygis»): Vizualiniams tikslams / Praktiniam naudojimui / Reikia konsultacijos.
3. **Papildomos paslaugos** (на месте «Papildomas apdirbimas»): Šlifavimas ir dažymas / Srieginių įvorių montavimas /
   Detalių surinkimas / Reikia konsultacijos. **Решение пользователя:** один выпадающий список, первым и по умолчанию —
   «Nereikia» (не нужны), звёздочку не ставить.
4. Блок «FDM parametrai»: **Paviršiaus kokybė** (на месте «Sluoksnio aukštis»): Aukšta / Standartinė (по умолчанию) /
   Supaprastinta / Reikia konsultacijos; **Užpildas (%)**: 15 / 20 (rekomenduojama) / 40 / 60 / 80 / 100 / Reikia
   konsultacijos. Текст `fdm_note` убран (на макете — пустая полоса).
5. **Gamybos terminas** (на месте «Pageidaujama darbų eiga»): Įprasta tvarka / Skubos tvarka; под ним вместо
   `preferences_note`: «Pasirinkę skubos tvarką, laukelyje „Papildoma informacija“ nurodykite, iki kada reikia pagaminti.
   Terminas skaičiuojamas nuo užsakymo patvirtinimo. Tikslų terminą suderinsime įvertinę modelį ir kiekį.»
6. Detalių kiekiai — без изменений.
7. **Papildoma informacija** — новое многострочное поле после киекiai.

Поток данных параметров (всё нужно провести): `resources/js/calculator/index.js` (`parameters()` — текст сообщения
специалисту, `submitOrder()` — отправка полей), `app/Http/Controllers/Public/OrderRequestController.php` (проверка
`$choice(...)`, `preferences`), `app/Domain/Orders/OrderSubmission.php` (параметры детали `StlPart.parameters`, `digest`
идемпотентности), `resources/views/admin/orders-card.blade.php` (`$settings` → подписи), кабинет `account/order.blade.php`,
тесты и фабрики (`tests/Feature/*`, `tests/browser/OrderFactory.php`, `database/seeders/DemoSeeder.php`), а также письма,
Telegram и Google Таблица, если они выводят параметры (проверить). Старые заказы с прежними ключами должны
отображаться без ошибок. Цена: из параметров на неё влияют только материал и заполнение
(`app/Domain/Pricing/EstimateService.php`: масса × (0.2 + 0.8 × infill/100)); «Reikia konsultacijos» у заполнения
должно давать оценку по `default_infill` и явную пометку для администратора. Остальные новые поля — пожелания, цену не
меняют (сохранить это правило в тексте и коде). Нужны переводы EN всех новых строк.

## П7. Шаг 03 «Modelių patikra»: «FDM: 1000 x 1000 x 1000 mm»

Снимок 23.40.06: обведено «Įrangos dydžio ribos — FDM: 250 × 250 × 250 mm». Текст строится из настройки калькулятора
`max_dimension_mm` (`lang/lt/calculator.php` `review.build_text`, `app/Domain/Pricing/CalculatorSettings.php`,
миграция `2026_10_21_100001_insert_calculator_settings.php`: 250 и `unconfirmed` содержит `max_dimension_mm`;
админка `admin/calculator-settings.blade.php` допускает 10…2000). Та же величина — порог предупреждения «viršyta :mm mm
įrangos riba» при разборе STL (`OrderSubmission` → `StlAnalyzer::measure`). Значит, правка — значение 1000 (и
подтверждено заказчиком), а не только текст. На рабочей БД сейчас 250.

## Ограничения

- Корень — публичный репозиторий; материалы заказчика — только в `docs/customer/` (исключено).
- Реализация — ночным прогоном `docs/planning/edition-2/run/night.py` (задачи только в `aiqme-site/` и
  `docs/requirements.md`; автору запрещены WebFetch/WebSearch, git commit/push; проверки — `aiqme-site/bin/check`,
  `aiqme-site/bin/browser-check`). Обновление рабочего сервера (`deploy <rev>`, ключ ALTCHA в `.env`, значение
  `max_dimension_mm` на рабочей БД) — только с пользователем.
- Простота: делать то, что попросил заказчик, без лишних функций.
