# Правки заказчика, ревизия 4 (присланы 03.11, план 05.11)

Четвёртая порция правок к сайту `aiqme-site/`. Сайт уже содержит правки 28.10 ([план](../edits-20261029/README.md)),
30.10 ([план](../edits-20261031/README.md)) и 02.11 ([план](../edits-20261102/README.md), G-01…G-09; на aiqme.com ещё
не выпущены). Исходный PDF «Правки. рев.4» (14 пунктов, снимки экрана с пометками) лежит приватно в
`docs/customer/client/edits-20261103-rev4/` (каталог исключён `.gitignore`); здесь он пересказан. Заказчик смотрел
рабочий aiqme.com, то есть сайт **без** правок 02.11. Поэтому часть пометок уже выполнена в G-01…G-09; такие места
отмечены «уже сделано 02.11».

Очередь — [tasks.json](tasks.json), задачи H-01…H-11 (H-11 в очереди сразу после H-01). Запуск — тот же ночной оркестратор:
`AIQME_TASKS=docs/planning/edits-20261105/tasks.json AIQME_TASK_MINUTES=180 bash docs/planning/edition-2/run/night-run.sh`.

## Решения пользователя 05.11

- **Нумерация счетов — серия заказчика.** Это отменяет решение 02.11 о серии `AIQW`.
  - Формат: `AIQ` + последняя цифра года выписки + номер из 4 цифр. Пример: `AIQ60008` в 2026 году, `AIQ70001` в 2027.
  - Счётчик сбрасывается каждый год.
  - Номер, с которого сайт продолжит, администратор задаёт в админке в день выпуска.
- **Видео hero.** Подпись «Bosch / The Apic» пользователь уже убрал (коммит c1d5d20). Кнопка «Sustabdyti video» тоже
  убирается.
- **Товары — как просит заказчик.**
  - Недорогой товар можно купить прямо на сайте: корзина, оформление, оплата, счёт.
  - У дорогого оборудования остаётся только «Gauti pasiūlymą».
  - Режим выбирает администратор у каждого товара.
  - Блоки «Komplektacija ir palaikymas» и «Dokumentai» заполняются в редакторе товара.
- **Цены в калькуляторе не показываются** (решение заказчика, п. 10). Цену называет администратор после
  отправки запроса.
- **Порядок работы.** План и ночной прогон. Выпуск — вместе с G-10 (правки 02.11), одним разом.

## Пункты PDF → задачи

| PDF | О чём | Задача |
|---|---|---|
| 1 | Логотип, hero, кнопка паузы, CTA, логотип в подвале | H-01 |
| 2 | Номера в надписях разделов, иконки, текст карточки 2 | H-02 (иконки уже сделаны 02.11) |
| 3 | «Darbo eiga»: новые тексты | H-02 |
| 4 | Раздел услуг: заголовок, текст, номера на фото, пункт 3D skenavimas | H-02 |
| 5 | 3D spausdinimas: подзаголовок, пункт | H-03 (подзаголовок уже сделан 02.11) |
| 6 | Страницы услуг: «01 / PRADŽIA», «02 / REZULTATAS», «Aiški darbų eiga», «Vienas kontaktas» | H-03 («Vienas kontaktas» уже убран 02.11) |
| 7 | Товары: прямая покупка, только «Gauti pasiūlymą» у дорогого оборудования, блоки в админке | H-07, H-08 |
| 8 | Apie mus: подзаголовок, абзац, три карточки | H-03 |
| 9 | Контакты: телефон обязателен | H-03 |
| 10 | Калькулятор без цены, тексты страницы | H-04 |
| 11 | Фрезеровка: материалы; толщина и глубина вводятся вручную | H-05 |
| 12 | Сообщение после «Pateikti užsakymą» | H-04 |
| 13 | Нумерация счетов | H-06 |
| 14 | Подвал: «Užsakymo suvestinė», девиз; синий блок: «Įkelti failus» | H-01 |
| — | Белая шапка над белыми страницами (Игорь, 05.11, устно) | H-11 |

