#!/usr/bin/env bash
# Возврат результата ночного прогона из копии aiqme-run в корень пользователя (D-39).
# Выполняет пользователь с sudo, между ночами — только этот шаг (повторная подготовка — после него):
#   bash docs/planning/edition-2/run/return-results.sh /путь/к/корню
# Возвращается только aiqme-site/ (без experiments/, vendor/, node_modules/, .env*, .mcp.json, .codex/,
# public/storage — они содержат пути aiqme-run, у себя их создают заново). Исключения с «/» привязаны
# к aiqme-site/, поэтому resources/views/vendor/, lang/vendor/ и public/vendor/ возвращаются.
# --delete переносит удаления агента; исключённое у пользователя не трогает.
set -euo pipefail

COPY=/home/aiqme-run/3D_website

if [ "$#" -ne 1 ]; then echo "Использование: $0 /путь/к/корню" >&2; exit 2; fi
if [ "$(id -u)" -eq 0 ]; then echo "Запускайте от своей учётной записи, не от root." >&2; exit 2; fi
ROOT="$(cd "$1" && pwd)"
ME="$(id -un)"
[ -d "$ROOT/aiqme-site" ] || { echo "Нет $ROOT/aiqme-site" >&2; exit 2; }

confirm() {
  local answer
  printf '\n== %s\n' "$1"
  read -r -p "Выполнить (sudo)? [y/N] " answer
  [ "$answer" = "y" ] || [ "$answer" = "Y" ]
}

RSYNC_ARGS=(-a --delete --chown="$ME:$ME"
  --exclude /experiments/ --exclude /vendor/ --exclude /node_modules/
  --include .env.example --exclude .env --exclude '.env.*'
  --exclude /.mcp.json --exclude /.codex/ --exclude /public/storage
  "$COPY/aiqme-site/" "$ROOT/aiqme-site/")

# Своих незакоммиченных файлов в aiqme-site/ до возврата быть не должно: --delete их удалит или затрёт.
LOCAL_CHANGES="$(git -C "$ROOT" status --porcelain -- aiqme-site ':!aiqme-site/experiments' || true)"
if [ -n "$LOCAL_CHANGES" ]; then
  echo "В $ROOT/aiqme-site есть незакоммиченные изменения:"
  echo "$LOCAL_CHANGES"
  read -r -p "Продолжить всё равно? [y/N] " a; [ "$a" = "y" ] || [ "$a" = "Y" ] || exit 1
fi

if confirm "Предпросмотр: rsync --dry-run из $COPY/aiqme-site/"; then
  sudo rsync --dry-run --itemize-changes "${RSYNC_ARGS[@]}"
fi

if confirm "Возврат: rsync --delete $COPY/aiqme-site/ → $ROOT/aiqme-site/"; then
  sudo rsync "${RSYNC_ARGS[@]}"
else
  echo "Возврат не выполнен."; exit 1
fi

# docs/requirements.md целиком не копируется (затёр бы правки пользователя после подготовки).
if confirm "Показать diff docs/requirements.md (копия aiqme-run против корня)"; then
  sudo diff -u "$ROOT/docs/requirements.md" "$COPY/docs/requirements.md" || true
fi

cat <<EOF

Дальше вручную:
- Только если W-21 выполнена: перенести из diff выше строку «Новый сайт» в $ROOT/docs/requirements.md.
- .env, .mcp.json, .codex/ и ссылку public/storage создать у себя заново.
- git status и git diff до commit (commit и push — решение пользователя).
EOF
