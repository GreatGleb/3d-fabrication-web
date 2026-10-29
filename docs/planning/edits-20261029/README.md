# Правки заказчика 28.10.2026: план реализации

Назначение: перечень семи правок заказчика от 28.10 (П1–П7) к новому сайту `aiqme-site/` (работает на
https://aiqme.com с 28.10, код `68af859`). Для каждой правки описано, как сейчас, как должно стать, какие файлы и
тексты меняются, как это проверить и чем это рискует. Отсюда же — очередь ночного прогона
[`tasks.json`](tasks.json) (задачи E-01…E-11, формат `docs/planning/edition-2/tasks.json`).

Главный принцип: **максимально просто, только то, что просил заказчик, но с проверками по сути**. Это не новая
редакция плана: решения D-xx [редакции 2](../edition-2/decisions.md) действуют, кроме мест, где заказчик или
пользователь решили иначе. Такие места перечислены ниже как осознанные отступления.

## Источники

| Что | Где | Примечание |
|---|---|---|
| Переписка 28.10 и 11 снимков | `docs/customer/client/edits-20261028/` (`edits.txt`, `WhatsApp Image 2026-10-28 at *.jpeg`) | приватно, вне Git; переписку не цитировать |
| Изучение кода и решения пользователя 29.10 | [research.md](research.md) | решения пользователя не пересматриваются |
| Правила | корневой `AGENTS.md`, `aiqme-site/AGENTS.md` (Boost), rules в [tasks.json](tasks.json) | |
| Прежний план | `docs/planning/edition-2/` (README, decisions.md, tasks.json, run/) | исторический, не переписывается |
| Эталон прототипа | `docs/customer-prototype/evidence/audit-20261016/` | приватно; сверку для изменённых мест заменяет эта правка |
| Ход работ | `aiqme-site/docs/progress.md` | каждая задача пишет свой раздел |

Снимки, на которые опирается план (пути от `docs/customer/client/edits-20261028/`):

| Правка | Снимки | Что на них |
|---|---|---|
| П1 | `… 19.28.27.jpeg` | «Apie mus» на телефоне: текст у левого края, справа обрезан |
| — | `… 19.43.33.jpeg` | админка «Apžvalga» — не правка (см. «Вне плана») |
| П2 | `… 19.45.15.jpeg`, `… 19.45.24.jpeg` | шапка 3dbonum.com вверху страницы и после прокрутки |
| П4 | `… 20.15.24.jpeg`, `… 20.15.24 (1).jpeg`, `… 20.17.09.jpeg` | выделенные подписи «● 01 / AIQME» и т. п.; макет «как надо» без них |
| П5 | `… 20.21.43 (1).jpeg`, `… 20.21.43.jpeg` | блок с одной ссылкой; спутниковая карта Google с меткой фирмы |
| П6 | `… 23.37.38.jpeg` | макет шага 02 калькулятора; списки вариантов — в `edits.txt` |
| П7 | `… 23.40.06.jpeg` | обведена строка габарита FDM 250 мм на шаге 03 |

Своими словами, что просит заказчик:
П1 — на телефоне у текста страниц нет боковых полей;
П2 — шапка-«шторка», как на 3dbonum.com: вверху тёмная с белым логотипом, при прокрутке белая;
П3 — капча на формах, через которые посетитель отправляет запрос, потому что пишут боты;
П4 — убрать повторяющуюся нумерацию у услуг;
П5 — чтобы карта на «Kontaktai» была видна сразу (к ним трудно добраться);
П6 — новый состав шага 02 калькулятора;
П7 — габарит FDM 1000 × 1000 × 1000 мм.

## Решения пользователя (29.10, из research.md)

1. **П2:** шторка **на всех страницах**: публичных, кабинета, админки и страниц ошибок. Вверху страницы шапка тёмная:
   на главной полупрозрачная поверх видео hero, на остальных страницах сплошная тёмная. Логотип и пункты белые.
   Второй ряд (подпись и «Įkelti modelį ir sužinoti kainą») тёмный вместе с шапкой. При прокрутке шапка плавно
   становится белой с обычным логотипом.
2. **П3:** капча **ALTCHA** — своя проверка proof-of-work, без внешнего сервиса и без ключей заказчика. Новая
   зависимость PHP и JS этим решением **разрешена**. Формы с капчей: контактная («Projekto užklausa»), регистрация на
   обучение, регистрация кабинета. Заказ из калькулятора **без капчи**. Ключ HMAC лежит в `.env`; на рабочем сервере
   его ставит пользователь.
3. **П5:** встроенная карта Google Maps (iframe) **сразу при открытии страницы**, рядом ссылка на Google Maps. Для
   этого iframe разрешается источник в CSP `frame-src` и делается исключение из D-20. В тексты cookie добавляется
   упоминание cookie Google Maps.
4. **П6:** «Papildomos paslaugos» — **один выпадающий список**, первый пункт и пункт по умолчанию «Nereikia»,
   без звёздочки. «Detalės paskirtis» — последний вариант из переписки: Vizualiniams tikslams / Praktiniam naudojimui /
   Reikia konsultacijos.
5. **Общее:** реализует ночной прогон (задачи меняют только `aiqme-site/` и `docs/requirements.md`). Обновление
   рабочего сервера делает только пользователь: `deploy <rev>`, ключ ALTCHA в `.env`, значение `max_dimension_mm`.

## Осознанные отступления от прототипа и решений редакции 2

Приёмщик не считает их расхождениями. В `progress.md` и отчётах они отмечаются как «правка 28.10».

| Где | Прототип / прежнее решение | Теперь |
|---|---|---|
| Шапка (П2) | белая (§7.1) | вверху тёмная, белая после прокрутки; значения §7.1 сверяются в прокрученном состоянии |
| Поля контента (П1) | на >1150 px и ≤600 px полей нет (ошибка CSS прототипа) | поля как у `.wrap`; x контента отличается от кадров прототипа §7.1 на >1150 px |
| Услуги (П4) | подпись «NN / подпись» над названием | подписи нет |
| Контакты (П5) | W-05: без встроенной карты (D-20, CSP) | iframe Google Maps; `frame-src https://www.google.com`; узкое исключение D-20 для этого iframe |
| Cookie (П5) | D-11: «только необходимые cookie» | тот же механизм плашки, в тексте сказано про cookie Google Maps |
| Шаг 02 калькулятора (П6) | quality/finish/layer/term прототипа | состав по макету заказчика |
| Габарит (П7) | D-24: 250 мм, «не подтверждено» | 1000 мм, подтверждено заказчиком |
| Формы (П3) | honeypot и throttle | плюс ALTCHA; без JavaScript три формы не отправить |

---

## П1. Боковые поля контента страниц

**Как сейчас.** Боковой отступ обнуляют два правила `resources/css/prototype/style.css`:
- стр. 124: `.page-content{padding:44px 0 72px;…}` стоит после `.wrap{…padding:0 48px}` (стр. 26);
- стр. 383: в `@media(max-width:600px)` правило `.page-content{padding:28px 0 50px}` стоит после
  `.wrap{padding-left:20px;padding-right:20px}` (стр. 342).

Правило ≤1150 px (стр. 301) идёт позже и возвращает поле только на 601–1150 px.

Замеры на aiqme.com 29.10 (x контента / x колонки шапки):

| Ширина окна, px | x контента / x колонки шапки |
|---|---|
| 320–600 | 0/20 |
| 601–1150 | 28/28 |
| 1151–1320 | 0/48 |
| 1400 | 40/88 |
| 1440 | 60/108 |

Затронуты все 27 шаблонов с `wrap page-content`: публичные страницы, ошибки, кабинет, админка, вход, заказ.
`bin/browser-check` проверяет только ширину документа и поля `.head-main`, поэтому ошибку не замечает.

**Как должно стать.** У `.wrap.page-content` боковые поля как у `.wrap`: 20 px до 600 px, 28 px на 601–1150 px,
48 px шире 1150 px. Контент начинается там же, где колонка шапки; на 1440 px это x = 108. Вертикальные отступы
прототипа не меняются, при печати полей нет.

**Файлы и изменения.**
- `aiqme-site/resources/css/shell.css`: блок с комментарием «Поля контента страниц (правка 28.10)…»:
  ```css
  .wrap.page-content{padding-left:48px;padding-right:48px}
  @media(max-width:1150px){.wrap.page-content{padding-left:28px;padding-right:28px}}
  @media(max-width:600px){.wrap.page-content{padding-left:20px;padding-right:20px}}
  @media print{.wrap.page-content{padding-left:0;padding-right:0}}
  ```
  Селектор `.wrap.page-content` специфичнее обоих правил прототипа, поэтому порядок правил не важен.
  `prototype/style.css` не меняется (§7.2).
- `aiqme-site/bin/browser-check`:
  - `GUTTER_PROBE` (JS) для `main .page-content` возвращает: paddingLeft/Right, x начала и конца контента, x колонки
    шапки (`.head-main` left + paddingLeft), clientWidth и признак `.article`;
  - `check_gutters(report, page, where, width)`: ожидаемое поле — `expected_styles(width)['wrap_padding']`, допуск
    ±1 px. Проверяются: поля равны ожидаемому; x начала ≥ поля; x конца ≤ ширина − поле; x начала = x колонки шапки
    (у `.article` — не меньше, потому что FAQ и политики центрированы, как в прототипе);
  - вызов `check_gutters` на всех ширинах `WIDTHS` в основном цикле страниц и в `check_private_pages`; это покрывает
    кабинет, админку и `/ru`;
  - отдельно ширина 1280×900 на `/lt/apie-mus`, `/lt/kalkuliatorius` и `/lt/duk`;
  - `self_test`: синтетический `.page-content` без `.wrap` — детектор обязан сработать;
  - в docstring — строка о новой проверке.

**Тексты.** Нет.

**Проверки.**
- `bin/browser-check`: `check_gutters` зелёный везде, самопроверка ловит нулевые поля.
- Прогон с откатом: без блока в `shell.css` проверка падает на `/lt/apie-mus` при 390 (0 ≠ 20) и при 1440
  (60 ≠ 108). После прогона блок вернуть. Это доказывает, что проверка ловит ошибку по сути.
- `document_fits` по-прежнему зелёный на 320. `bin/check` без изменений, XPath тестов по `wrap page-content`
  работает.

**Риски.**
- На 1151–1440 px контент всех страниц сдвинется на 48 px и станет на 96 px уже. Это ожидаемо: так контент
  выравнивается по шапке. Расхождение x с кадрами прототипа отмечается как правка.
- На 320 px колонка калькулятора и админки станет 280 px. Ширину документа контролирует `document_fits`.
- `aiqme-site/demo/` получит исправление только при пересборке. Пересборка не входит в план.

## П2. Шапка-«шторка» и белый логотип

**Как сейчас.**
- Шапка везде белая: `.header{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50}`
  (style.css:27). Под логотипом белая подложка (стр. 636, 682, 683). Второй ряд `.header-cta` с фоном `#f8fafc`,
  CTA цвета `var(--blue)`.
- Разметка: `components/header.blade.php` (одна `<img>` в `a.logo`), `header-cta.blade.php`, `layout.blade.php:10-14`
  (`$logo` — свой файл `logo` page_text home или `defaults/logo.jpg`).
- Обработчика прокрутки нет. Меню ≤900 px — `resources/js/menu.js`, выпадающий список белый.
- Есть только один логотип, `public/defaults/logo.jpg` (1280×320, чёрные буквы, синий хвост «Q», белый фон).
  Белого варианта и SVG нет. ImageMagick в системе нет, есть Python PIL 12.3.0.
- Высота шапки: 123 px на >900, 116 px на ≤900, 108 px на ≤600. Sticky-сводка калькулятора `top:143px` рассчитана
  на эту высоту.
- На 3dbonum.com (разметка прочитана 29.10): порог переключения — высота шапки + 50 px, переход
  `background-color .3s ease`, два логотипа сменяются через opacity.

**Как должно стать.**
- Вверху любой страницы шапка тёмная:
  - фон `var(--dark)` `#0a1119`;
  - белые пункты меню, значки и LT/EN;
  - белый логотип (белые буквы, синий хвост «Q», прозрачный фон, без подложки);
  - второй ряд тёмный: подпись `#d5dde6`, CTA `#9cc4ff`.
- На главной (когда выводится hero) шапка полупрозрачная поверх видео: `rgba(10,17,25,.6)`. Hero начинается от
  верха окна. Содержимое hero (h1, кнопки) остаётся на прежних координатах, без скачка.
- После прокрутки больше чем на высоту шапки + 50 px шапка за 0,3 с (ease) становится белой и в точности совпадает
  с прототипом §7.1: фон `#fff`, линия `#dce2e8`, второй ряд `#f8fafc`, CTA `#0867ff`, обычный логотип на белой
  подложке. Выше порога шапка снова тёмная.
- Высоты рядов в обоих состояниях одинаковы, меняются только цвета и прозрачность.
- Выпадающее меню ≤900 px остаётся белым с тёмными пунктами.
- Активный пункт подчёркнут: на тёмном `#9cc4ff`, на белом `var(--blue)`. Фокус — прежний outline.
- При `prefers-reduced-motion` переход мгновенный.
- Страница, открытая уже прокрученной (перезагрузка, назад, якорь), получает состояние сразу, без анимации:
  переходы включаются только после `load`.
- Без JS шапка всегда тёмная и сплошная (на главной тоже, через `<noscript><style nonce>`).
- Свой белый логотип загружается в админке, слот `logo_white` (PNG/WebP).
  - Если администратор загрузил свой обычный логотип без белого, белое умолчание AIQME не показывается. В тёмной
    шапке тогда стоит его обычный логотип на белой подложке (класс `header--logo-plate`), по образцу правила
    умолчаний hero при своём видео.
- Подвал не меняется.

**Файлы и изменения — E-08 (логотип и слот).**
- `aiqme-site/public/defaults/logo-white.png`: 1280×320 RGBA, изготовить из `logo.jpg` разовым скриптом PIL.
  - Алгоритм для каждого пикселя: `m=min(r,g,b)`, `alpha=clamp((255−m)/245,0,1)`; при `alpha<0.04` — прозрачный
    пиксель.
  - Иначе цвет очищается от белого фона: `c=(v−(1−alpha)·255)/alpha`. Ахроматический (`max(c)−min(c)<70`)
    становится белым, синий сохраняется.
  - Скрипт выполняется так: `python3 -I - <in> <out>` из heredoc. В репозиторий скрипт не кладётся, команда
    записывается в `progress.md`. Результат посмотреть на тёмной подложке.
- `config/aiqme.php` (media_defaults.page_text.home): `'logo_white' => 'defaults/logo-white.png'` и комментарий
  «только пока нет своего logo».
- `app/Domain/Content/Models/ContentItem.php:29`: `'logo_white' => ['png','webp']`. SVG не добавлять: MediaFile его
  запрещает.
- `app/Domain/Content/ContentTypes.php:62`: `'logo_white' => true` после `logo`. Первым ключом остаётся video.
- `lang/{lt,en}/admin.php`: `media.collections.logo_white` (см. тексты).
- `resources/views/admin/content-editor.blade.php:69-97`:
  - умолчание `logo_white` не показывается, если есть свой `logo`;
  - превью белого логотипа на тёмной подложке (`admin-media-preview--dark`, CSS
    `.admin-media-preview--dark{background:var(--dark);padding:4px}` в `resources/css/public.css`).
- Telegram-бот подхватывает слот сам: `ContentTypes::media`. Фото из Telegram приходит JPEG и будет отклонено, а
  PNG документом пройдёт.

**Файлы и изменения — E-09 (шапка).**
- `components/layout.blade.php`:
  - prop `headerOverlay=false`;
  - `$logoWhite` = свой `logo_white` → иначе умолчание, если нет своего `logo` → иначе `null`; без БД — умолчания;
  - передача в `<x-header>`;
  - `<noscript><style nonce="{{ Vite::cspNonce() }}">.header--overlay{background:var(--dark)}</style></noscript>`
    в `<head>`.
- `public/home.blade.php`: `:header-overlay="(bool) $home"`, только когда выводится hero.
- `components/header.blade.php`:
  - `<header @class(['header','header--dark','header--overlay'=>$overlay,'header--logo-plate'=>! $logoWhite])>`;
    тёмный класс рисует сервер, поэтому первый кадр уже тёмный;
  - в `a.logo` (класс ровно `logo`) первой идёт обычная `img alt="AIQME"`, второй — `img.logo-white alt=""`;
  - обновить комментарий.
- `resources/js/header.js` (новый) и `import './header'` в `app.js` после `./menu`. Логика:
  - `threshold = header.offsetHeight + 50`;
  - rAF-троттлинг обработчика `scroll` (passive): `classList.toggle('header--dark', scrollY <= threshold)`;
  - на `resize` порог пересчитывается, на `pageshow` выполняется `sync`;
  - после `load` (или сразу, если `readyState==='complete'`) — `sync` и класс `header--ready`, который включает
    переходы.
- `resources/css/shell.css`, блок «Шапка-шторка (правка 28.10)»:
  - `--header-height` 123/116/108;
  - белый логотип `position:absolute` поверх обычного, opacity 0/1;
  - правила `.header--dark …` и `.header--dark.header--overlay`, `header--logo-plate`;
  - переходы только под `.header--ready`;
  - `.header--overlay{margin-bottom:calc(-1*var(--header-height))}`, у первого `.hero` главной `min-height` и
    `padding-top` увеличены на `--header-height` (650/620/660 и 85/56 из прототипа);
  - меню ≤900 px — тёмные пункты в любом состоянии;
  - `prefers-reduced-motion: reduce` → `transition:none`.

  Медиа-блоки ставятся после общих правил. Если sticky с отрицательным `margin-bottom` поведёт себя плохо, запасной
  вариант — отрицательный `margin-top` у hero. Полный список правил — в спецификации исследования, перенесённой в
  `done` задачи E-09.
- `bin/browser-check`:
  - `check_styles` на прокрутке 0 ждёт тёмное состояние: `headerDark` true, линия `rgba(255, 255, 255, 0.12)`,
    CTA `rgb(156, 196, 255)`. Высоты, поля, токены и шрифты §7.1 не меняются;
  - новая `check_header` (подробности — в проверках ниже);
  - `DEFAULT_MEDIA .logo img` остаётся: оба `img` из `/defaults/`;
  - в docstring — строка о шапке.

**Тексты.**

| Ключ | LT | EN |
|---|---|---|
| `admin.media.collections.logo_white` | Baltas logotipas (tamsiai antraštei; PNG arba WebP) | White logo (for the dark header; PNG or WebP) |

**Проверки.**
- PHPUnit `ShellTest`:
  - `header--dark` есть на `/lt`, `/en`, `/lt/apie-mus`, `/en/quote`, в админке и кабинете;
  - `header--overlay` — только на главной с hero;
  - в логотипе две `img` с нужными `src` и `alt`;
  - в `noscript/style` nonce совпадает с nonce из заголовка CSP.
- PHPUnit `DefaultMediaTest`:
  - слот `logo_white`: загрузка своего файла и удаление;
  - свой `logo` без белого → нет `img.logo-white`, есть `header--logo-plate`;
  - превью в редакторе;
  - файл `logo-white.png`: 1280×320, PNG RGBA, прозрачный угол (5,5), белый непрозрачный пиксель буквы (1100,90),
    синий хвост (590,230).
- `ContentMediaTest`/`AdminMediaTest`: JPEG и SVG в `logo_white` отклоняются, PNG принимается.
- `browser-check check_header` на `/lt`, `/en`, `/lt/apie-mus`, `/en/quote`, ширины 390 и 1440, и в админке:
  - на прокрутке 0 — тёмный фон, белые ссылки и значки, логотипы с opacity 1/0, контраст текстов шапки ≥4.5;
    для полупрозрачного фона контраст считается над `rgb(161,161,161)` (худший кадр ролика); цвета решения №4
    этот порог проходят (CTA `#9cc4ff` — 4.95);
  - на главной `hero.top == 0`, `header.offsetHeight == --header-height` на 320–1440, h1 hero на прежней
    координате;
  - после прокрутки на `offsetHeight+250` — значения §7.1, высоты рядов те же; после возврата на 0 — снова тёмная;
  - перезагрузка прокрученной страницы — белое состояние без анимации (`getAnimations().length==0`);
  - при reduced-motion `transitionDuration=='0s'`;
  - без JS — тёмная сплошная шапка, в том числе после прокрутки;
  - меню 390 px — белый список, тёмные пункты, `check_menu` без изменений;
  - кадры `shots/header-top-<страница>-<ширина>.png` и `shots/header-scrolled-…` для `/lt` и `/lt/apie-mus`
    (390 и 1440) и `/en/admin` (1440). Основной цикл снимает только верх страницы сразу после загрузки, поэтому
    кадры после прокрутки снимает сама `check_header`.
- Ручной просмотр этих кадров (ждёт пользователя).

**Риски.**
- `check_styles` нужно менять одновременно с CSS, иначе упадёт на всех страницах.
- `--header-height` задан числом. Если второй ряд перенесётся (длинная подпись ui_text на 320 px), hero уедет под
  шапку. Поэтому `offsetHeight` сверяется с переменной на всех ширинах.
- Прозрачность .6 — компромисс: у 3dbonum фон полностью прозрачный, но у нас ролик местами светлый вверху.
  Полную прозрачность заказчик может попросить отдельно; для неё нужен более тёмный ролик или затемнение.
- Возможен один кадр тёмной шапки при загрузке прокрученной страницы (модуль Vite — defer). Это допустимо.
- Белый логотип изготовлен из JPEG, на краях может быть ореол. Файл производный от `logo.jpg` (D-43), публикация
  допустима тем же решением.
- `position:sticky` с отрицательным `margin-bottom` подтверждается в Chromium. Safari и Firefox проверяются вручную,
  при случае.

## П3. Капча ALTCHA

**Как сейчас.**
- Контактная форма и регистрация на обучение защищены honeypot `website_hp` (`FormController.php`, partial
  `honeypot.blade.php`) и `throttle:forms` (10 в час с IP).
- Регистрация кабинета (`RegisterController::store`) не защищена ничем.
- CSP (`SecurityHeaders.php:34-50`): `script-src 'self'` с nonce, без `worker-src`. Воркер с `'self'` разрешён,
  `data:` и `blob:` запрещены.
- Пакетов altcha нет.
- Около 30 POST-запросов в тестах обращаются к этим трём формам. `bin/browser-check` отправляет контактную форму,
  окна обучения и контактную форму в prod-compose.

**Как должно стать.**
- Над кнопкой отправки на трёх формах стоит виджет ALTCHA с чекбоксом «Aš nesu robotas» / «I'm not a robot»:
  - `/lt/kontaktai`, `/en/contact`;
  - каждое окно регистрации на обучение (`/lt/mokymai`, `/en/training` и страница услуги обучения);
  - `/lt/paskyra/register`, `/en/account/register`.
- При фокусе в форме браузер решает задачу PBKDF2/SHA-256 (`auto=onfocus`, 1–3 с). Затем виджет показывает
  «Patvirtinta» / «Verified».
- Задачу выдаёт наш `GET /altcha`, подписывая её ключом `ALTCHA_HMAC_KEY`. Срок задачи 30 мин. Выдача ограничена
  30 задачами в минуту с IP.
- Сервер принимает форму только с верным, не просроченным и не использованным раньше решением. Иначе он
  возвращает ту же страницу с сообщением у виджета: поля сохраняются, записей, событий и писем нет.
- Honeypot и throttle остаются.
- Без ключа `/altcha` отвечает 503, и формы не принимаются: отказ закрытый.
- Калькулятор, вход и сброс пароля капчу не получают. Демо-сборка виджет вырезает.

**Зависимости (разрешены пользователем):**
- `altcha-org/altcha` **2.3.0** (Composer, MIT, без зависимостей): `bin/php /usr/local/bin/composer require
  altcha-org/altcha:2.3.0`. После установки `git diff composer.lock` показывает только этот пакет, а проверка
  версий в `bin/check` зелёная.
- npm `altcha` **3.4.1**, devDependency с точной версией: `npm install --save-dev --save-exact --ignore-scripts
  altcha@3.4.1`. Используется сборка `altcha/external`: без встроенных `<style>` и `data:`-воркеров, которые
  запретила бы CSP. Стили берутся из `altcha/altcha.css`, воркер — `altcha/workers/pbkdf2?worker` (отдельный файл
  `/build/assets`).

**Файлы и изменения — E-06 (основа, без форм).**
- `config/aiqme.php`, массив `forms`: `'altcha' => ['hmac_key' => env('ALTCHA_HMAC_KEY'), 'cost' => 5000,
  'counter' => [5000, 10000], 'expires' => 1800, 'per_minute' => (int) env('AIQME_ALTCHA_PER_MINUTE', 30)]` —
  лимит через env, как `AIQME_ORDERS_PER_HOUR`: `bin/browser-check` ходит только с 127.0.0.1.
- `app/Domain/Forms/Altcha.php` (final). Имена API PHP-пакета ниже — ожидаемые: пакета 2.3.0 нет ни в `vendor/`, ни в
  кэше Composer, сверить их до установки нельзя. Автор берёт фактический API из README и `src` в
  `vendor/altcha-org/altcha` и записывает его в `progress.md`; критерии задачи сформулированы через поведение. Методы:
  - `configured()`;
  - `challenge()` (Pbkdf2, cost, `random_int` counter, `expiresAt`);
  - `verify($payload)`: false при пустом ключе, при payload не строкой, пустом или длиннее 4096. Затем
    `Payload::fromBase64` + `verifySolution`; исключения → false; false при `!verified` и при пустом `expiresAt`.
    Последний шаг — `Cache::add('altcha:'.signature, true, ttl до срока)` против повтора;
  - `verifyOrFail(Request)` → `ValidationException` по ключу `altcha` с текстом `public.forms.altcha`.
- `app/Http/Controllers/Public/AltchaController.php`: 503 без ключа, иначе JSON и `Cache-Control: no-store, private`.
- `routes/webhooks.php`: `Route::get('altcha', …)->middleware('throttle:altcha')->name('altcha')`. Маршрут вне группы
  web: без сессии, cookie и `url()->previous()`. Комментарий файла поправить.
- `AppServiceProvider`: `RateLimiter::for('altcha', …perMinute(config…)->by(ip))`.
- `lang/{lt,en}/public.php` `forms.altcha` (см. тексты).
- `.env.example`: блок `ALTCHA_HMAC_KEY=` с комментарием (64 hex, `openssl rand -hex 32`; пусто — формы не
  принимаются).
- `bin/setup`: идемпотентно дописать ключ в локальный `.env`.
- `phpunit.xml`: `<env name="ALTCHA_HMAC_KEY" value="" force="true"/>`.
- `tests/TestCase.php::setUp`: тестовый ключ `phpunit-only-not-a-secret`, cost 1, counter [1,20]; helper
  `altcha()` решает задачу библиотекой (`solveChallenge`) без HTTP.

**Файлы и изменения — E-07 (формы).**
- `FormController`: `verifyOrFail` после honeypot в `contact()` и `training()`.
- `RegisterController::store`: `verifyOrFail` после `normalizeEmail`.
- `resources/views/public/partials/altcha.blade.php`:
  ```blade
  <altcha-widget challenge="{{ route('altcha', absolute: false) }}" auto="onfocus"
      language="lt|en по локали" configuration='{"hideFooter":true}'></altcha-widget>
  ```
  Под виджетом — `@error('altcha')` с `role="alert"`. Атрибут `name` не задавать.
- Подключение partial: `contact.blade.php` (после honeypot), `training-topics.blade.php` (после honeypot),
  `account/auth.blade.php` (только в режиме register, перед кнопкой). Комментарии шаблонов обновить.
- `resources/js/altcha.js`: `altcha/external`, `altcha/i18n/lt`, `altcha/altcha.css`, свой воркер PBKDF2.
  В `app.js` — динамический `import('./altcha.js')`, только если на странице есть `altcha-widget`.
- `resources/css/shell.css`: `altcha-widget{display:block;margin:4px 0 16px;--altcha-max-width:100%;…}`.
- `DemoExport::demoForm`: удалить `altcha-widget` из форм демо.
- Существующие тесты: `'altcha' => $this->altcha()` в каждом POST к трём формам:
  - FormsTest, SheetsTest, `Scenarios/ContactRequestScenarioTest`, `Scenarios/ClientCancelAfterAdvanceScenarioTest`,
    AuthTest;
  - тест honeypot не меняется;
  - тесты 419 (TelegramBotTest, StripeWebhookTest) не трогаются.
- `bin/browser-check`:
  - в `browser_env()` — `ALTCHA_HMAC_KEY` = `secrets.token_hex(32)` и `AIQME_ALTCHA_PER_MINUTE=1000` (иначе
    выдачи задач за прогон с одного IP могут упереться в 30/мин, а ответ 429 проверка считает ошибкой);
  - helper `wait_altcha` (ждёт `input[name=altcha]` и текст «Verified»/«Patvirtinta», таймаут 30 с), его вызов в
    `check_contact_form`, `check_training_modal` и `prod_contact`;
  - новая `check_register` (`/lt/paskyra/register` @390 → кабинет `#account-overview`);
  - кадры формы с виджетом после «Verified»/«Patvirtinta»: `shots/altcha-<страница>-<ширина>.png`;
  - `prod_contact` работает только в режиме `--base-url/--compose-file`, ночью он не выполняется: «проверено
    чтением кода, ждёт prod-compose (пользователь)»;
  - в docstring — строка о капче.
- `aiqme-site/docs/operations.md`: ключ `ALTCHA_HMAC_KEY` при первом выпуске до build/up, в prod-compose, при смене
  ключей (restart app worker scheduler → optimize).

**Тексты.**

| Ключ | LT | EN |
|---|---|---|
| `public.forms.altcha` | Patvirtinkite, kad nesate robotas: pažymėkite langelį „Aš nesu robotas“ ir palaukite, kol bus parašyta „Patvirtinta“. Langelis veikia tik įjungus JavaScript. | Confirm that you are not a robot: tick “I'm not a robot” and wait until it says “Verified”. The box needs JavaScript enabled. |

Тексты самого виджета («Aš nesu robotas», «Patvirtinta», «Tikrinama...», «Patvirtinimo laikas baigėsi. Bandykite dar
kartą.» и их EN) приходят из пакета: LT — `altcha/i18n/lt`, EN встроен. Подвал «Apsaugota ALTCHA» скрыт.

**Проверки.**
- `AltchaTest`, выдача задачи:
  - `GET /altcha` — 200, параметры задачи (алгоритм, cost, keyLength 32, срок около +1800 с, подпись 64 hex),
    `no-store`, без Set-Cookie, есть CSP, каждый ответ уникален;
  - throttle (`per_minute=2` → третий запрос 429);
  - без ключа — 503 и отказ формы.
- `AltchaTest`, приём форм (провайдер по пяти адресам):
  - без поля, с мусором (`abc`, `{}`, тестовый режим виджета, массив, 5000 символов), с подделкой (counter, cost,
    чужой ключ) и с просрочкой — отказ `altcha`, текст по локали, без записей, событий и писем;
  - с верным решением — запись создана;
  - повтор того же решения — отказ;
  - поля после отказа сохраняются (`old`), окно обучения открывается снова;
  - honeypot срабатывает раньше капчи.
- `AltchaTest`, разметка и конфигурация:
  - ровно один виджет перед `submit` на трёх формах; на login, reset и в калькуляторе виджета нет;
  - рабочие значения в `config/aiqme.php`: 5000, [5000, 10000], 1800.
- `OrderSubmissionTest` не меняется: калькулятор без капчи.
- `DemoExportTest`: в демо нет `altcha-widget`.
- После сборки в `manifest.json` есть отдельный чанк altcha и отдельный файл воркера. В `public/build/assets` нет
  `data:text/javascript`.
- `bin/browser-check`:
  - контакты, обучение и регистрация отправляются после «Verified»/«Patvirtinta»;
  - ни одного нарушения CSP и ни одной ошибки консоли;
  - нет внешних запросов;
  - ширина документа равна окну.

**Риски.**
- **Выпуск без ключа в `.env` сервера отключит три формы.** Порядок обязателен: сначала ключ, потом deploy, потом
  `curl /altcha` (см. «После прогона»).
- На старом телефоне решение может занять несколько секунд. Если заказчик пожалуется, `counter` снижается в
  конфигурации.
- Без JavaScript три формы не отправить. Это цена капчи без внешнего сервиса; сообщение это объясняет.
- От ботов с настоящим браузером капча не защищает, она делает каждую отправку дорогой. Боты прежнего сайта
  (`legacy-landing`) этой правкой не затронуты: защищаются только формы aiqme.com. Это стоит сказать заказчику.
- Пакет `npm altcha` 3.4.1 вышел 19.10, ему 10 дней. Если нужен запас «старше двух недель», можно взять 3.3.0
  (05.10): сборка у него та же. По умолчанию — 3.4.1, изученная подробно.
- Zipball PHP-пакета скачивается с `api.github.com` (D-20 разрешает). Если в момент установки нет сети, задача
  получает статус blocked.
- Проверке prod-compose нужен ключ в `build/prod-local/.env`. Это записывается в `operations.md`.

## П4. Подпись над названием услуги

**Как сейчас.** `resources/views/public/partials/service-features.blade.php:14` выводит
`<div class="eyebrow">● NN / {{ $service->field('label') }}</div>`. Partial общий для главной и `/paslaugos`. Номер
«01» в углу медиа (стр. 9) и заголовок раздела «03 / PASLAUGOS» — отдельные элементы. Тест `PublicPagesTest.php:82-83`
ожидает подписи. `check_text` сверяет их с эталоном на `/lt`, `/en`, `/lt/paslaugos` и `/en/services`.
`marker-allowlist.json` разрешает заглавную подпись 3D-печати на этих 4 страницах.

**Как должно стать.** Подписи над h3 нет: карточка начинается с названия. Номер в углу картинки и заголовок раздела
остаются, как на макете 20.17.09.

**Файлы и изменения.**
- `service-features.blade.php`: удалить строку 14 и поправить комментарий.
- Поле `label` в `ContentTypes::SERVICE_FIELDS`, данные сидера и подписи админки **не менять**: удаление поля ломает
  сохранение черновиков, в которых оно уже есть (`ContentService::clean`). В `ContentTypes.php:27` — комментарий «не
  выводится на сайте с 28.10; оставлено для совместимости черновиков».
- `tests/Feature/PublicPagesTest.php`:
  - на `/en`, `/lt` и `/lt/paslaugos` — 0 `eyebrow` в `.service-feature-copy`;
  - номера 01–06 в `span.service-feature-number`;
  - первый элемент `.service-feature-copy` — h3;
  - строки `VIENETINIAI GAMINIAI, PROTOTIPAI IR SERIJINĖ GAMYBA` в HTML нет.
- `bin/browser-check` `TEXT_EXCLUDED_BLOCKS`: `('div', {'eyebrow'}, None, ('div', {'service-feature-copy'}))` и
  комментарий.
- `tests/fixtures/marker-allowlist.json`: удалить 4 записи с заглавной подписью. Записи пункта списка
  «Vienetiniai gaminiai, prototipai ir serijinė gamyba.» и его EN оставить.

**Тексты.** Нет.

**Проверки.**
- Новые ассерты падают до правки шаблона и проходят после (проверить один раз).
- `check_text` на 4 страницах зелёный. Маркеры D-21 после чистки allowlist не срабатывают.

**Риски.** В админке остаётся поле «Bloko parašas», которое больше ничего не меняет. Скрыть его можно отдельной
правкой, если попросят.

## П5. Встроенная карта Google Maps на «Kontaktai»

**Как сейчас.**
- `contact.blade.php:32-34`: в `.contact-map` только ссылка `public.contact.map` → `map_url`. Карту убрали в W-05
  (CSP, D-20).
- CSS прототипа уже содержит правила для iframe: `.contact-map iframe{height:320px}` и 260 px на ≤900 px
  (style.css:634, 668). Но у коробки осталась высота 210 px (стр. 260).
- CSP `frame-src` разрешает только Stripe (`SecurityHeaders.php:43`).
- Cookie-плашка: «Analitika ir reklamos sekimas nenaudojami.» (`lang/*/shell.php` `cookie.text`).
- `browser-check` в основном режиме прерывает чужие хосты, в режиме prod-compose считает их ошибкой.
  `demo-check` тоже считает их ошибкой.
- Проверено 29.10: `https://www.google.com/maps?q=<название>&t=k&z=18&hl=..&output=embed` работает без ключа.
  Перенаправление идёт в пределах `www.google.com`, запрета встраивания нет. Запрос по названию фирмы даёт метку
  с подписью в координатах `54.6412,25.2681`.

**Как должно стать.**
- На `/lt/kontaktai` и `/en/contact` под реквизитами сразу при открытии видна спутниковая карта (`t=k`, z=18) с
  меткой «AIQME, UAB 3D INŽINERIJA IR TECHNOLOGIJOS». Язык интерфейса карты — язык страницы.
- Ширина карты — 100 % колонки, высота 320 px (260 px на ≤900 px).
- Под картой внутри той же рамки — прежняя ссылка «Atidaryti žemėlapį ↗» / «Open map ↗».
- У iframe есть `title`, нет `loading=lazy` и `referrerpolicy`: действует общий `same-origin`.
- CSP `frame-src` дополнительно разрешает только `https://www.google.com`.
- Cookie-плашка добавляет одно предложение о Google Maps.
- Проверки ночью к Google не обращаются: `browser-check` и `demo-check` подменяют документ iframe локальной
  заглушкой.

**Файлы и изменения.**
- `lang/{lt,en}/public.php`, массив `contact`: `map_embed_url` и `map_title` (см. тексты). Над `map_embed_url`
  комментарий: хост должен остаться `https://www.google.com`, его разрешает CSP. `map` и `map_url` не трогать.
- `contact.blade.php`: `<iframe src="{{ __('public.contact.map_embed_url') }}" title="{{ __('public.contact.map_title') }}"></iframe>`
  перед ссылкой. Комментарий стр. 1 обновить.
- `resources/css/public.css`: `.contact-map{height:auto;overflow:hidden}` и комментарий о высоте 210 px прототипа.
- `SecurityHeaders.php:43`: к `frame-src` добавить `https://www.google.com` и дописать комментарий класса.
- `lang/lt/shell.php` и `lang/en/shell.php` `cookie.text` (см. тексты). `cookie-notice.blade.php` — комментарий.
  Поведение плашки не меняется.
- Тесты:
  - `PublicPagesTest` (переименовать тест): ровно один iframe в `div.contact-map`, `src` равен lang-URL
    (hl=en/hl=lt), `title`, нет `loading`, ссылка после iframe, для `/en/contact` и `/lt/kontaktai`;
  - `SecurityHeadersTest`: точный `frame-src` на всех типах ответов и `/lt/kontaktai` в списке;
  - `PaymentRequestsTest:730`: ожидаемый `frame-src` дополнить;
  - `ShellTest:114-115`: новые тексты плашки.
- `bin/browser-check`:
  - `MAP_EMBED` (regex `^https://www\.google\.com/maps\?[^#]*output=embed`), `MAP_STUB` и `map_stub()`;
  - в guard основного режима первым условием — заглушка и счётчик `report.map_stubbed`;
  - в prod-режиме `foreign()` исключает `MAP_EMBED`, а `context.route(MAP_EMBED, map_stub)` регистрируется **после**
    `route(foreign, abort)`;
  - новая `check_contact_map` на обеих страницах при 390 и 1440: один видимый iframe, `src` и `hl`, `title`, нет
    `loading`; ширина равна рамке; высота 320/260; ссылка под картой внутри рамки; `.legal-note` не перекрыта;
    кадр загружен; снимок рамки `shots/contact-map-<lt|en>-<ширина>.png`;
  - в сводке строка «Карта Google Maps (контакты): подменена заглушкой N раз»;
  - в docstring — строка о заглушке.
- `bin/demo-check`: та же заглушка до ветки «запрос вне сборки». `demo/` не пересобирать. В старой сборке iframe нет,
  поэтому ветка заглушки ночью не выполняется: «проверено чтением кода, ждёт пересборки демо» (зелёный
  `demo-check` её не доказывает).
- `aiqme-site/docs/verification/production-launch.md`, «Ждёт проверки»:
  - пункт «после deploy проверить карту на /lt/kontaktai»;
  - в пункте о политиках (D-27) — предложенный раздел cookie для заказчика (тексты ниже).

**Тексты.**

| Ключ | LT | EN |
|---|---|---|
| `public.contact.map_embed_url` | `https://www.google.com/maps?q=AIQME%2C%20UAB%203D%20in%C5%BEinerija%20ir%20technologijos%2C%20Metalo%20g.%204%2C%20Vilnius&t=k&z=18&hl=lt&output=embed` | то же с `hl=en` |
| `public.contact.map_title` | AIQME vieta žemėlapyje | AIQME location map |
| `shell.cookie.text` | Analitika ir reklamos sekimas nenaudojami. Puslapyje „Kontaktai“ rodomas „Google Maps“ žemėlapis – jį įkeliant „Google“ gali naudoti savo slapukus. | No analytics or advertising tracking is used. The Contact page shows a Google Maps map; Google may use its own cookies when it loads. |

Раздел для страницы cookies заказчик публикует сам в админке («Informacija ir SEO → Puslapių tekstai → cookies»,
D-27). Сид-черновик не меняется (D-11):
- заголовок: «Trečiųjų šalių turinys: „Google Maps“» / «Third-party content: Google Maps»;
- текст LT: «Puslapyje „Kontaktai“ įterptas „Google Maps“ žemėlapis. Atidarius šį puslapį, žemėlapis įkeliamas iš
  „Google“ serverių: „Google“ gauna jūsų IP adresą ir gali naudoti savo slapukus pagal [„Google“ privatumo
  politiką](https://policies.google.com/privacy). AIQME šių slapukų nevaldo.»;
- текст EN: «The Contact page contains an embedded Google Maps map. When you open this page, the map is loaded from
  Google servers: Google receives your IP address and may use its own cookies under the [Google Privacy
  Policy](https://policies.google.com/privacy). AIQME does not control these cookies.»

**Проверки.**
- Feature-тесты выше.
- `browser-check`: `check_contact_map` зелёный; «Прерванные запросы к чужим хостам: нет»; N > 0; нет нарушений CSP.
- Самопроверка один раз: без `www.google.com` в `frame-src` проверка `bin/browser-check /en/contact` падает
  с ошибкой CSP. Затем вернуть.
- Настоящий вид карты проверяет пользователь после deploy. Ночью этого сделать нельзя (D-20).

**Риски.**
- **Правовой риск:** карта грузится без согласия, Google получает IP и свои cookie. Пользователь решил показывать
  карту сразу; плашка и раздел cookie это раскрывают. Окончательные политики — за заказчиком (D-27).
- Встраивание без ключа официально не документировано. Если Google его отключит, вместо карты будет ошибка во
  фрейме, а ссылка продолжит работать. URL меняется в админке (ui_text) без deploy.
- Запрос идёт по названию карточки фирмы. Если карточку переименуют, запасной вариант — координаты в `q=` (метка
  без подписи).
- Если на рабочей БД есть своё ui_text `shell.cookie.text`, новый текст не покажется. Проверить после deploy.
- Если в ui_text `map_embed_url` вписать другой хост, его заблокирует CSP. Об этом предупреждает комментарий в lang.

## П6. Калькулятор, шаг 02 «Gamybos pasirinkimai»

**Как сейчас.**
- Шаблон `calculator.blade.php:51-77`:
  - сетка: Medžiaga, Spalva, Kokybės lygis (`quality`), Papildomas apdirbimas (`finish`);
  - блок «FDM parametrai»: Sluoksnio aukštis (`layer`), Užpildas (`infill`: 20/40/60/100 ∪ `default_infill`),
    `fdm_note`;
  - «Pageidaujama darbų eiga» (`term`) и `preferences_note`; затем «Detalių kiekiai».
- Поток данных:
  - JS `resources/js/calculator/index.js`: `parameters()` (стр. 60-66), `submitOrder()` (стр. 97), обработчик
    `change` → `quote.infill=Number(value)` (стр. 266-268);
  - `OrderRequestController` (`$choice` по ключам lang, `infill` integer 0..100, `preferences`);
  - `OrderSubmission` (`StlPart.parameters`, `OrderMeta::create`, `digest`);
  - вывод — только карточка заказа админки `admin/orders-card.blade.php:16-26`.
- Письмо, Telegram, Google Sheets, счёт и кабинет параметров деталей не выводят (проверено).
- На цену влияют только материал и заполнение (`EstimateService`).
- Поля для текста клиента нет. В `order_meta` нет `notes`.

**Как должно стать** (сверху вниз, по макету 23.37.38 и спискам из переписки):
1. Medžiaga | Spalva — без изменений.
2. Detalės paskirtis (`purpose`): Vizualiniams tikslams (первая, по умолчанию) / Praktiniam naudojimui / Reikia
   konsultacijos.
   Papildomos paslaugos (`service`, без «*»): Nereikia (первая, по умолчанию) / Šlifavimas ir dažymas / Srieginių
   įvorių montavimas / Detalių surinkimas / Reikia konsultacijos.
3. Блок «FDM parametrai»:
   - Paviršiaus kokybė (`surface`): Aukšta / Standartinė (по умолчанию) / Supaprastinta / Reikia konsultacijos;
   - Užpildas (`infill`): 15 % / 20 % · rekomenduojama / 40 % / 60 % / 80 % / 100 % / Reikia konsultacijos
     (`consult`). `default_infill` из админки добавляется в список, помечается « · rekomenduojama» и выбран по
     умолчанию;
   - `fdm_note` нет.
4. Gamybos terminas (`term`, прежние ключи standard/priority): Įprasta tvarka / Skubos tvarka. Под списком
   legal-note `term_note`. `preferences_note` удалён.
5. Detalių kiekiai — без изменений.
6. Papildoma informacija (`notes`, textarea, необязательное, maxlength 2000) после количеств.

Цена зависит только от материала и заполнения. «Reikia konsultacijos» у заполнения считается по текущему
`default_infill`, а в детали хранится `infill=default_infill, infill_consult=true`. Остальные новые поля —
пожелания, сумму не меняют. `notes` хранится в `order_meta.notes` и виден администратору в карточке заказа:
экранированный, с переносами строк. Старые заказы (quality/layer/finish) показываются с прежними подписями.

**Файлы и изменения.**
- Миграция `database/migrations/2026_10_29_100000_add_notes_to_order_meta.php`: `text('notes')->nullable()`,
  в `down()` — `dropColumn`.
- `OrderMeta.php`: `notes` в `#[Fillable]` и в docblock.
- `lang/{lt,en}/calculator.php` `settings`:
  - удалить quality, qualities, finish, finishes, layer, layers, fdm_note, preferences_note;
  - добавить purpose(s), service(s), surface(s), infill_consult, term_note, notes;
  - изменить term и terms.
- `lang/{lt,en}/admin.php` `orders.legacy`: прежние тексты quality/qualities/layer/layers/finish/finishes
  дословно, с комментарием «параметры заказов до 28.10, только для показа».
- `calculator.blade.php`:
  - `$infills = collect([15,20,40,60,80,100,default_infill])->unique()->sort()`, к опциям добавить `consult`;
  - поля purpose, service, surface (value `standard`);
  - удалить `fdm_note`, `term_note` вместо `preferences_note`;
  - textarea `notes` (maxlength 2000) после `[data-quantities]`;
  - комментарий файла.
- `index.js`:
  - `parameters()` берёт `['color','purpose','service','surface','term']`, без `notes`;
  - `submitOrder()` отправляет `['material','infill','color','purpose','service','surface','term','notes']`;
  - `quote.infill = value==='consult' ? settings.default_infill : Number(value)` с комментарием.
- `OrderRequestController`:
  - `infill` проверяется `Rule::in([...'0'..'100','consult'])`;
  - purpose/service/surface — через `$choice`, `notes` — `nullable|string|max:2000`;
  - удалить сообщения `infill.min/max`;
  - `consult` → `default_infill` и `infill_consult=true`;
  - `preferences` = only(color, purpose, service, surface, term), отдельно `notes`.
- `OrderSubmission`:
  - phpdoc;
  - `parameters` + `infill_consult` (`?? false`);
  - `OrderMeta::create` + `notes` (`?? null`);
  - `digest` включает `infill_consult` и `notes`.
- `app/Models/StlPart.php`: docblock параметров.
- `admin/orders-card.blade.php`:
  - карта новых ключей и `$legacy` (`admin.orders.legacy.*`);
  - строка заполнения с « · Reikia konsultacijos»;
  - блок `#order-notes` с `nl2br(e())` после таблицы файлов.
- Фикстуры preferences → `['color'=>'black','purpose'=>'visual','service'=>'none','surface'=>'standard','term'=>'standard']`:
  - `DemoSeeder.php:58`, `tests/browser/OrderFactory.php`;
  - Sheets/Invoices/CustomerAccount/StlDownload/StripeWebhook/PaymentRequests/OrderWorkflow tests;
  - настоящие `POST /en/quote` в `Scenarios/ClientCancelAfterAdvanceScenarioTest.php:72` и
    `Scenarios/GuestOrderLifecycleScenarioTest.php:138`: `quality/layer/finish` → `purpose=visual`, `service=none`,
    `surface=standard` (иначе 422 вместо `assertCreated`); полноту проверить `grep -rln "'quality'" tests database`;
  - в `AdminOrdersTest` — practical/inserts/high/priority и notes с переводом строки и `<b>`.
- `bin/browser-check` `check_calculator`, шаг 2:
  - старых `select` нет;
  - итог при `infill=20` равен итогу при `consult`;
  - смена purpose/service/surface/term и ввод `notes` итог не меняют;
  - затем `infill=100`, дальше прежний сценарий 5,12;
  - заявка уходит с новыми полями (201);
  - кадры `shots/calculator-step2-<ширина>.png` (шаг 2) и `shots/calculator-step3-<ширина>.png` (шаг 3 с загруженным
    STL и строкой 1000 мм);
  - заказ фабрики `tests/browser/OrderFactory.php` получает `notes`, `check_admin_order` проверяет `#order-notes`, и
    кадр `admin-order-card-<ширина>.png` показывает notes.

**Тексты.**

| Ключ | LT | EN |
|---|---|---|
| `calculator.settings.purpose` | Detalės paskirtis | Part purpose |
| `…purposes.visual / practical / consult` | Vizualiniams tikslams / Praktiniam naudojimui / Reikia konsultacijos | Visual purposes / Practical use / Consultation needed |
| `…service` | Papildomos paslaugos | Additional services |
| `…services.none / painting / inserts / assembly / consult` | Nereikia / Šlifavimas ir dažymas / Srieginių įvorių montavimas / Detalių surinkimas / Reikia konsultacijos | Not needed / Sanding and painting / Threaded insert installation / Part assembly / Consultation needed |
| `…surface` | Paviršiaus kokybė | Surface quality |
| `…surfaces.high / standard / simplified / consult` | Aukšta / Standartinė / Supaprastinta / Reikia konsultacijos | High / Standard / Simplified / Consultation needed |
| `…infill` (без изменений) | Užpildas | Infill |
| `…infill_consult` | Reikia konsultacijos | Consultation needed |
| `…term` | Gamybos terminas | Production lead time |
| `…terms.standard / priority` | Įprasta tvarka / Skubos tvarka | Standard / Urgent |
| `…term_note` | Pasirinkę skubos tvarką, laukelyje „Papildoma informacija“ nurodykite, iki kada reikia pagaminti. Terminas skaičiuojamas nuo užsakymo patvirtinimo. Tikslų terminą suderinsime įvertinę modelį ir kiekį. | If you choose urgent production, state the required completion date in the “Additional information” field. The lead time is counted from order confirmation. We will agree the exact lead time after reviewing the model and quantity. |
| `…notes` | Papildoma informacija | Additional information |

LT — тексты заказчика. EN — перевод, его стоит показать заказчику вместе с ответом.

**Проверки.**
- `CalculatorPageTest` (тест шага 2 переписать):
  - точные списки опций EN и выбранные значения;
  - порядок имён полей `['material','color','purpose','service','surface','infill','term','notes']`;
  - старых `select` нет, `fdm_note` и `preferences_note` нет;
  - `term_note` дословно, `textarea` с maxlength 2000;
  - LT при `default_infill=30`: `30 % · rekomenduojama` выбран, подписи без «*».
- `OrderSubmissionTest`:
  - payload и точный массив `parameters` с `infill_consult=false`; `notes=null` без поля и при пустой строке;
  - `consult` → `infill=20`, `infill_consult=true`, итог равен итогу при 20 и отличается от итога при 100;
  - пожелания и `notes` итог не меняют, `notes` сохранён дословно;
  - тот же токен с другим `notes` или `consult` → 422 `submission_token`;
  - неверные значения (`abc`, `fine`, `smooth`, `0.2`, `express`, 2001 символ, нет `purpose`) отклоняются без
    записей.
- `AdminOrdersTest`:
  - лист Bambu с новыми подписями и без «Quality level»;
  - `notes` экранированы, с `<br />`; у заказа без `notes` блока нет;
  - старый заказ с прежними ключами — прежние подписи LT/EN; консультация — «Infill 20% · Consultation needed».
- `browser-check check_calculator` на 390 и 1440, `check_admin_order`, `bin/demo-check`, если он есть в прогоне.
- Ручная сверка кадров шага 2 с макетом 23.37.38 (ждёт пользователя).

**Риски.**
- Страница калькулятора, открытая до выпуска и отправленная после него, получит 422: в ней нет `purpose`. Реальные
  заказы ещё не открыты, поэтому это допустимо.
- Без `admin.orders.legacy` тестовые заказы на рабочей БД показали бы ключи вместо подписей. Это покрыто тестом.
- Значение `consult` в любом `Number(value)` даёт NaN. Поэтому обработка в `index.js` обязательна и проверяется в
  браузере.
- Нужна миграция на сервере. Её выполняет `migrate --force` при deploy.
- Сверка шага 2 с прототипом §7.1 расходится намеренно. Это отмечается в `progress.md`.

## П7. Габарит FDM 1000 × 1000 × 1000 мм

**Как сейчас.**
- Шаг 03 выводит `calculator.review.build_text` = `FDM: :mm × :mm × :mm mm.` из настройки `max_dimension_mm`.
  Формат уже нужный, меняется только число.
- Начальное значение 250 и пометка `unconfirmed` заданы миграцией `2026_10_21_100001` (D-24). На рабочей БД она
  уже выполнена.
- Та же величина — порог предупреждения при разборе STL: сервер (`OrderSubmission` → `StlAnalyzer::measure`) и JS
  (из `data-settings`).
- Админка допускает 10…2000. Старые заказы показывают предупреждение из своего снимка настроек.

**Как должно стать.** На шаге 03 написано «Įrangos dydžio ribos — FDM: 1000 × 1000 × 1000 mm.» / «Build size limits —
FDM: 1000 × 1000 × 1000 mm.». Порог предупреждения — 1000 мм в браузере и на сервере. В админке
«Įrangos kraštinė, mm» = 1000 без пометки «Nepatvirtinta». Значение администратора, если оно уже не 250, не
перезаписывается.

**Файлы и изменения.**
- Новая миграция данных `database/migrations/2026_10_29_000000_set_calculator_max_dimension_1000.php`
  (через `DB::table`, без модели):
  - `up()`: если записи `calculator` нет или `max_dimension_mm !== 250` — ничего не делать. Иначе поставить 1000 и
    убрать `max_dimension_mm` из `unconfirmed`;
  - `down()`: при значении 1000 вернуть 250 и пометку.
  Старую миграцию не менять.
- Код, JS, шаблоны и lang **не меняются**.
- Тесты:
  - `CalculatorSettingsTest`: 1000, без пометки, `unconfirmed` без `max_dimension_mm`, подписи без «Not confirmed»;
  - новый `test_migration_sets_confirmed_1000_mm_only_over_the_initial_250`: 250 → 1000; повторный запуск ничего
    не меняет; 320 сохраняется; без записи — без ошибки и без вставки;
  - `CalculatorPageTest:33` → 1000, текст шага 3 LT/EN;
  - новый тест в `OrderSubmissionTest` (Feature-теста `exceeds_build_size` раньше не было, его проверяли только
    `tests/js/calculator-quote.test.js:125`): фикстура `cube-10mm-binary.stl`, масштаб ограничен 1–1000 %, поэтому
    нужен unit `in`. `unit=in, scale=473` (≈1201 мм) → в `StlPart.measurement['warnings']` есть `exceeds_build_size`;
    `unit=in, scale=315` (≈800 мм) → нет.
- `bin/browser-check check_calculator_settings` (стр. 1126-1152):
  - пометки нет ни в начале, ни после сохранения;
  - значения 300 и 1000 видны в `data-settings` и в тексте шага 3;
  - итог возвращается к 1000;
  - описание в docstring.
- `aiqme-site/docs/progress.md` — запись П7. Запись в `docs/planning/edition-2/decisions.md` (D-24) ночной автор
  не делает: `docs/planning/` ему запрещён. Подтверждение 1000 мм фиксирует этот план. По желанию пользователь сам
  дописывает строку в D-24 (см. «После прогона»).

**Тексты.** Без изменений. При значении 1000 выводится «FDM: 1000 × 1000 × 1000 mm.», знак ×, без разделителя
тысяч.

**Риски.**
- Если на рабочей БД администратор уже поставил другое число, миграция его не тронет. После deploy проверить
  вкладку Calculator.
- 1000 мм — порог предупреждения, а не блокировка (D-24).

---

## Принятые по умолчанию мелкие решения (по умолчанию, можно изменить)

| № | Решение |
|---|---|
| 1 | П1: исправление в `shell.css` селектором `.wrap.page-content`; значения 48/28/20 повторяют `.wrap`; центрирование `.article` (FAQ, политики) как в прототипе; 1280 px проверяется на трёх страницах, а не во всех `WIDTHS` |
| 2 | П2: порог — высота шапки + 50 px, переход 0,3 с ease, как у 3dbonum; тень в белом состоянии не добавляется |
| 3 | П2: полупрозрачность главной `rgba(10,17,25,.6)` ради контраста над светлыми кадрами ролика |
| 4 | П2: цвета тёмного состояния по WCAG AA: белый, подпись `#d5dde6`, CTA/hover/active `#9cc4ff`, неактивный язык `#b7c3ce`. Контраст над худшим фоном главной (второй ряд прозрачный: `rgba(10,17,25,.6)` поверх `rgb(161,161,161)` ≈ `rgb(70,75,79)`): белый 8.85, `#d5dde6` 6.45, `#b7c3ce` 4.93, `#9cc4ff` 4.95. Прежний `#8ab8ff` давал 4.37 < 4.5 (CTA — жирный 11–13 px, не крупный текст) |
| 5 | П2: выпадающее меню ≤900 px всегда белое; слот `logo_white` (PNG/WebP), файл `defaults/logo-white.png`; имя `logo-light` не используется (так назывались удалённые копии, D-25) |
| 6 | П2: белое умолчание не показывается рядом со своим обычным логотипом; в тёмной шапке тогда стоит обычный логотип на белой подложке |
| 7 | П2: скрипт изготовления логотипа не коммитится, проверяется готовый файл |
| 8 | П3: версии `altcha-org/altcha` 2.3.0 и npm `altcha` 3.4.1 (альтернатива — 3.3.0) |
| 9 | П3: PBKDF2/SHA-256, cost 5000, counter 5000–10000, срок 30 мин, выдача 30/мин с IP |
| 10 | П3: `auto=onfocus`, видимый чекбокс, подвал виджета скрыт |
| 11 | П3: повтор отсекается через `Cache::add` по подписи, без таблицы; капча проверяется до валидации полей; один текст ошибки на три формы |
| 12 | П3: отказ закрытый, без запасного ключа; `worker-src` не добавляется |
| 13 | П3: регистрации кабинета throttle не добавляется |
| 14 | П4: поле `label` остаётся в схеме и данных, убирается только вывод |
| 15 | П5: URL по названию фирмы, `t=k`, z=18, `hl` по языку |
| 16 | П5: хост `www.google.com`; без lazy, `referrerpolicy`, sandbox и `allowfullscreen`; `frame-src` глобально |
| 17 | П5: URL и title карты — ключи lang (правятся в админке); `map_url` ссылки не меняется |
| 18 | П5: сид-черновик cookies не меняется, раздел публикует заказчик |
| 19 | П6: новые ключи `purpose`/`service`/`surface`; `term` и `color` прежние; подпись «Užpildas» без «(%)», как на макете |
| 20 | П6: `consult` у заполнения → `default_infill` + `infill_consult` |
| 21 | П6: `notes` в `order_meta.notes`, лимит 2000, только в карточке админки (не в письме, Telegram, Sheets, счёте и кабинете) и не в ссылке «Kreiptis į specialistą» |
| 22 | П6: EN-переводы новых вариантов — в таблице П6 |
| 23 | П7: значение меняет миграция данных, только если стоит 250; пометка «не подтверждено» снимается |
| 24 | Общее: демо-сборка `aiqme-site/demo/` не пересобирается (только по указанию пользователя); `demo-check` готовится заранее |
| 25 | Общее: строки о правках в `docs/requirements.md` пишет только итоговая задача E-10, чтобы задачи не конфликтовали в одном файле |

## Порядок задач

Сначала идут простые задачи, затем калькулятор, капча и шапка. Задачи E-01…E-09 по коду независимы и могут
выполняться в любом порядке, кроме E-07 (после E-06) и E-09 (после E-08). Но многие из них трогают одни и те же
файлы (`bin/browser-check`, `shell.css`, `lang/*/public.php`, `contact.blade.php`), поэтому идут последовательно:
каждая учитывает уже внесённое.

| id | Правка | Название | track | Этап | depends |
|---|---|---|---|---|---|
| E-01 | П4 | Убрать подпись над названием услуги | L | S1 | — |
| E-02 | П7 | Габарит FDM 1000 мм | L | S1 | — |
| E-03 | П1 | Боковые поля контента страниц | L | S1 | — |
| E-04 | П5 | Карта Google Maps на «Kontaktai» | L | S2 | — |
| E-05 | П6 | Шаг 02 калькулятора | L | S2 | — |
| E-06 | П3 | ALTCHA: зависимости, сервис и выдача задач | L | S3 | — |
| E-07 | П3 | ALTCHA на трёх формах | L | S3 | E-06 |
| E-08 | П2 | Белый логотип и слот `logo_white` | L | S4 | — |
| E-09 | П2 | Шапка-шторка | L | S4 | E-08 |
| E-10 | все | Сквозная проверка правок и статус в requirements.md | L | S5 | E-01…E-09 |
| E-11 | все | Выпуск на aiqme.com | P | S6 | E-10 |

На E-11 ночной прогон останавливается (track P).

## Подготовка прогона

> **Сделано 29.10 (дневная сессия):** `night.py --tasks` (и `AIQME_TASKS` в `night-run.sh`), шаблон id `[A-Z]-\d+`,
> glob сводки `[A-Z]-*`, предел проверки 60 мин, промпты берут путь очереди, `plan_refs` и `agent_note` из этого
> tasks.json (без номеров rules и исключения W-21). `--dry-run` показывает E-01…E-10 «к выполнению» и E-11 «стоп (P)».
> Будильник и сторож сети (`night-at.sh`, `night-netwatch.sh`) для E-xx не доработаны — запуск только напрямую.

`docs/planning/edition-2/run/night.py` привязан к очереди редакции 2. Ночной автор `night.py` не меняет:
`docs/planning/**` ему запрещён, правку делает пользователь или дневная сессия до запуска. В `night.py` нужно
изменить следующее.

1. **Путь очереди.** `TASKS = ROOT / "docs/planning/edition-2/tasks.json"` (стр. 30). Добавить аргумент
   `--tasks` (по умолчанию прежний путь) и передать его из `night-run.sh`, например через переменную `AIQME_TASKS`.
   Можно и проще: сделать копию `night.py`/`night-run.sh` с другим путём. Путь очереди должен попадать и в
   промпты (п. 3).
2. **Шаблон id.** `done_tasks()` ищет `^night: (W-\d+)` (стр. 150), поэтому выполненные E-xx не распознаются, и при
   `--resume` или следующей ночи задачи начнутся заново. Шаблон нужно заменить на `^night: ([A-Z]-\d+)`. Коммит
   `night: {tid} …` (стр. 439) менять не нужно. Справка `--only` (стр. 489) — пример `E-01`.
3. **Тексты промптов.**
   - `WORK_PROMPT` (стр. 75-81) и `REVIEW_PROMPT` (стр. 105-106): вместо `docs/planning/edition-2/tasks.json`
     подставлять путь очереди; вместо «разделы edition-2, decisions.md» — «план
     `docs/planning/edits-20261029/README.md` (раздел правки задачи) и, где он ссылается, edition-2/decisions.md».
   - Строку «если это задача W-21, строку «Новый сайт» в docs/requirements.md» заменить на «если это задача E-10 —
     раздел «Правки заказчика 28.10» в docs/requirements.md».
   - В обоих промптах дописать: «Эта очередь — правки заказчика 28.10; перечисленные в README плана отступления от
     прототипа (шапка, поля, подпись услуг, карта, шаг 2 калькулятора, габарит, капча) — требование, а не
     расхождение; тексты LT/EN — из README плана, а не из эталона».
   - Номера правил в промптах — по нумерации edition-2. В этой очереди «progress.md» — `rules[12]` (а не
     `rules[13]`), «эталон и тексты» — `rules[6]` (а не `rules[8]`). Ссылки на номера в промптах поправить или
     убрать.
   - `W01_EXTRA` (стр. 92, 268) для E-xx не срабатывает. Менять не нужно.
4. **Сводка.** `report()` перебирает только `self.out.glob("W-*.work*.stdout.json")` (стр. 464), поэтому разделов
   «Итог автора» для E-xx в `СВОДКА.md` не будет. Заменить на `glob("[A-Z]-*.work*.stdout.json")`.
5. **Время.** `check()` даёт каждой проверке 30 минут (`run_limited(..., 30 * 60)`, стр. 316). В ночь W-21 `bin/check`
   и `bin/browser-check` вместе заняли около 27 минут (работа автора закончилась в 19:10:56, логи проверок записаны в
   19:37:44), а план добавляет проверки (поля на 5 ширинах, шапка, карта, капча, регистрация). Предел поднять до
   60 минут (лучше параметром, например `--check-minutes`, по умолчанию 60). Сессию автора на эту ночь — до 180 минут:
   `AIQME_TASK_MINUTES=180` (у `night-run.sh` уже есть этот параметр). Автору правило очереди `rules[15]`: во время
   разработки — `bin/browser-check` с путями, полный прогон — один раз в конце.
6. **Способ запуска.** Запускать только `night-run.sh` напрямую (в терминале или через `systemd-run`, см. его
   шапку) с `AIQME_TASKS=docs/planning/edits-20261029/tasks.json AIQME_TASK_MINUTES=180`. Обёртки `night-at.sh`,
   `morning-run.sh`, `night-supervise.sh` и `finish-all.sh` запускают сторожа `night-netwatch.sh`, а он к E-xx не готов:
   - ищет текущую задачу шаблоном `'W-[0-9]+ start:'`: для E-xx ничего не найдёт, при пропаже сети работа не уйдёт в
     stash, а следующий `night-run.sh --resume` откажется стартовать из-за занятого дерева;
   - вызывает `night-run.sh --resume` без `AIQME_TASKS`, то есть с очередью edition-2;
   - ищет процесс по `edition-2/run/night.py` (`pgrep`/`pkill`), поэтому копия `night.py` под другим путём для него
     невидима.
   Если сторож всё же нужен: шаблон `'[A-Z]-[0-9]+ start:'`, передача `AIQME_TASKS` во все обёртки (или путь очереди
   этой ночи по умолчанию) и вариант с аргументом `--tasks`, а не копия `night.py`.
7. **Без изменений.** `ALLOWED` (`aiqme-site/`, `docs/requirements.md`), `FORBIDDEN` (`experiments/`), `DENY`
   (в том числе `Edit(./docs/planning/**)` и `WebFetch`), проверки `bin/check` и `bin/browser-check` после каждой
   задачи, остановка на track ≠ L. Composer и npm автору не запрещены. Загрузки с Packagist, `api.github.com` и
   npm разрешены D-20, поэтому E-06 ставит пакеты сама.
8. **Проверка перед ночью.** Выполнить `python3 docs/planning/edition-2/run/night.py --tasks
   docs/planning/edits-20261029/tasks.json --dry-run`: должны быть перечислены E-01…E-10 «к выполнению» и
   E-11 «стоп (P)».
9. **Чистое дерево.** `night-run.sh` не начнёт ночь при незакоммиченной работе. До запуска нужно закоммитить
   `README.md` и `tasks.json` этого каталога, а также правку `night.py`. Про `research.md` см. «Что делает человек».
10. **Этапы.** Чтобы посмотреть результат по частям, можно задать `AIQME_STOP_AFTER_SLICE=S2,S4`: остановка после
   простых правок и калькулятора, потом после шапки.

## Что делает человек

**До прогона.**
1. Сделать правку `night.py` и `night-run.sh` по разделу «Подготовка прогона» (пп. 1–5) и проверить её через
   `--dry-run`.
2. **Убедиться, что текущий `main` зелёный.** Последний полный отчёт `browser-check` — от 23.10 (W-21), а после него
   менялись `bin/browser-check` и калькулятор (27.10) и прошли W-22 и W-23. На чистом `main` выполнить
   `aiqme-site/bin/check` и `aiqme-site/bin/browser-check` (оба меняют только локальное: своя БД `aiqme_browser`,
   `build/`), получить зелёный результат и записать длительность каждого. **При красном ночь не запускать**: иначе
   E-01 получит красную проверку по чужой причине, и то же повторится на каждой задаче. Если какой-то прогон дольше
   ~40 минут, поднять предел проверки (п. 5 «Подготовки») с запасом.
3. Решить судьбу `research.md`. В нём есть короткие дословные цитаты переписки, а репозиторий публичный. Варианты:
   - убрать цитаты перед коммитом;
   - перенести файл в `docs/customer/client/edits-20261028/` (исключён `.gitignore`).
   Ночному прогону `research.md` не нужен: всё нужное есть в README.
4. Закоммитить `docs/planning/edits-20261029/README.md` и `tasks.json`, проверить `git ls-files`.
5. Убедиться, что на машине прогона есть приватный эталон `docs/customer-prototype/evidence/`. Без него
   `check_text` пишет «не проверено».
6. По желанию прогреть кэш: `npm cache add altcha@3.4.1`. Не обязательно.
7. Ставить composer и npm вручную не нужно: E-06 делает это сама.
8. Запуск: `AIQME_TASKS=docs/planning/edits-20261029/tasks.json AIQME_TASK_MINUTES=180 bash
   docs/planning/edition-2/run/night-run.sh` (без `night-at.sh` и сторожа сети, см. п. 6 «Подготовки»).

**После прогона.**
1. Прочитать утреннюю сводку и разделы `progress.md` E-01…E-10. Посмотреть кадры, помеченные «ждёт проверки
   пользователем»:
   - шапка вверху и после прокрутки на главной, «Apie mus», в админке (`shots/header-top-*`, `header-scrolled-*`);
   - шаг 2 калькулятора рядом с макетом 23.37.38 (`shots/calculator-step2-*`), шаг 3 (`calculator-step3-*`);
   - карточки услуг рядом с макетом 20.17.09 (верх `/lt` и `/lt/paslaugos` из основного цикла);
   - поля «Apie mus» на 390 px (`shots/lt__apie-mus-phone.png`);
   - рамка карты (`contact-map-*`), виджет капчи (`altcha-*`), notes в карточке заказа (`admin-order-card-*`).
   Пометки «проверено чтением кода» (ветки `demo-check` для новой сборки, `prod_contact`) — проверить самому после
   пересборки демо или в prod-compose, если это нужно.
2. Push ветки и слияние в `main` — по решению пользователя.
3. **Выпуск (E-11), строго в этом порядке:**
   1. сделать копию БД (`bin/backup` по `operations.md`);
   2. на сервере выполнить `openssl rand -hex 32` и дописать `ALTCHA_HMAC_KEY=<значение>` в `.env` приложения
      (права `root:33 0640` сохранить). **Без ключа после выпуска перестанут приниматься три формы;**
   3. `deploy <rev>`: build → up → `migrate --force` (`order_meta.notes`; `max_dimension_mm` 250 → 1000) →
      bootstrap → optimize → restart. Если ключ добавлен после выпуска — `$C restart app worker scheduler &&
      $A optimize`;
   4. `curl -s https://aiqme.com/altcha` — JSON с `signature`, не 503.
4. Проверить вручную на aiqme.com (ПК и телефон):
   - поля текста на `/lt/apie-mus` (x первого абзаца 20 px на 390 px и 48 px на 1280 px);
   - шапка вверху тёмная, после прокрутки белая; белый логотип;
   - на `/lt` и `/lt/paslaugos` нет подписей «01 / AIQME»;
   - карта на `/lt/kontaktai` и `/en/contact` (спутник, метка, язык), в DevTools нет ошибок CSP;
   - контактная форма: «Patvirtinta» → успех → заявка в админке;
   - шаг 2 калькулятора и пробная заявка с «Papildoma informacija» → текст в карточке заказа;
   - шаг 3 «FDM: 1000 × 1000 × 1000 mm.»; в админке Calculator — 1000 без «Nepatvirtinta»; старые тестовые заказы
     открываются;
   - в админке «Vertimai» нет своего `shell.cookie.text`.
5. Передать заказчику:
   - текст раздела cookie о Google Maps для публикации в админке (D-27, тексты П5);
   - EN-переводы вариантов шага 2 на согласование;
   - пояснение, что капча защищает формы aiqme.com, а не прежний сайт;
   - что без JavaScript формы не отправить;
   - что белый логотип можно заменить своим (PNG/WebP) в админке.
6. По желанию:
   - дописать датированную строку в `docs/planning/edition-2/decisions.md` D-24 («29.10: заказчик подтвердил
     1000 мм — миграция 2026_10_29_000000»);
   - пересобрать демо (`bin/demo-build`) — только если оно нужно.

## Вне плана

- Снимок 19.43.33: на «Apžvalga» рабочего сайта видна одна неудачная фоновая задача. Это не правка заказчика.
  Причину пользователь смотрит на сервере отдельно (`failed_jobs`).
- Защита прежнего сайта (`legacy-landing/`) от ботов: формы туда не переносились, правка не касается.

## Открытые вопросы

Блокирующих вопросов нет: все неоднозначные места решил пользователь 29.10, а мелкие решения приняты по умолчанию
(таблица выше). Подтвердить у заказчика **после** выпуска (на прогон не влияет):
1. Устраивает ли полупрозрачная (а не полностью прозрачная) шапка поверх видео на главной.
2. EN-формулировки новых вариантов шага 2.
3. Нужна ли загрузка карты только по согласию, если этого потребует юрист заказчика при публикации политик (D-27).

## Проверка плана 29.10

Замечания независимых проверяющих сверены с кодом (`bin/browser-check`, `night.py`, `night-netwatch.sh`, тесты,
`OrderRequestController`, `StlAnalyzer`, кэши Composer и npm). Все тринадцать подтвердились. Три из них повторяют друг
друга (порог 1200 мм, сторож и `glob` сводки), они объединены в одну правку.

| № | Замечание | Решение | Что сделано |
|---|---|---|---|
| 1 | CTA `#8ab8ff` во втором ряду главной даёт 4.37 < 4.5 над `rgba(10,17,25,.6)` поверх `rgb(161,161,161)` | принято: расчёт повторён (фон ≈ `rgb(70,75,79)`; белый 8.85, `#d5dde6` 6.45, `#b7c3ce` 4.93, `#8ab8ff` 4.37). CTA жирный 11–13 px, это не крупный текст | CTA/hover/active → `#9cc4ff` (4.95; над сплошным `#0a1119` — 10.62) в П2, решении №4, `check_styles` (`rgb(156, 196, 255)`), E-09 done[3] и done[6] |
| 2 | Сценарии `ClientCancelAfterAdvanceScenarioTest:72` и `GuestOrderLifecycleScenarioTest:138` шлют `quality/layer/finish` в настоящий `POST /en/quote` | принято: оба делают POST с `assertCreated`, а `OrderRequestController` проверяет поля через `$choice` | добавлены в П6 «Фикстуры», E-05 touches и done[7] (`purpose=visual`, `service=none`, `surface=standard`) с проверкой полноты через grep |
| 3, 10 | Feature-теста `exceeds_build_size` нет; куб 10 мм и scale 1–1000 % не дают 1200 мм в мм | принято: предупреждение проверяет только `tests/js/calculator-quote.test.js:125`; `StlAnalyzer::measure` кладёт его в `measurement['warnings']` | П7 и E-02 done[4]: новый тест, `cube-10mm-binary.stl`, `unit=in`, `scale=473` (≈1201 мм) → есть, `scale=315` (≈800 мм) → нет, поле `StlPart.measurement['warnings']`. Вариант 472 (≈1199 мм) тоже >1000, но выбран 473 — ближе к «1200» |
| 4, 9 | `night.py:464` `glob("W-*…")`; сторож ищет `'W-[0-9]+ start:'`, не передаёт `AIQME_TASKS`, ищет `edition-2/run/night.py` | принято: всё видно в коде | «Подготовка прогона» пп. 4 и 6: `glob("[A-Z]-*…")`; запуск только `night-run.sh` напрямую; для сторожа — шаблон, `AIQME_TASKS` и вариант `--tasks` вместо копии |
| 5 | Лимит `/altcha` 30/мин без env, а `browser-check` ходит с 127.0.0.1 и считает 429 ошибкой | принято: так же уже поднят `AIQME_ORDERS_PER_HOUR=100` | `per_minute => (int) env('AIQME_ALTCHA_PER_MINUTE', 30)` (П3, E-06 done[1]); `AIQME_ALTCHA_PER_MINUTE=1000` в `browser_env()` (E-07 done[9]) |
| 6 | Перед ночью нет зелёного `bin/check` и `bin/browser-check` на текущем `main` | принято: последний полный отчёт — 23.10 19:37 (W-21), после него менялись калькулятор и `bin/browser-check` | «Что делает человек → До прогона» п. 2: прогнать оба на чистом `main`, записать длительности; при красном ночь не запускать |
| 7 | Предел проверки 30 мин и сессии 120 мин тесны | принято: `run_limited(..., 30 * 60)`, W-21 — около 27 мин на обе проверки (PHPUnit 351 с) | «Подготовка прогона» п. 5: предел проверки 60 мин; `AIQME_TASK_MINUTES=180`; `rules[2]` — 3 ч; новое `rules[15]`: при разработке `browser-check` с путями, полный прогон один раз в конце |
| 8 | E-10 требует кадров, которых `browser-check` не снимает (только верх страницы, без `full_page`) | принято: `SHOTS` и цикл кадров снимают только верх после `goto`; `admin-order-card` — заказ фабрики без notes | кадры перенесены в задачи: E-09 `header-top/header-scrolled`, E-05 `calculator-step2/step3` и notes в заказе `OrderFactory` для `admin-order-card`, E-04 `contact-map`, E-07 `altcha`; E-10 done[2] перечисляет только существующие кадры, недостающие отмечает «нет кадра» |
| 11 | `git status --porcelain` на E-10 бессмыслен: принятые задачи уже закоммичены | принято; `git diff` автору не запрещён (`DENY`) | E-10 done[3]: `git diff --name-status main...HEAD -- aiqme-site docs` плюс незакоммиченное E-10; единственный новый бинарный — `logo-white.png` |
| 12 | Ветки `demo-check` (старое `demo/`) и `prod_contact` (только `--base-url`) ночью не выполняются | принято: `demo/` есть, но собран до правок; `prod_contact` вызывается только в prod-режиме | новое `rules[15]`, E-04 done[10], E-07 done[9], E-05 и E-09 checks[3]: «проверено чтением кода; ждёт пересборки демо или prod-compose», в `progress.md` — «не проверено» |
| 13 | Имена PHP-API `altcha-org/altcha` 2.3.0 в критериях не сверены | принято: пакета нет ни в `vendor/`, ни в кэше Composer; npm 3.4.1 подтверждён кэшем | П3 и E-06 done[2], done[5]: критерии через поведение, имена (`Payload::fromBase64`, `verifySolution`, `Pbkdf2`, `solveChallenge`) — ожидаемые; фактический API — в `progress.md` |