Все тексты LT ниже — дословно из PDF. EN-переводы составлены при планировании.
**«Шрифт и стиль сохранить как было»** (п. 3, 4): на макетах заказчика другой шрифт. Меняются только тексты; шрифт,
размеры, стрелки и вёрстка сайта остаются.

## Как устроено хранение текстов (для всех задач)

- **Содержимое страниц** (`page_text`, `service` и т. п.) лежит в БД: опубликованная версия и черновик. Изначально
  оно берётся из `aiqme-site/database/seeders/data/prototype-content.json`. Поэтому правка нужна в двух местах:
  - в JSON;
  - миграцией данных по образцу `2026_11_02_100000_update_texts_g2.php`. Миграция меняет поле, только если в нём
    прежний текст, и правит обе версии. `down` возвращает прежний текст.
- **Удаление поля или элемента списка** (`hero_stages`, `intro_paragraphs[1]`) делается такой же миграцией.
  Редактор админки строит поля по ключам JSON (`ContentTypes.php`).
- **Тексты lang** (`lang/lt|en/*.php`) правятся в файлах. Если администратор переопределил ключ в `ui_texts`, файл не
  перекрывает это переопределение. На рабочем сайте переопределений этих ключей не ожидается; при выпуске это
  проверяется.
- **`bin/browser-check`, `check_text`** сравнивает тексты `<main>` с эталонным DOM прототипа. Каждый изменённый или
  удалённый текст `<main>`, который есть в прототипе, получает запись `D21_REPLACEMENTS` с комментарием
  «правка 05.11, H-xx». Удалённый текст записывается как `''` с регулярным выражением страниц.

---

## H-01. Шапка, hero, CTA, подвал (п. 1, 14)

**Известная поломка на `main`.** Коммит c1d5d20 убрал подпись видео Bosch, но не добавил запись
`('Bosch / The Apic ↗', '', '^/(lt|en)$')` в `D21_REPLACEMENTS`. Поэтому `bin/browser-check /lt /en` сейчас красный.
H-01 исправляет это первым делом.

1. **Логотип чуть меньше и чуть ниже**, в подвале такой же.
   - Ширина (`shell.css`, `LOGO_WIDTHS` в browser-check): 220 → **200**, ≤1150: 180 → **164**, ≤600: 140 → **128**,
     ≤360: 132 → **120**.
   - Подвал — те же значения.
   - Отступ сверху больше: ряд шапки с логотипом и меню выше на 8 px на ПК (82 → 90), на 6 px при ≤900, на 4 px
     при ≤600. Логотип и меню остаются по центру ряда.
   - `--header-height` и отступ hero под шапкой пересчитываются, чтобы hero не залезал под шапку
     (`check_hero_under_header`).
   - CSS прототипа не меняется: правки только в `shell.css`.
2. **Надпись hero.** LT `3D INŽINERIJA & TECHNOLOGIJOS` (было `3D INŽINERIJA IR TECHNOLOGIJOS`). EN остаётся
   `3D ENGINEERING & TECHNOLOGY`.
   - Меняется только `hero_eyebrow` на главной.
   - Надпись второго ряда шапки на внутренних страницах (`shell.tagline`) не меняется.
3. **Подзаголовок hero** (`hero_text`): LT `Vienas partneris visiems gamybos etapams`, EN
   `One partner for every stage of production`.
   - Точки в конце нет, как в макете.
   - Мета-описание главной берётся из этого поля. Если описание стало короче допустимого `PublicSeoTest`, тест
     меняется с комментарием.
4. **Ряд `01 SKENUOTI → 02 PROJEKTUOTI → 03 GAMINTI` убирается.**
   - Удаляется разметка в `home.blade.php`.
   - Поле `hero_stages` удаляется из JSON и миграцией из БД.
   - Неиспользуемый CSS в `public.css`/`shell.css` удаляется. CSS прототипа не трогается.
