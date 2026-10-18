# Реорганизация рабочего пространства — 15 октября 2026

Задача выполнена локально без изменения функций и дизайна, без push, публикации или настройки внешних сервисов. [Точка входа](../../README.md), [актуальные требования](../requirements.md).

## Классификация и новая структура

```text
site/                       основные исходники, калькулятор
server/                     существующий локальный Flask
instance/                   сохраняемые база и вложения
scripts/                    действующие сборки и проверки
tools/stl/                  исходники сборки STLLoader
requirements-*.txt          зависимости Python
design-previews/
  animation-v1/             предыдущий вариант для сравнения
  animation-v2/             оформление текущей публикации
    archive/scroll-scenes/  отменённые сцены, не подключать
  */baseline/               неизменяемые снимки
build/
  server/                   основной сайт для Flask
  demo/                     основная статическая демоверсия
  motion-v1/                сравнение original/V1
  motion-v2/                сравнение original/V1/V2
github-pages/               отдельный Git-репозиторий публикации
materials/
  client/                   переписка и снимок заказчика
  design_inside_excel.xlsx  источник используемых иллюстраций
  initial-requirements.txt  ранний перечень разделов
  reorganization-request.txt уточнения текущего объёма
references/
  relats/                   воспроизводимый внешний референс
  behance-videos/            сохранённые видео и манифесты
  competitors.txt           исходный список ссылок
docs/                       требования, инструкции, исследования и отчёты
archive/
  design-concepts/          галерея и четыре ранних варианта
  github-automation/        прежний неиспользуемый workflow
  retired-checks/           тест отменённой сцены проектов
  project-history/         прежние README и AGENTS
  reorganization-20261015/  резервный снимок до первого изменения
```

V2 — действующий дизайн публикации, но не реализация нового прототипа заказчика.
V1 необходима для сравнения и потому оставлена рядом с V2. Excel не признан
мусором: скрипт извлечения использует его, а иллюстрации входят в сайт.
Исследования, изображения и результаты прошлых проверок в `docs/design/`
сохранены рядом с соответствующими документами, чтобы не разрывать галереи.

Неопределённость: происхождение и актуальность записей `instance/` не проверялись;
данные не читались для классификации и не менялись. Будущая применимость Behance
и конкурентов не утверждена. Отдельные файлы `правкия/правки` из списка вкладок IDE
на диске не найдены. Прототип в Chrome не просмотрен; новых фото и видео нет.
Кэши `__pycache__` и `tools/stl/node_modules` — локальные генерируемые зависимости,
игнорируются; не путать их с исходниками `tools/stl/`.

## Карта старый путь → новый путь

| Старый путь | Новый путь |
|---|---|
| `_site/` | `build/server/` |
| `_site_pages/` | `build/demo/` |
| `_site_motion/` | `build/motion-v1/` |
| `_site_motion_v2/` | `build/motion-v2/` |
| `_site_staging/`, `_site_pages_staging/` (создавались сборкой) | `build/server-staging/`, `build/demo-staging/` |
| `примеры/` | `references/` |
| `сайты конкурентов примеры` | `references/competitors.txt` |
| `правки/` | `materials/client/` |
| `design_inside_excel.xlsx` | `materials/design_inside_excel.xlsx` |
| `requirements` (текст требований, не pip) | `materials/initial-requirements.txt` |
| `design-previews/00-design-gallery/` | `archive/design-concepts/00-design-gallery/` |
| `design-previews/01-precision/` | `archive/design-concepts/01-precision/` |
| `design-previews/02-studio/` | `archive/design-concepts/02-studio/` |
| `design-previews/03-process/` | `archive/design-concepts/03-process/` |
| `design-previews/04-balanced/` | `archive/design-concepts/04-balanced/` |
| Общие `design-previews/{assets,app.js,favicon.svg,fonts.css,index.html,site.css,start.sh,README.md}` | Те же имена в `archive/design-concepts/` |
| `.github/` | `archive/github-automation/` |
| `scripts/check_project_v2.py` | `archive/retired-checks/check_project_v2.py` |
| Прежние `README.md`, `AGENTS.md` | Копии `archive/project-history/before-reorganization-{README,AGENTS}.md`; корневые файлы обновлены |

Полный перечень фактических перемещений: [moves.json](moves.json).
Основные исходники, V1/V2, baseline, `github-pages/`, `server/`, `instance/`,
`tools/` и документация исследований остались на прежних местах.

Историческая переписка сохранена без переписывания; [карта рядом с ней](../customer/client/README.md).
Старые README/AGENTS и README отменённых сцен — исторические свидетельства,
их команды сопоставляйте с этой картой. Имена Excel в ASSETS.md обозначают книгу
в `materials/`; тексты о происхождении изображений и baseline не изменялись.

## Обновлённые зависимости

