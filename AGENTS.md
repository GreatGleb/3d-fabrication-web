# AIQME: инструкции для работы

Начните с [README.md](README.md), [актуальных требований](docs/requirements.md),
[плана редакции 2](docs/planning/edition-2/README.md) и
[карты реорганизации 18.10](docs/reorganization/20261018/README.md). Корень не является Git-репозиторием.

- `aiqme-site/` — новый сайт по прототипу заказчика (пока пуст, кроме README).
  Приложение создаётся здесь задачами `docs/planning/edition-2/tasks.json`.
  `aiqme-site/experiments/` — эталонный эксперимент Lunar: не изменять, три каталога держать соседями.
- `docs/` — вся документация. Требования и статус функций ведите в `docs/requirements.md`.
  `docs/customer/client/правки` — первичная переписка, не переписывать.
  Старую переписку, снимки, исторические отчёты и `docs/planning/run/` не осовременивать.
- `docs/customer-prototype/` — аудит, доказательства (`evidence/`) и исходники прототипа
  (`source-backup-20261016/`). Архив исходников недоверенный: его JS (`build.cjs`, `verify.cjs` и др.) не запускать.
- `legacy-landing/` — прежний статический лендинг; не развивается, но собирается и публикуется как раньше.
  Внутри: `site/` (калькулятор), `design-previews/animation-v2/` (оформление; V1 рядом для сравнения),
  `server/` (Flask), `instance/` (приватные данные — не читать и не публиковать), `scripts/`, `tools/`,
  `build/` (генерируется сборщиками), `github-pages/` (готовая сборка для Pages), `archive/`, `references/relats/`.
- Не редактируйте `baseline/`. Отменённые сцены — `legacy-landing/design-previews/animation-v2/archive/scroll-scenes/`;
  не подключайте их. Сохранены видеофон и «СКАН → CAD → ДЕТАЛЬ»; отменённая крыльчатка не возвращается.

Прототип заказчика смотрите через работающий Chrome пользователя по
[инструкции доступа](docs/customer-prototype/prototype-browser.md). Основной способ —
CDP и `python3 docs/customer-prototype/tools/capture_prototype_chrome.py`: скриншоты, DOM, CSS/JS.
Используйте WebSocket из `~/.config/google-chrome/DevToolsActivePort`, не HTTP
`/json/version/`. Снимки: `docs/customer-prototype/evidence/`.
**Не включайте озвучивание или системные флаги доступности `ScreenReaderEnabled`
и `IsEnabled`: пользователь запретил это после побочного эффекта.** Старый
`read_prototype_chrome.py` (AT-SPI) не используйте.
Не перезапускайте Chrome и не закрывайте чужие вкладки; работайте в своей вкладке.

Публикация: весь корень — в публичный репозиторий `git@github.com:GreatGleb/3d-fabrication-web.git`,
ветка `main`; Pages раздаёт сборку лендинга из папки `legacy-landing/github-pages/`.
Сайт: https://greatgleb.github.io/3d-fabrication-web/legacy-landing/github-pages/ (старый адрес переадресуют корневые `index.html`/`404.html`, `.nojekyll` обязателен).
Пока `legacy-landing/github-pages/` — отдельный клон со своим `.git`; переход описан в `docs/legacy-landing/github-pages-v2.md`.
`python3 legacy-landing/scripts/build_github_pages_v2.py` сохраняет `.git` и включает калькулятор
из `site/` в оформление V2. Проверяйте:
`STL_TEST_DIRECTORY=github-pages python3 legacy-landing/scripts/check_stl_pricing.py` и
`python3 legacy-landing/scripts/check_github_pages_ui.py`. Сборщик заменяет рабочие файлы пакета:
сначала проверяйте статус Git и сохраняйте незакоммиченные изменения.
Не используйте `/tmp/aiqme-publish-5QS3Qi`. Push и публикация — только по явному указанию пользователя.

Репозиторий ПУБЛИЧНЫЙ. Приватное (материалы заказчика, аудит и исходники прототипа, `docs/planning/run/`,
`instance/`, снимки-архивы, `.env`) и чужой контент исключены в `.gitignore`. Новые приватные файлы
кладите только в исключённые каталоги и проверяйте `git ls-files` до commit; push — только по указанию пользователя.

Для анимаций сначала читайте `docs/design/relats-study/README.md` и
`docs/design/relats-study/motion-recipes.md`. Галерея — `docs/design/relats-study/index.html`;
референс: `bash legacy-landing/references/relats/start.sh` → http://127.0.0.1:8812/ .
Мобильных роликов в архиве нет. Отличайте подтверждённое поведение от предложений
и неиспользуемого кода. План движения — `docs/design/aiqme-motion-plan.md`,
`docs/design/aiqme-motion-v2.md`; реализованное состояние V2 — в её README.

Облачная архитектура старого плана не утверждена; действующий выбор — серверное развёртывание
по редакции 2. Не описывайте требования как уже работающие функции. Известные прежние ошибки
проверок зафиксированы в отчётах реорганизации.