5. **Кнопка «Sustabdyti video» / «Pause video» убирается.**
   - Видео играет само.
   - При `prefers-reduced-motion: reduce` видео не запускается, показывается постер, как сейчас при паузе.
   - `hero-video.js` сейчас запускает видео, только если кнопка есть. Условие нужно переписать, иначе видео
     перестанет играть (`check_motion`).
   - Ключи `public.hero.play/pause/…` удаляются, если больше нигде не используются.
6. **Главная кнопка «Įkelti modelį ir sužinoti kainą»** (hero `public.hero.quote`, второй ряд шапки `shell.quote_cta`,
   пустая корзина): LT `Įkelkite failus ir gaukite pasiūlymą`, EN `Upload files and get a quote`.
   - На сайте больше нет цены сразу (п. 10). Красная надпись в п. 1 указывает на эту кнопку.
7. **Синий блок «Jūsų kitas žingsnis — paprastas.»** — кнопка `public.cta.quote`: LT `Įkelti failus`, EN `Upload files`.
   - Кнопка карточек услуг `Įkelti modelį ↗` → LT `Įkelti failus ↗`, EN `Upload files ↗`, для единообразия.
8. **Подвал.**
   - Девиз `shell.footer.slogan`: LT `Vienas partneris visiems gamybos etapams.`, EN
     `One partner for every stage of production.`
   - Ссылка `shell.footer.calculator`: LT `Užsakymo suvestinė`, EN `Order summary`. Адрес прежний — калькулятор.

## H-02. Главная: надписи разделов, «Darbo eiga», услуги (п. 2, 3, 4)

1. **Номера в надписях разделов убираются на всей главной.**
   - LT: `PRADĖKIME NUO JŪSŲ`, `DARBO EIGA`, `PASLAUGOS`, `PARTNERIAI IR TECHNOLOGIJOS`, `ĮRANGA IR MEDŽIAGOS`,
     `NAUJIENOS IR ĮŽVALGOS`.
   - EN: те же надписи без `0n / `.
   - Поля `*_eyebrow` правятся в JSON и миграцией.
2. **Карточка 2 «Turiu failą. Reikia pagaminti.»** (`scenarios[1].text`).
   - LT `Įkelkite failą, pasirinkite gamybos parametrus ir gaukite preliminarų pasiūlymą.`
   - EN `Upload a file, choose the manufacturing parameters and get a preliminary quote.`
   - Прежний текст — вариант G-02 02.11. Если на рабочем сайте окажется текст до G-02, его тоже нужно заменить, то
     есть «прежних» значений два.
   - Иконки уже заменены 02.11 (G-01). Не трогать.
3. **«Darbo eiga»** (`process_title`, `process_text`, `process_steps`). Стрелки и номера 01–04 остаются.
   - Заголовок: LT `Aiškus ir nuoseklus gamybos\nprocesas.`, EN `A clear and consistent\nmanufacturing process.`
   - Текст справа: LT `AIQME koordinuoja visą procesą. Jums nereikia patiems derinti skirtingų tiekėjų.`, EN
     `AIQME coordinates the whole process. You don't need to coordinate different suppliers yourself.`
     Если поле выводит `\n` как `<br>`, перенос ставится после первого предложения, как в макете.

   | № | LT заголовок | LT текст | EN заголовок | EN текст |
   |---|---|---|---|---|
   | 01 | Užklausos įvertinimas | Pagal jūsų pateiktą failą, eskizą, nuotrauką, idėją ar pačią detalę įvertiname ir suderiname gamybos procesą, medžiagą, kainą bei terminą. | Request assessment | Based on the file, sketch, photo, idea or the part itself, we assess and agree on the manufacturing process, material, price and lead time. |
   | 02 | Gamyba | Paruošiame skaitmeninį modelį ir pagal suderintus reikalavimus pagaminame detalę. | Manufacturing | We prepare the digital model and manufacture the part to the agreed requirements. |
   | 03 | Kokybės kontrolė | Patikriname pagamintos detalės kokybę, matmenis ir atitiktį suderintiems techniniams reikalavimams. | Quality control | We check the quality, dimensions and compliance of the finished part with the agreed technical requirements. |
   | 04 | Perdavimas | Pagaminta detalė perduodama užsakovui arba pristatoma nurodytu adresu. | Handover | The finished part is handed over to the customer or delivered to the specified address. |

