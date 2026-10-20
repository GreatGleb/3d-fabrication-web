# AIQME — рабочее пространство

Точка входа после реорганизации 18 октября 2026. Строим новый сайт AIQME по прототипу заказчика; прежний статический лендинг сохранён и публикуется, но больше не развивается. [Текущий объём и решения](docs/requirements.md) имеют приоритет над старыми планами.

## Структура

| Путь | Назначение и статус |
|---|---|
| [`aiqme-site/`](aiqme-site/README.md) | Новый сайт (Laravel + Lunar). **Пока пуст**, кроме README; `experiments/` — эксперимент сравнения заказов с Lunar и без него. |
| [`docs/`](docs/requirements.md) | Вся документация: требования, план, прототип заказчика, исследования, дизайн, материалы заказчика, отчёты. |
| [`legacy-landing/`](legacy-landing/README.md) | Прежний статический лендинг V2 со всем для сборки: `site/`, `server/`, `instance/`, `design-previews/`, `build/`, `scripts/`, `tools/`, `github-pages/`, `archive/`, `references/relats/`. |

### Документация

| Путь | Что внутри |
|---|---|
| [docs/requirements.md](docs/requirements.md) | Согласованный объём, принятые решения, неизвестные данные и доступы |
| [docs/planning/edition-2/](docs/planning/edition-2/README.md) | Действующий план: [задачи W-01…W-33](docs/planning/edition-2/tasks.md) ([tasks.json](docs/planning/edition-2/tasks.json)), [решения D-01…D-43](docs/planning/edition-2/decisions.md) |
| [docs/planning/](docs/planning/questions-for-owner.md) | [Опросник (72 ответа)](docs/planning/questions-for-owner.md), [карта вопросов](docs/planning/questions-map.md), исторический 30-слойный цикл `run/` и [его инструмент](docs/planning/workflow/README.md) |
| [docs/customer-prototype/](docs/customer-prototype/README.md) | Аудит прототипа, доказательства (снимки, DOM, CSS/JS), распакованный архив исходников 16.10 — **только локально, в публичный репозиторий не входят**; [доступ через Chrome](docs/customer-prototype/prototype-browser.md) |
| [docs/customer/](docs/customer/README.md) | ТЗ и переписка заказчика, исходные требования, Excel-макет. **Только локально.** Переписку не переписывать |
| [docs/research/](docs/research/lunar-vs-laravel-comparison-20261017.md) | [Сравнение Lunar](docs/research/lunar-vs-laravel-comparison-20261017.md), [первая оценка Lunar](docs/research/lunar-evaluation-20261017.md), [референсы](docs/research/references/README.md) |
| [docs/design/](docs/design/relats-study/README.md) | Дизайн-исследования: Relats, планы движения, аудиты V1/V2 |
| [docs/legacy-landing/](docs/legacy-landing/github-pages-v2.md) | [Публикация V2](docs/legacy-landing/github-pages-v2.md), [локальный Flask](docs/legacy-landing/local-server.md) |
| [docs/reorganization/](docs/reorganization/20261018/README.md) | Отчёты реорганизаций [18.10](docs/reorganization/20261018/README.md) и [15.10](docs/reorganization/README.md) |

## Публикации

- **Прежний лендинг:** **https://greatgleb.github.io/3d-fabrication-web/legacy-landing/github-pages/** — сборка лежит в `legacy-landing/github-pages/` того же репозитория `GreatGleb/3d-fabrication-web`, Pages раздаёт её из этой папки. Старый адрес https://greatgleb.github.io/3d-fabrication-web/ и старые глубокие ссылки переадресуются корневыми `index.html` и `404.html`. [Подробнее и порядок перехода](docs/legacy-landing/github-pages-v2.md).
- **Демо нового сайта:** место для будущей демо-сборки для GitHub Pages (только фронтенд, пометка «демо», [задача W-24](docs/planning/edition-2/tasks.md#w-24)). Пока не создана. По умолчанию будет лежать в `aiqme-site/demo/` этого же репозитория: https://greatgleb.github.io/3d-fabrication-web/aiqme-site/demo/ ([D-16](docs/planning/edition-2/decisions.md#d-16)).
- **Рабочий сайт:** серверное развёртывание по плану; сервер не арендован, домен не подключён.

## Прежний лендинг: сборка и проверки

Из корня (скрипты не зависят от текущего каталога). Python 3; для браузерных проверок — `legacy-landing/requirements-dev.txt` и Google Chrome.

```bash
git status --short legacy-landing/github-pages   # сначала сохранить незакоммиченное
python3 legacy-landing/scripts/build_github_pages_v2.py
STL_TEST_DIRECTORY=github-pages python3 legacy-landing/scripts/check_stl_pricing.py
python3 legacy-landing/scripts/check_github_pages_ui.py
python3 -m http.server 8830 --bind 127.0.0.1 --directory legacy-landing/github-pages
```

Готовая V2: http://127.0.0.1:8830/ . Сборка ничего не публикует; на Pages сайт появится после commit и push папки `legacy-landing/github-pages/` (https://greatgleb.github.io/3d-fabrication-web/legacy-landing/github-pages/). Остальные сборки, сравнения V1/V2 и Flask — в [README лендинга](legacy-landing/README.md). `check_motion_preview.py` и `check_motion_v2.py` по-прежнему падают на сверке исторического манифеста `site/index.html`; это известная прежняя проблема, не повреждение.

## Прочее

- Прототип заказчика смотрите через Chrome пользователя по [инструкции](docs/customer-prototype/prototype-browser.md): `python3 docs/customer-prototype/tools/capture_prototype_chrome.py`. **Озвучивание и системные флаги доступности не включать.**
- Эксперименты: `python3 aiqme-site/experiments/orders-comparison/bin/compare.py` (нужен запущенный контейнер PostgreSQL эксперимента; [подробнее](aiqme-site/experiments/orders-comparison/README.md)).
- Тесты инструмента планирования: `python3 -m unittest discover -s docs/planning/workflow -p 'test_plan_workflow.py'`.

## Git

Корень — Git-репозиторий публичного `GreatGleb/3d-fabrication-web` (ветка `main`; весь корень опубликован коммитом `2fb9487`); у `legacy-landing/github-pages/` своего `.git` больше нет. Корневой [.gitignore](.gitignore) исключает всё приватное и чужое: материалы заказчика (`docs/customer/`, аудит, снимки и исходники прототипа), исторический цикл `docs/planning/run/`, `instance/`, снимки-архивы, `.env`, копию сайта Relats и видео Behance, зависимости и runtime. Требования, план редакции 2 и опросник публикуются. Перед каждым commit проверяйте `git ls-files` и `git status`; commit и push — только по указанию пользователя. Переход на общий репозиторий выполнен; [история перехода](docs/legacy-landing/github-pages-v2.md#переход-на-общий-репозиторий-выполнен).
