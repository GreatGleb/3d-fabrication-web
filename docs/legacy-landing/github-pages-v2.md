# Сборка и публикация прежнего лендинга (V2)

> Прежний лендинг после реорганизации 18.10.2026 находится в `legacy-landing/`. Команды ниже — из корня рабочего пространства; скрипты не зависят от текущего каталога.

## Адрес

Решение 18.10.2026: всё рабочее пространство публикуется в **тот же публичный репозиторий** `git@github.com:GreatGleb/3d-fabrication-web.git` (ветка `main`), где раньше лежала только сборка. GitHub Pages раздаёт репозиторий с корня ветки, а сайт открывается из папки сборки:

**https://greatgleb.github.io/3d-fabrication-web/legacy-landing/github-pages/**

Старые адреса продолжают работать. Корневой `index.html` переадресует https://greatgleb.github.io/3d-fabrication-web/ на новый адрес. Корневой `404.html` переносит старые глубокие ссылки вида `/3d-fabrication-web/calculator/` в `/3d-fabrication-web/legacy-landing/github-pages/calculator/`. Корневой `.nojekyll` отключает Jekyll для всего репозитория. Ссылки внутри сборки относительные. Работа по такому подпути проверена: STL- и UI-проверки прошли на `http://127.0.0.1:…/3d-fabrication-web/legacy-landing/github-pages/`.

В настройках Pages репозитория: **Source — Deploy from a branch, `main`, папка `/ (root)`**. Так было и для прежней публикации.

## Сборка и проверка

```bash
python3 legacy-landing/scripts/build_github_pages_v2.py
STL_TEST_DIRECTORY=github-pages python3 legacy-landing/scripts/check_stl_pricing.py
python3 legacy-landing/scripts/check_github_pages_ui.py
python3 -m http.server 8830 --bind 127.0.0.1 --directory legacy-landing/github-pages
```

Просмотр: http://127.0.0.1:8830/ . Команды ничего не публикуют. Сборщик заменяет файлы `legacy-landing/github-pages/`, кроме `README.md`. Перед сборкой проверьте `git status` и сохраните незакоммиченные правки. Готовая сборка **хранится в репозитории** (её раздаёт Pages), поэтому после пересборки изменения в `legacy-landing/github-pages/` нужно закоммитить. Сборка из чистого клона (только отслеживаемые файлы) проверена: результат совпадает с текущей публикацией. Не использовать `/tmp/aiqme-publish-5QS3Qi`.

## Переход на общий репозиторий (выполнен)

Раньше `legacy-landing/github-pages/` была отдельным клоном со своим `.git`. Переход выполнен коммитом `2fb9487` (история публикации стала историей корня), своего `.git` у сборки больше нет. Перед каждым commit проверяйте, что приватное не попало в индекс:

```bash
git ls-files | grep -E '^(docs/customer/|docs/customer-prototype/(audit|evidence|source)|docs/planning/run/|legacy-landing/instance/)' # должно быть пусто
```

Всё, что однажды попало в публичный коммит, остаётся в истории даже после удаления. Поэтому проверка `git ls-files` выполняется **до** commit. Список исключений и причины — в корневом `.gitignore`.

## Что внутри

Оформление редактируется в `legacy-landing/design-previews/animation-v2/`; общие исправления пяти страниц — в `shared/`. Актуальный калькулятор берётся из `legacy-landing/site/`. Замороженные `baseline/` не редактировать. `legacy-landing/build/demo/` — другая демосборка. Прежний workflow помещён в `legacy-landing/archive/github-automation/`, V2 он не обслуживает.

Форма в статической публикации готовит заявку и предлагает написать по почте, но не отправляет её серверу. Кабинет читает встроенный JSON, настоящие учётные записи не используются. Удаление надписей «демо» не превращает его в рабочую систему авторизации. STL-калькулятор работает в браузере.

Коммерческая платформа будет размещаться на сервере: [требования](../requirements.md).