4. **Раздел услуг** (главная и список `/paslaugos`).
   - Заголовок `services_title`: LT `Skirtingos technologijos\njūsų užduočiai.`, EN `Different technologies\nfor your task.`
   - Текст `services_text`: LT `Sujungiame skaitmeninį modeliavimą ir fizinę gamybą. Pasirinkite reikiamą paslaugą
     arba patikėkite mums visą procesą.`, EN `We combine digital modelling and physical manufacturing. Choose the
     service you need or entrust us with the whole process.`
   - **Номера 01–06 в углу фотографий убираются** (`partials/service-features.blade.php`). Тест бейджей меняется.
   - 3D skenavimas, первый пункт (`service scanning` → `benefits[0]`): LT `Objekto geometrija perkeliama į
     skaitmeninį 3D modelį.`, EN `The object's geometry is transferred into a digital 3D model.`

## H-03. Страницы услуг, «Apie mus», контакты (п. 5, 6, 8, 9)

1. **Все страницы услуг.**
   - Убираются надписи `01 / PRADŽIA` и `02 / REZULTATAS` (EN `01 / INPUT`, `02 / OUTPUT`) целиком: заказчик
     обвёл всю надпись. Заголовки «Ką pateikti?» / «Ką gausite?» и текст карточек остаются.
   - Убирается блок «Aiški darbų eiga.» со всеми шагами (`service.blade.php`, ключ `public.service.workflow`).
   - FAQ идёт сразу за карточками с обычным отступом. Тест порядка блоков (`PublicPagesTest`) меняется.
   - «Vienas kontaktas visam darbui» уже убран 02.11.
   - Страницы проектов (`01 / Užduotis` …) заказчик не отмечал. Они не меняются.
2. **3D spausdinimas.**
   - Второй пункт «Kada naudinga» (`problems[1]`): LT `Reikia vienos detalės arba serijos`, EN
     `Need a single part or a series`. Прежние варианты — до и после G-02.
   - Подзаголовок `Prototipai, funkcinės detalės ir detalių serijos.` уже сделан 02.11.
3. **Apie mus.**
   - Подзаголовок под H1 `Vienas partneris – nuo jūsų užduoties iki pagaminto produkto.` убирается со страницы
     (вывод `pages.about.description` в `about.blade.php`). Мета-описание страницы берётся из `intro_paragraphs[0]` и
     не меняется.
   - Абзацы после «Sujungiame idėją, modelį ir gamybą.»: на макете два абзаца. Первый не меняется, второй — новый
     текст. Поэтому:
     - `intro_paragraphs[1]` («Pradedame nuo paskirties…») удаляется;
     - `[2]` становится LT `Visą procesą koordinuoja AIQME. Aiškiai susitariame dėl darbų apimties, o pakeitimus
       deriname prieš juos vykdydami.`, EN `AIQME coordinates the whole process. We clearly agree on the scope of
       work and agree any changes before making them.`
     - Это толкование макета; при просмотре его подтверждает пользователь.
   - Три карточки (`panels`):

   | LT заголовок | LT текст | EN заголовок | EN текст |
   |---|---|---|---|
   | Individualus požiūris | Kiekvienai užduočiai parenkame tinkamiausią sprendimą. | Individual approach | We choose the most suitable solution for every task. |
   | Sklandus procesas | Pasirūpiname visais etapais, kad jums nereikėtų rūpintis detalėmis. | Smooth process | We take care of every stage so you don't have to worry about the details. |
   | Atsakingas požiūris | Kiekvieną užsakymą vykdome pagal suderintus reikalavimus. | Responsible approach | We carry out every order according to the agreed requirements. |