Обновлены сборщики, проверки основного сайта и STL, путь Flask к статическим файлам,
скрипты исследования Relats, документация и HTML-подсказки запуска сравнения.
У галереи сохранена исходная взаимная структура HTML/CSS/JS/шрифтов/изображений.
Старый workflow перенесён вместе с обновлёнными путями, но не активирован.

Сборщик V1 специально продолжает читать временный `temp/_site_pages`:
его создаёт неизменённый скрипт внутри frozen baseline. Внешний результат
теперь пишется в `build/motion-v1/`. Это сохранённая внутренняя зависимость,
а не забытый путь. В Git-пакет V2 страницы сравнения не входят, поэтому
обновление подсказок запуска не меняет опубликованный сайт.

## Проверки до и после

Первый прогон выполнен после резервирования, до перемещений. Логи:
[до](before/results.json), [после](after/results.json).

| Проверка | До | После |
|---|---|---|
| `build_pages.py` (server/demo) | PASS | PASS |
| `build_motion_preview.py`, `build_motion_v2.py` | PASS | PASS |
| `build_github_pages_v2.py` | PASS | PASS |
| `STL_TEST_DIRECTORY=github-pages … check_stl_pricing.py` | PASS | PASS |
| `check_github_pages_ui.py` | PASS | PASS |
| `check_pages.py`, `check_demo.py` | PASS | PASS |
| `check_portal.py` в исходном окружении | Нет `werkzeug` | Та же ошибка |
| `check_motion_preview.py`, `check_motion_v2.py` | Assertion `site/index.html` | Та же ошибка |

Старые motion-проверки сверяют сегодняшний `site/` с историческим манифестом;
README V2 также предупреждает об устаревших ожиданиях эффектов в `check_motion_v2.py`.
Эти проверки не переписывались для получения зелёного результата. Тест отменённой
сцены `check_project_v2.py` вынесен из активной папки, так как ожидает отсутствующую
по решению пользователя `.project-feature`.

Дополнительная верификация:

- После установки `requirements-server.txt` во временную директорию
  `/tmp/aiqme-reorganization-20261015/server-deps` проверка кабинета прошла:
  CSRF, вход/выход, rate limit, изоляция проектов/файлов, заявки, браузерные формы.
  [Лог](extra/portal-with-deps.log). Системное окружение не менялось.
- `check_motion_effects.py`, `check_gear_v2.py`, `check_services_v2.py` — PASS.
  [Результаты](extra/results.json). Временные HTTP-серверы остановлены.
- `docs/design/relats-study/test_archive_ranges.py` — 5 тестов PASS.
  [Лог](extra/relats.log).
- Python-файлы сборки, сервера и исследования успешно скомпилированы.
- Относительные Markdown-ссылки в поддерживаемой документации проверены, разрывов нет.
- 1491 относительная ссылка HTML/CSS проверена: в готовых сборках, перенесённой
  галерее и галереях документации новых разрывов нет. Все 88 отсутствующих
  ссылок относятся к неизменённому исследовательскому `sources/style.css` Relats;
  отсутствие файлов подтверждено в исходном снимке. Это не самостоятельный сайт,
  архив воспроизводится через `references/relats/serve.py`. [Список](links.json).
- Все 101 файл baseline (кроме генерируемых pyc) побайтно совпадают с резервным
  снимком. Исходные файлы не потеряны; `site/`, переписка, локальные данные и
  файлы Git-публикации сохранены. [Контроль сохранности](preservation.json).

## Git, сохранность и восстановление

В корне нет `.git`; единственный найденный репозиторий — `github-pages/.git`.
До работ и после сборок его рабочее дерево чистое, ветка `main`, origin
`git@github.com:GreatGleb/3d-fabrication-web.git`. История, refs и конфигурация
сохранены побайтно. Служебный `.git/index` обновил stat-кэш при проверке статуса;
это не изменение индекса содержимого или истории. Коммитов и push не выполнялось.

Полная резервная копия исходного рабочего пространства **до первых сборок**:
`archive/reorganization-20261015/workspace-before.tar.gz`.
Включает `.git`, `instance/`, исходные результаты сборок и пользовательские
файлы; исключён только восстанавливаемый `tools/stl/node_modules`.
Архив содержит приватные локальные данные: это локальная копия, не ресурс сайта.
Результаты сборок могли устареть ещё до задачи; исходные версии сохранены в архиве.

Для восстановления сначала распакуйте снимок в отдельный пустой каталог,
сверьте изменения и только затем возвращайте нужные файлы. Не распаковывайте
поверх текущей работы вслепую. Кэш зависимостей STL восстанавливается по
`tools/stl/package-lock.json`. Временная старая копия публикации
`/tmp/aiqme-publish-5QS3Qi` не использовалась.

## Что остаётся решить

Финальный прототип и шрифты, новые медиа, правила цен/оплаты/счетов,
ролей Telegram и событий Sheets, обратная синхронизация, резервирование,
выбор архитектуры и будущие доступы перечислены в [требованиях](../requirements.md).
В этой задаче новые функции и дизайн не реализовывались.
