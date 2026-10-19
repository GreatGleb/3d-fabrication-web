# Прежний лендинг AIQME (статическая V2)

Лендинг больше не развивается, но собирается и публикуется на GitHub Pages: **https://greatgleb.github.io/3d-fabrication-web/legacy-landing/github-pages/**. Весь корень рабочего пространства публикуется в тот же репозиторий `GreatGleb/3d-fabrication-web`, Pages раздаёт сайт из этой папки. Старый адрес https://greatgleb.github.io/3d-fabrication-web/ переадресуется. [Публикация](../docs/legacy-landing/github-pages-v2.md). Перенесён сюда из корня 18.10.2026 без изменения байтов; поправлены только пути к `docs/` в нескольких скриптах ([отчёт](../docs/reorganization/20261018/README.md)).

Команды ниже — из этого каталога (скрипты не зависят от текущего каталога; из корня добавьте префикс `legacy-landing/`):

```bash
git status --short                          # сначала сохранить незакоммиченное
python3 scripts/build_github_pages_v2.py
STL_TEST_DIRECTORY=github-pages python3 scripts/check_stl_pricing.py
python3 scripts/check_github_pages_ui.py
python3 -m http.server 8830 --bind 127.0.0.1 --directory github-pages
```

| Путь | Назначение |
|---|---|
| `site/` | Исходники и действующий STL-калькулятор |
| `design-previews/animation-v2/` | Оформление публикации; V1 рядом для сравнения; `*/baseline/` не редактировать |
| `server/`, `instance/` | Локальный Flask и его приватные данные (не читать, не публиковать) |
| `scripts/`, `tools/stl/` | Сборки и проверки; сборка STLLoader |
| `build/` | Генерируемые сборки |
| `github-pages/` | Готовая сборка, которую раздаёт Pages; хранится в корневом репозитории (своего `.git` нет); после пересборки изменения нужно закоммитить |
| `archive/` | Ранние концепции, старый workflow, снимки реорганизаций |
| `references/relats/` | Локальный референс Relats: `bash references/relats/start.sh` → http://127.0.0.1:8812/ |

Подробнее: [публикация V2](../docs/legacy-landing/github-pages-v2.md), [локальный сервер](../docs/legacy-landing/local-server.md), [V2](design-previews/animation-v2/README.md), [V1](design-previews/animation-v1/README.md). Push и публикация — только по явному указанию пользователя.