4. **Kontaktai: телефон обязателен.**
   - Подпись: LT `Telefonas`, EN `Phone` (без «(neprivaloma)»).
   - Атрибут `required`, правило `required` в `FormController`.
   - Обновляются тесты, которые отправляют форму без телефона (`AltchaTest`, `FormLabelsTest`), и заполнение формы в
     `bin/browser-check` (`check_contact_form`) и `bin/demo-check`.
   - Колонка БД остаётся nullable: старые заявки без телефона.

## H-04. Калькулятор без цены; тексты; сообщение после отправки (п. 10, 12)

**Цель.** Покупатель нигде не видит сумму, пока администратор не подтвердил цену. Сервер по-прежнему считает
оценку и показывает её администратору: карточка заказа в админке и письмо администратору не меняются. Оплата после
подтверждения цены (PaymentRequest → Stripe) не меняется.

1. **Шаги** (макеты п. 10–12): `Failai` → `Gamybos parametrai` → `Užklausos pateikimas`. EN: `Files` →
   `Manufacturing parameters` → `Submit request`.
   - Шаги «Kaina» (таблица «Detalė / Kiekis / Sąmata») и отдельная «Patikra» убираются.
   - Сведения проверки файла остаются у каждого файла на шаге 2: размеры, масштаб, предупреждения о размере станка,
     «цену назначит специалист» — без суммы.
   - Число шагов в JS (`index.js`: 239, 243, 339) приводится к трём.
2. **Тексты страницы** (`lang/*/pages.php`, `lang/*/calculator.php`):
   - заголовок и хлебные крошки: LT `Jūsų failai. Mūsų kitas žingsnis.`, EN `Your files. Our next step.`;
   - вступление и мета-описание: LT `Įkelkite failus, pasirinkite gamybos parametrus ir gaukite pasiūlymą.`, EN
     `Upload files, choose the manufacturing parameters and get a quote.`;
   - кнопка выбора: LT `Pasirinkti failus`, EN `Choose files`;
   - шаг 1 по макету: строка форматов `STL, STEP, OBJ, DXF, DWG. Galite pasirinkti kelis failus.`, затем зона
     «Nutempkite failus čia arba pasirinkite», кнопка и `Iki 50 MB vienam failui.`; прочие строки под зоной убираются;
   - заголовок `01. Įkelkite savo modelius`: на макете его нет, убирается.
3. **Панель «Užsakymo suvestinė»** справа.
   - Без надписи «PRELIMINARI SĄMATA», без сумм и итога.
   - Список файлов «имя × количество», как на макете п. 11.
   - Заметка: LT `Galutinę kainą ir gamybos terminą patvirtinsime peržiūrėję jūsų užklausą.`, EN `We will confirm the
     final price and production time after reviewing your request.`
   - Блок «Reikia pagalbos?» остаётся.
4. **Тарифы не уходят в браузер.** Из `data-settings` убираются `rate_per_gram`, `order_fee_cents`, `file_fee_cents`
   и цены материалов. Остаются только данные для проверки файла: размеры, станки, списки материалов.
   - Расчёт оценки в JS, который больше не нужен для показа, удаляется из сборки страницы.
   - PHP-расчёт и тест сверки `tests/js/stl-parity.mjs` сохраняются, если от них зависит серверная оценка.
     Иначе удаляется только JS-часть показа.
5. **Кнопка «Pateikti užsakymą»** остаётся.
   - После отправки страница показывает главным текстом LT `Jūsų užklausa gauta. Susisieksime su jumis artimiausiu
     metu.`, EN `Your request has been received. We will contact you shortly.`
   - Ниже остаются номер заказа и ссылка для гостя или переход в кабинет: без них покупатель не найдёт заказ.
   - Сумм на странице нет.
6. **Кабинет и гостевая страница заказа.**
   - До подтверждения цены — без «Preliminari suma» и без сумм позиций, вместо них заметка из п. 3.
   - В списке заказов сумма — только у подтверждённых.
   - После подтверждения всё как сейчас.
