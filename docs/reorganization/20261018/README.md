# Реорганизация рабочего пространства — 18 октября 2026

Корень разложен на `docs/`, `legacy-landing/` и `aiqme-site/`. Сайт не разрабатывался, дизайн не менялся. Publish, push, `git init` и commit не выполнялись. [Текст задания](request.md), [точка входа](../../../README.md), [предыдущая реорганизация 15.10](../README.md) (её метод повторён: копия → манифест → проверки до → перенос → проверки после → сверка байтов).

## Дополнение: публичный общий репозиторий

Уточнение пользователя после отчёта. Весь корень публикуется в **тот же публичный** репозиторий `GreatGleb/3d-fabrication-web`, где был прежний лендинг. Pages раздаёт сайт из папки сборки: **https://greatgleb.github.io/3d-fabrication-web/legacy-landing/github-pages/**. Требования, план редакции 2 и опросник публикуются. Поэтому разделы ниже о «приватном» репозитории устарели. Изменения:

- `.gitignore` исключает приватное: `docs/customer/`, `docs/customer-prototype/{audit-20261016,evidence,source-backup-20261016}/`, `docs/planning/run/` (цикл цитирует материалы заказчика), полные манифесты реорганизации, `legacy-landing/instance/`, снимки-архивы, `.env`. Чужой контент тоже исключён: копия сайта Relats (`references/relats/cache/`) и видео Behance. Готовая сборка `legacy-landing/github-pages/` теперь **не** исключена: её раздаёт Pages. Проверка через временный внешний git-каталог: около 930 файлов, примерно 105 МБ, приватных путей среди них нет.
- В корень добавлены `.nojekyll` (без него Jekyll обработал бы весь репозиторий), `index.html` (переадресация со старого адреса) и `404.html` (старые глубокие ссылки `/3d-fabrication-web/<путь>` ведут в папку сборки).
- Сборка из чистого клона (только отслеживаемые файлы) проходит. Результат совпадает с текущей публикацией. STL- и полная UI-проверка проходят на подпути `/3d-fabrication-web/legacy-landing/github-pages/`.
- README, AGENTS, README лендинга и `legacy-landing/github-pages/README.md` (кнопка «Открыть сайт») указывают новый адрес. Переход на общий репозиторий выполнен позже коммитом `2fb9487`: `.git` сборки перенесён в корень, у `legacy-landing/github-pages/` своего `.git` больше нет ([github-pages-v2.md](../../legacy-landing/github-pages-v2.md#переход-на-общий-репозиторий-выполнен)).
- План: D-14 (публичный репозиторий корня), D-16 (демо в `aiqme-site/demo/` того же репозитория, https://greatgleb.github.io/3d-fabrication-web/aiqme-site/demo/), §8.3, §8.7, W-01, W-24, `changes.md` (источник ЗАД-3).
- Ссылки из публичных документов на исключённые каталоги (аудит, переписка, `run/`) у внешних читателей будут битыми, локально они работают.

## Итог

- Все 37 526 файлов, снятых в манифест до переноса, найдены по новым путям. **37 444 побайтно идентичны** (sha256). 77 изменены намеренно: пути в документах, скриптах и runtime-конфигах, а также кэши. Подробности ниже. Один удалён намеренно: осиротевший `tests/__pycache__/*.pyc`, он есть в резервной копии.
- `instance/` перенесён переименованием, содержимое не читалось. Сверка по метаданным: те же inode, размер и mtime у всех 3 файлов. Файлы `baseline/`, переписка `docs/customer/client/` и `.git` публикации не изменены. Исключение — stat-кэш `.git/index`, его обновляет любой `git status`.
- Сборки и проверки лендинга **до и после совпадают**: 9 PASS и те же 3 прежних провала. Обязательные три команды проходят и из корня, и из `legacy-landing/`.
- Эксперименты из нового места: `compare.py` — 38 тестов / 144 assertions в обоих вариантах, как и до переноса.
- Ссылки: 1021 относительная ссылка в 37 документах переписана автоматически. Итог — **0 битых ссылок и якорей** в поддерживаемой документации и во входах `tasks.json`. 341 разрыв в замороженных снимках-копиях был и до переноса, он не изменился (см. «Что не удалось»).
- Архив исходников прототипа распакован без запуска кода. `dist/app.js`, `data.js`, `style.css` **совпадают по sha256** с ресурсами аудита.
- План: `platform/` → `aiqme-site/`. Добавлены требование R-041 (демо-сборка для GitHub Pages), задача W-24, решение D-16; `tasks.md` перегенерирован.

## Новая структура

```text
README.md, AGENTS.md, .gitignore
aiqme-site/                      новый сайт (пока только README)
  experiments/                   бывший prototypes/: lunar-aiqme, laravel-orders-aiqme, orders-comparison (соседство сохранено)
docs/
  requirements.md
  planning/                      edition-2/, run/ (история), questions-*.md
    workflow/                    plan_workflow.py, test_plan_workflow.py, layers.json, README.md, render_tasks.py (новый)
  customer/                      бывший materials/: client/ (переписка), initial-requirements.txt, reorganization-request.txt, Excel
  customer-prototype/            README (новый), audit-20261016/, evidence/, source-backup-20261016/, prototype-browser.md, tools/
  research/                      lunar-*.md, references/ (behance-videos, competitors.txt, README)
  design/                        без изменений места
  legacy-landing/                github-pages-v2.md, local-server.md
  reorganization/                отчёт 15.10 (README.md и др.) и этот каталог 20261018/
legacy-landing/                  README (новый), site, server, instance, design-previews, build, scripts, tools/stl,
                                 github-pages (с .git), archive, references/relats, requirements-*.txt
```

## Карта «было → стало»

| Было | Стало |
|---|---|
| `site/`, `server/`, `instance/`, `design-previews/`, `build/`, `github-pages/`, `archive/` | `legacy-landing/<то же имя>/` |
| `requirements-dev.txt`, `requirements-server.txt` | `legacy-landing/` |
| `scripts/` (кроме трёх файлов ниже) | `legacy-landing/scripts/` |
| `scripts/plan_workflow.py`, `tests/test_plan_workflow.py` | `docs/planning/workflow/` |
| `tools/planning/README.md`, `tools/planning/layers.json` | `docs/planning/workflow/` |
| `scripts/capture_prototype_chrome.py`, `scripts/read_prototype_chrome.py` | `docs/customer-prototype/tools/` |
| `tools/stl/` | `legacy-landing/tools/stl/` |
| `references/relats/` | `legacy-landing/references/relats/` |
| `references/aiqme-prototype/` | `docs/customer-prototype/evidence/` |
| `references/behance-videos/`, `competitors.txt`, `README.md` | `docs/research/references/` |
| `materials/` | `docs/customer/` |
| `prototypes/{lunar-aiqme,laravel-orders-aiqme,orders-comparison}` | `aiqme-site/experiments/…` |
| `docs/prototype-audit-20261016/` | `docs/customer-prototype/audit-20261016/` |
| `docs/prototype-browser.md` | `docs/customer-prototype/prototype-browser.md` |
| `docs/lunar-evaluation-20261017.md`, `docs/lunar-vs-laravel-comparison-20261017.md` | `docs/research/` |
| `docs/github-pages-v2.md`, `docs/local-server.md` | `docs/legacy-landing/` |
| `/home/greatgleb/Downloads/AIQME-site-backup-2026-10-16.zip` (не перемещён) | распакован в `docs/customer-prototype/source-backup-20261016/` |

Машинный перечень — [moves.json](moves.json) (32 перемещения, выполнены `os.rename` в одной ФС). Пустые `tests/`, `tools/`, `references/`, `prototypes/` удалены.

### Решения по файлам, не вписанным явно

- **Инструменты прототипа** (`capture_/read_prototype_chrome.py`) относятся к прототипу заказчика, а не к лендингу. Поэтому они лежат в `docs/customer-prototype/tools/`, рядом со снимками, которые создают. `read_prototype_chrome.py` (AT-SPI) помечен «не использовать».
- **Инструмент планирования** перенесён к документации планирования (`docs/planning/workflow/`), а не в legacy. Причины: он относится к плану нового сайта, а лендинг заморожен. Поэтому `tests/` в `legacy-landing/` не создавался: другого содержимого, кроме этого теста и кэша, там не было.
- **`docs/design/` остался на месте.** Это дизайн-исследования по условию задачи, хотя часть результатов проверок туда пишут скрипты лендинга: они теперь используют `ROOT.parent/'docs/design/…'`.
- **Документы лендинга** (`github-pages-v2.md`, `local-server.md`) перенесены в `docs/legacy-landing/`: вся документация — в `docs/`. Рядом с кодом лежит короткий [legacy-landing/README.md](../../../legacy-landing/README.md).
- **Excel-макет** `design_inside_excel.xlsx` относится к материалам заказчика (`docs/customer/`). Скрипт извлечения ссылается на него через `ROOT.parent`.
- **Lunar-отчёты** перенесены в `docs/research/`. Референсы Behance и список конкурентов — в `docs/research/references/`.
- **Новый генератор** `docs/planning/workflow/render_tasks.py`. Прежнего генератора `tasks.md` в рабочем пространстве не было. Новый сначала воспроизвёл существующий `tasks.md` побайтно (кроме добавленной ссылки на себя в шапке), затем перегенерировал файл с W-24.

## Резервная копия и манифесты

- Полная копия **до любых сборок и перемещений**: `legacy-landing/archive/reorganization-20261018/workspace-before.tar.zst`, 710 МБ, 37 526 файлов. В неё вошли `.git` публикации, `instance/`, `vendor/` и `node_modules/`, а также предыдущий снимок. SHA-256 — [backup.sha256](backup.sha256). Содержимое архива потоково сверено с манифестом, расхождений нет: [backup-verification.json](backup-verification.json). Архив содержит приватные данные, он исключён `.gitignore`.
- Манифесты до и после — [manifest-before.json.gz](manifest-before.json.gz), [manifest-after.json.gz](manifest-after.json.gz). Для файлов — sha256, размер и inode; для `instance/` — только stat, имена этих файлов в сохранённых манифестах заменены хешем.
- Между первым манифестом и моментом переноса сборки «до» изменили только `github-pages/.git/index` (stat-кэш). Прогон тестов экспериментов изменил два `.phpunit.result.cache`. Сборки детерминированы.
- Сверка после переноса — [preservation.json](preservation.json). Все 77 изменённых файлов перечислены там с видом изменения:

| Вид | Файлов |
|---|---|
| Ссылки и пути в документах (`.md`, `tasks.json`, `layers.json`) | 49 |
| Пути в скриптах (`.py`, `setup.sh`) | 14 |
| Абсолютные пути в `.codex/config.toml` и `runtime/conf/*.ini` экспериментов | 10 |
| `.gitignore` | 1 |
| Кэши: `.phpunit.result.cache` (2), `__pycache__` (1), `.git/index` (1) | 4 |

Добавлено: распакованный архив (180), новые README (3: `aiqme-site/`, `legacy-landing/`, `docs/customer-prototype/`), `render_tasks.py`, кэши Python и 18 скомпилированных Blade-представлений из прогона тестов после переноса. 248 файлов `legacy-landing/build/` побайтно совпадают, но получили новые inode: сборщик их пересоздаёт.

## Правки путей в коде

| Файл | Что изменено |
|---|---|
| `legacy-landing/scripts/check_pages.py` | `docs/design/pages-check.json` → `ROOT.parent/…` |
| `legacy-landing/scripts/check_gear_v2.py`, `check_motion_effects.py`, `check_motion_preview.py` | результаты в `docs/design/…` → `ROOT.parent/…` |
| `legacy-landing/scripts/extract_excel_assets.py` | книга → `docs/customer/design_inside_excel.xlsx` |
| `legacy-landing/archive/retired-checks/check_project_v2.py` | `parents[2]` → `parents[3]` |
| `docs/design/relats-study/{test_archive_ranges,inspect_*}.py` | `references/relats` → `legacy-landing/references/relats` |
| `docs/customer-prototype/tools/capture_prototype_chrome.py` | корень `parents[3]`, вывод по умолчанию → `docs/customer-prototype/evidence/<дата>` |
| `docs/planning/workflow/plan_workflow.py` | корень `parents[3]`, `layers.json` рядом со скриптом, новые пути источников и снимка, тексты заданий |
| `docs/planning/workflow/test_plan_workflow.py` | скрипт ищется рядом с тестом |
| `aiqme-site/experiments/orders-comparison/bin/setup.sh` | работает от каталога `experiments/` вместо `../../prototypes/` |
| `aiqme-site/experiments/*/app/.codex/config.toml`, `lunar-aiqme/runtime/conf/*.ini` | абсолютные пути → `…/aiqme-site/experiments/…` |

Остальные скрипты лендинга вычисляют корень как `parents[1]`. Это теперь `legacy-landing/`, где по-прежнему лежат `site/`, `build/`, `github-pages/` и др., поэтому правка им не нужна. `compare.py`, `metrics.py`, `browser_check.py` и `bin/php` используют пути относительно соседних каталогов.

Одна зависимость сначала была пропущена. Прогон «после» записал `pages-check.json` в новый лишний каталог `legacy-landing/docs/design/`. Скрипт исправлен, каталог удалён (содержимое совпадало с `docs/design/pages-check.json`), повторный прогон прошёл: [лог](after/check_pages-rerun.log). Итоговый `docs/design/pages-check.json` побайтно совпадает с исходным.

## Проверки до и после

Git публикации перед сборкой: ветка `main`, `+0 -0` к `origin/main`, рабочее дерево чистое, незакоммиченного нет. HEAD до и после — `e2e2616`, дерево чистое ([до](github-pages-status-before.txt), [после](github-pages-status-after.txt)).

| Проверка | До | После |
|---|---|---|
| `build_pages.py` (server и demo) | PASS | PASS |
| `build_motion_preview.py`, `build_motion_v2.py` | PASS | PASS |
| `build_github_pages_v2.py` | PASS | PASS |
| `STL_TEST_DIRECTORY=github-pages … check_stl_pricing.py` | PASS | PASS |
| `check_github_pages_ui.py` | PASS | PASS |
| `check_pages.py`, `check_demo.py` | PASS | PASS |
| `check_portal.py` в системном окружении | нет `werkzeug` | та же ошибка |
| `check_motion_preview.py`, `check_motion_v2.py` | `AssertionError: site/index.html` | та же ошибка |
| Три обязательные команды из каталога `legacy-landing/` | — | PASS ([лог](after/required-from-legacy-landing-cwd.log)) |
| `check_portal.py` с `requirements-server.txt` во временном каталоге scratch | — | PASS ([лог](after/check_portal-with-deps.log)) |
| `test_plan_workflow.py` (13 тестов) | OK | OK |
| `docs/design/relats-study/test_archive_ranges.py` (5 тестов) | OK | OK |
| Эксперименты: контракт 38 тестов / 144 assertions × 2 варианта | PASS | PASS |
| `render_tasks.py --check` | — | актуален |

Логи: [before/](before/results.json), [after/](after/results.json). Провалы motion- и portal-проверок совпадают с [отчётом 15.10](../README.md#проверки-до-и-после) и не исправлялись. Команды «до» запускались из корня в старой раскладке, «после» — из корня с префиксом `legacy-landing/`. Скрипты от текущего каталога не зависят.

Эксперименты. «До» запускалась копия `compare.py` с выводом в [before/experiments](before/experiments/comparison-results.json), чтобы не перезаписать `evidence/`. «После» запущен настоящий `aiqme-site/experiments/orders-comparison/bin/compare.py`. Его пять выходных файлов в `evidence/` были заранее сохранены и после прогона восстановлены побайтно. Результаты нового прогона — в [after/experiments](after/experiments/comparison-results.json). Для тестов запускался существующий контейнер `aiqme-lunar-probe-postgres`. До работы он был остановлен; после прогона снова остановлен.

## Архив исходников прототипа

Распаковка: новая пустая папка, Python `-I`, `zipfile`. Каждый путь проверен: внутри папки, не симлинк, без исполняемых битов. JS архива не запускался. 180 записей, SHA-256 архива `bf0184b0…2c33c`. `app.js`, `data.js`, `style.css` совпадают с `evidence/audit-20261016/resources/{3b7cc88c…,a4317b5d…,3b84c155…}`: [source-backup-verification.json](source-backup-verification.json), [описание](../../customer-prototype/README.md#архив-исходников-16102026).

## Ссылки и документы

- Переписыватель ([tools/rewrite_links.py](tools/rewrite_links.py)) разрешал каждую ссылку относительно **старого** места файла. Ссылку, которая вела к существующему пути, он переводил через карту перемещений и пересчитывал относительно нового места. Неразрешённых ссылок не было; повторный запуск изменений не даёт. Перечень — [link-rewrites.json](link-rewrites.json).
- Входы `tasks.json` переведены на новые пути. Пути будущего приложения (`aiqme-site/…`) не проверяются на существование: их создают сами задачи.
- Проверка ([tools/check_links.py](tools/check_links.py)): относительные ссылки, изображения, `<a href>`, сноски, якоря по заголовкам и `id`, а также входы `tasks.json` с якорями.

| Состояние | Документов | Проверено ссылок | Битых в поддерживаемых | В замороженных снимках |
|---|---|---|---|---|
| До ([links-before.json](links-before.json), распакованная копия) | 156 | 1952 | 0 | 341 |
| После ([links-after.json](links-after.json)) | 161 | 2067 | 0 | 341 (тот же набор) |

- Исторические отчёты (аудит, Lunar, `docs/reorganization/README.md`, `docs/planning/run/`, опросник) изменены только в путях ссылок. В документах, где даны команды (`docs/legacy-landing/*`, README V1/V2, `archive/`, Relats), добавлена пометка «команды выполняются из `legacy-landing/`»; так прежние `python3 scripts/…` остаются верными. Точечно исправлены README экспериментов, `prototype-browser.md`, README workflow, README исследования Relats и материалов заказчика.
- Корневые `AGENTS.md` и `README.md` переписаны под новую структуру (с разрешения пользователя). README содержит адрес лендинга https://greatgleb.github.io/3d-fabrication-web/ и место для будущей демо-сборки.

## Изменения плана (редакция 2)

- `platform/` → `aiqme-site/` в `tasks.json`, `tasks.md`, разделах 02, 08, 09, `decisions.md`, `changes.md`, `traceability.md`. Правило задач теперь запрещает менять `legacy-landing/`, `aiqme-site/experiments/`, baseline и `docs/customer/client`.
- W-01 противоречил новой цели (корень пойдёт на GitHub целиком), поэтому критерий «git init только в platform/» заменён: отдельного Git в `aiqme-site/` нет, корень готовится к одному приватному репозиторию. D-14 уточнён так же. Отражено в `changes.md` (источник ЗАД-2).
- Новое требование **R-041**: демо-сборка для GitHub Pages ([§1.6](../../planning/edition-2/01-scope.md#16-демо-сборка-для-github-pages), [requirements](../../requirements.md#демо-сборка-нового-сайта-для-github-pages--добавлено-18-октября)). Механизм предложен как рекомендация в [§8.7](../../planning/edition-2/08-operations.md#87-демо-сборка-для-github-pages-рекомендация): Artisan-экспорт тех же Blade-шаблонов на демо-данных, постобработка (базовый путь, баннер «ДЕМО», `noindex`, перехват форм без сети), калькулятор — тот же модуль W-10.
- Задача **W-24** стоит после W-15. Зависимости W-06, W-10, W-14, W-15: к этому моменту готовы все публичные экраны, калькулятор, кабинет и админка. Срез S2. Следующие задачи от неё не зависят, поэтому при нехватке времени её можно отметить заблокированной без остановки оплаты и интеграций.
- Решение **D-16**: адрес публикации демо. По умолчанию — отдельный публичный репозиторий только со сборкой: корневой репозиторий приватный, а Pages из приватного репозитория требует платного тарифа GitHub. Сборку решение не блокирует.

## .gitignore и будущий репозиторий

Подготовлен [корневой .gitignore](../../../.gitignore). Исключены: `legacy-landing/instance/`, `docs/planning/run/sessions/`, `.env*` (кроме `.env.example`), `node_modules/`, composer `vendor/` (только в `aiqme-site/`), runtime и кэши экспериментов (`runtime/`, `storage/…`, `bootstrap/cache`, `.codex/`), весь `legacy-landing/github-pages/` (вложенный репозиторий: если оставить каталог с `.git`, Git добавил бы его как встроенный репозиторий), `legacy-landing/build/`, снимки `legacy-landing/archive/reorganization-*/`, кэши Python и PHPUnit. `legacy-landing/site/assets/vendor/` (исходный STLLoader) намеренно **не** исключён.

Проверка через временный внешний git-каталог в scratch (в корне `.git` не создавался): отслеживалось бы около 3200 файлов, около 420 МБ. Файлов больше 100 МБ нет; крупнейший — 22 МБ из кэша Relats.

**Репозиторий корня должен быть приватным:** в `docs/customer/` переписка и материалы заказчика, в `docs/customer-prototype/` снимки и исходники его прототипа. `git init`, commit и push не выполнялись.

## Что не удалось или не проверено

- **`setup.sh` экспериментов не перезапускался.** Он ставит пакеты composer, мигрирует и сидирует БД, пересоздаёт `.codex`. Пути исправлены, синтаксис проверен `bash -n`. Изолированный PHP из нового места загружает все 8 расширений, а `compare.py` проходит, поэтому повторный setup для тестов не нужен. На новой машине или после удаления runtime его нужно запустить заново.
- **Не запускались** `browser_check.py` (нужны два HTTP-сервера и Playwright, перезаписывает `evidence/`), `metrics.py` и `check_boost.py` (тоже пишут в `evidence/`). Пути в них относительные или берутся из исправленного `.codex/config.toml`.
- **Замороженные снимки-копии** (`docs/planning/run/reconciliations/**`, `legacy-landing/archive/project-history/`) содержат 341 разрыв. Ссылки в них написаны относительно исходного места файлов, разрывы существовали до переноса, их набор не изменился. Копии не правились, чтобы не нарушить их sha256-манифесты. В проверке они учтены отдельно.
- **Исторические JSON-записи с путями не переписывались**, это свидетельства своего времени: `docs/reorganization/{moves,preservation,links}.json` (15.10), `docs/planning/run/state.json` (абсолютные пути в `source_hashes`, поэтому `run` этого цикла заблокирован, как и раньше через `superseded_by`; `status` работает), `baseline/manifest.json`, `experiments/orders-comparison/snapshots/lunar-before.json`, логи в `evidence/`.
- **Генераторы аудита** в `docs/customer-prototype/evidence/audit-20261016/tools/` сохранены как доказательство и рассчитаны на старую раскладку. Перед повторным запуском их пути нужно адаптировать.
- **Устаревшие скомпилированные Blade-представления** со старыми абсолютными путями остались в `storage/framework/views` экспериментов. Это кэш, Laravel компилирует новые по новому пути; каталог в `.gitignore`.
- **Побочный запуск.** `extract_excel_assets.py` не поддерживает `--help`, и вызов с этим флагом выполнил извлечение в `legacy-landing/site/assets/images/`. Все 14 файлов побайтно совпали с исходными, изменилось только mtime. Заодно это подтвердило, что скрипт работает с нового места.
- Сравнение входов `tasks.json` «до» не равноценно «после»: в старой раскладке проверялись только входы под `docs/`.
- Открытые вкладки IDE со старыми путями нужно открыть заново.

## Инструменты отчёта

[tools/](tools/): `manifest.py` (манифест sha256/stat), `run_checks.py` (сборки и проверки с временными серверами 8822/8824), `rewrite_links.py`, `check_links.py`, `compare_copy.py` (копия `compare.py` с выводом вне `evidence/`). Запускать из корня, например `python3 docs/reorganization/20261018/tools/check_links.py .`.