7. Прочие обещания цены в интерфейсе калькулятора заменяются нейтральными: заметка масштаба «…ir kaina» и т. п.
   Найти поиском `kain`, `Sąmata`, `preliminar` в `lang/*/calculator.php`, `lang/*/order*.php` и шаблонах калькулятора.

## H-05. Фрезеровка и гравировка: материалы, ввод толщины и глубины (п. 11)

1. **Материалы фрезеровки** (`milling_materials`):
   - LT `Spalvotieji metalai`, `Mediena`, `Plastikas`, `Reikia konsultacijos`;
   - EN `Non-ferrous metals`, `Wood`, `Plastic`, `Consultation needed`.
   - Меняются миграцией настроек (только если список прежний) и в миграции-источнике для новых БД.
   - Материалы гравировки заказчик не указал. Они не меняются.
2. **Толщина фрезеровки и глубина гравировки вводятся числом** (мм), вместо выпадающих списков.
   - Толщина: от 0,1 до 200 мм, шаг 0,1. Глубина: от 0,01 до 20 мм, шаг 0,01.
   - Пределы — допущение плана; заказчик их не назвал. Записываются как вопрос к заказчику.
   - При материале «Reikia konsultacijos» толщина необязательна. В остальных случаях обязательна.
   - Сервер: `numeric|gt:0|max:…` вместо `Rule::in(...)`, сообщения LT/EN. Хранение — JSON `parameters`, как сейчас.
   - Браузер: `<input type="number">` в карточке файла и в свёрнутой строке.
   - Списки `milling_thicknesses_mm`, `engraving_depths_mm` убираются из настроек, формы админки, валидации и
     подписей. Миграция удаляет их из записи настроек; `down` возвращает.
   - Показ в админке, письме и счёте (`StlPart::label`) выводит число как ввели, с запятой в LT.

## H-06. Нумерация счетов по серии заказчика (п. 13)

- **Номер:** префикс серии из настроек (по умолчанию `AIQ`) + последняя цифра года даты выписки + счётчик минимум из
  4 цифр. Примеры: `AIQ60008`; после 9999 — `AIQ610000`, без обрезки.
- **Счётчик свой на каждый год.** В новом году он начинается с 1: `AIQ70001`.
- **В админке «Nustatymai» — «Kitas sąskaitos numeris (šiems metams)».**
  - Задаёт следующий номер текущего года; при выпуске туда вписывают число, которое назовёт заказчик.
  - Нельзя задать номер, который уже выдан. Ошибка показывается по-литовски.
  - Поле «pirmas numeris» серии `AIQW` заменяется этим полем.
- **Старые счета печатаются как были**: `AIQ-1`, `AIQ-2`, а также `AIQW00001`, если такие появятся. Номер нового
  счёта сохраняется при выписке и не пересчитывается.
- **Тесты:** смена года (31.12 → 01.01), задание следующего номера, запрет повтора, старые форматы, PDF с новым номером.

## H-07. Товар: режим продажи, цена, комплектация, документы (п. 7)

Сейчас все товары — только «Gauti pasiūlymą». Корзина всегда пустая. В редакторе нет цены, комплектации и
документов.

1. **Редактор товара** (админка, `ContentTypes::FIELDS['product']`):
   - «Pardavimo būdas»: `Gauti pasiūlymą` (по умолчанию; так остаются все существующие товары) или `Pirkti`;
   - «Kaina, EUR su PVM» — обязательна при `Pirkti`;
   - «Komplektacija ir palaikymas» — текст LT/EN, необязательный;
   - «Dokumentai» — файлы PDF (несколько, до 20 МБ каждый), показываются по имени файла.
2. **Страница товара.**
   - `Gauti pasiūlymą` — как сейчас.
   - `Pirkti` — цена, поле количества и кнопка LT `Į krepšelį`, EN `Add to cart`. Блока «Individualus pasiūlymas»
     нет.
   - «Komplektacija ir palaikymas» — текст товара, а если он пуст — прежняя общая фраза.
   - «Dokumentai» — список ссылок на PDF. Без документов блок не выводится, «Informacija ruošiama» нет.
   - Список товаров показывает цену у товаров `Pirkti`.

## H-08. Корзина и оформление покупки (п. 7)

1. **Корзина.**
   - В сессии, работает и для гостя.
   - Значок в шапке показывает число товаров.
   - Страница корзины: товары с картинкой, ценой, изменением количества, удалением, итогом. Пустая корзина — как
     сейчас.
   - Товар, переведённый администратором в `Gauti pasiūlymą` или снятый с публикации, при оформлении убирается с
     сообщением.
2. **Оформление.**
   - Форма, как на шаге «Užklausos pateikimas» калькулятора: те же поля и проверки, телефон обязателен.
   - Доставка: «Pristatymas visoje ES» / «Atsiėmimas».
   - Стоимость доставки — одна настройка «Pristatymo kaina (prekėms)» в настройках, по умолчанию 0 €. Значение —
     вопрос к заказчику.
3. **Заказ.**
   - Создаётся заказ того же журнала заказов с номером из общей серии, с пометкой «Prekės».
   - Цены уже подтверждены: стадия «цена подтверждена», PaymentRequest на всю сумму.
   - Покупатель сразу переходит на существующую страницу оплаты Stripe.
   - После оплаты (webhook) выписывается счёт по H-06, как у производственных заказов.
   - Администратору — то же письмо о новом заказе, с позициями-товарами.
   - В админке и кабинете позиции-товары показываются с названием, количеством и ценой.
   - Остатки на складе не ведутся.
4. **Тесты.** Корзина гостя, оформление, заказ и PaymentRequest, оплата через подменённый Stripe (без сети), счёт
   после webhook, товар `Gauti pasiūlymą` нельзя положить в корзину (в том числе POST напрямую).

## H-11. Белая шапка над белыми страницами (просьба Игоря, 05.11)

Добавлено 05.11 после запуска прогона, по устной просьбе Игоря через пользователя. В PDF заказчика этого пункта нет.
В очереди идёт сразу после H-01: обе задачи меняют шапку.

**Сейчас.** По правке 28.10 (П2) шапка вверху тёмная на всех страницах и белеет после прокрутки.
- Сервер рисует `header--dark`, `resources/js/header.js` снимает класс после прокрутки.
- Над светлыми страницами получается тёмная полоса над белым содержимым.

**Должно стать.** Шапка тёмная вверху **только там, где под ней тёмный верх страницы**. Сейчас это одна главная:
`header--overlay` поверх видео hero.
- Главная — как сейчас: вверху тёмная полупрозрачная, после прокрутки белая.
- Все остальные страницы — сразу белая шапка прототипа §7.1, и вверху, и после прокрутки, без перехода:
  внутренние страницы со светлым `page-top`, услуги, товары, калькулятор, кабинет, админка, вход, 404 и т. д.
- Второй ряд шапки на внутренних страницах (надпись и ссылка-CTA) тоже в белом виде.
- Логотип над белой шапкой — обычный (тёмный), без белого варианта.
- Сервер не рисует `header--dark` на нетёмных страницах. `header.js` не включает тёмное состояние там, где его не было
  при загрузке: по классу или признаку, который даёт сервер. Без JS — то же самое (noscript-стиль).
- Признак «тёмный верх» задаётся тем же параметром layout, что `headerOverlay` главной. Другой страницы с тёмным
  верхом сейчас нет; новых настроек не вводится.
- Высоты рядов, логотип и отступы из H-01 не меняются, меняются только цвета.

**Проверки.**
- ShellTest: классы шапки на главной и на внутренних страницах, в кабинете и в админке.
- `bin/browser-check`: `check_styles` и проверки шторки (`header--dark` на прокрутке 0) — тёмная ожидается только на
  `/lt`, `/en`. На остальных страницах — белая на прокрутке 0 и после прокрутки, анимаций нет.
- Кадры 390/1440 вверху: главная, услуга, калькулятор.

## H-09. Сквозная проверка

- Полный `bin/check` и полный `bin/browser-check` на одном дереве.
- Просмотр кадров:
  - главная 390/1440: шапка вверху и после прокрутки, hero без ряда этапов и без кнопки паузы, разделы без номеров,
    «Darbo eiga», услуги без номеров на фото, подвал;
  - страница услуги, «Apie mus», «Kontaktai»;
  - калькулятор, шаги 1–3 и страница после отправки;
  - товар `Pirkti` и `Gauti pasiūlymą`, корзина, оформление;
  - PDF счёта с номером `AIQ6xxxx` → PNG.
- Раздел «Правки заказчика 03.11 (рев. 4)» в `docs/requirements.md`: что сделано (H-01…H-08, H-11) со статусом «сделано в
  коде и проверено локально; на aiqme.com — после выпуска», толкования и открытые вопросы к заказчику.
- Итог в `progress.md` и шаги пользователя для H-10.

## H-10. Выпуск (с человеком)

Одним выпуском с G-10 правок 02.11 ([README](../edits-20261102/README.md), G-10). Порядок: копия БД, `deploy <rev>`,
`migrate`. Затем:
- в «Nustatymai» для счёта: префикс `AIQ`; следующий номер текущего года — тот, что назовёт заказчик в день выпуска.
  Заказчик писал 03.11, что сейчас идёт `AIQ60008`. Остальные реквизиты — по G-10, без серии `AIQW`;
- проверить, что в `ui_texts` нет переопределений ключей, изменённых в H-01/H-03/H-04;
- пробный счёт на тестовом заказе. Номер пробного счёта расходует номер серии, поэтому пробу делать до того, как
  задан номер, или согласовать с заказчиком;
- ручной просмотр на ПК и телефоне по списку H-09;
- товары остаются `Gauti pasiūlymą`, пока заказчик не задаст цены и режим `Pirkti`.

## Вопросы к заказчику (после прогона)

1. Пределы ввода толщины фрезеровки и глубины гравировки (план: 0,1–200 мм и 0,01–20 мм).
2. Материалы гравировки — оставить прежний список?
3. Стоимость доставки для покупки товаров (план: одна сумма, по умолчанию 0 €).
4. Номер счёта, с которого продолжить в день выпуска.
5. Подтвердить толкование «Apie mus»: остаются два абзаца (первый прежний и новый текст).

## Правила для ночного прогона

Те же, что у очереди 02.11 (`rules` в tasks.json). Отличия:
- меняется только `aiqme-site/` и, в H-09, новый раздел `docs/requirements.md`;
- новых бинарных файлов нет, кроме маленьких синтетических PDF-фикстур документов товара в `aiqme-site/tests/fixtures/`;
- новых зависимостей нет. Корзина — на сессии или на имеющемся Lunar-корзине, без пакетов.

## Подготовка прогона (человек или дневная сессия)

1. Убрать из корня три пустых мусорных файла от 05.11 (`[text`, `, hero_video_source => …`, `Bosch"]:…`).
   Решить судьбу неотслеживаемого `docs/customer-edits-20261102-release-handoff.md`: закоммитить или убрать.
   Оркестратор считает неотслеживаемые файлы изменениями.
2. `main` зелёный: `aiqme-site/bin/check` и `aiqme-site/bin/browser-check`. Единственная известная красная проверка —
   подпись Bosch на /lt и /en (c1d5d20); её исправляет H-01, остальное должно быть зелёным.
3. Закоммитить `docs/planning/edits-20261105/`, проверить `git ls-files` — нет файлов из `docs/customer/`.
4. `python3 docs/planning/edition-2/run/night.py --tasks docs/planning/edits-20261105/tasks.json --dry-run`:
   H-01, H-11, H-02…H-09 «к выполнению», H-10 «стоп (P)».
5. Запуск: `AIQME_TASKS=docs/planning/edits-20261105/tasks.json AIQME_TASK_MINUTES=180 bash docs/planning/edition-2/run/night-run.sh`.
