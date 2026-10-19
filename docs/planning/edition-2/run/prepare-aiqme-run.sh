#!/usr/bin/env bash
# Подготовка учётной записи ночного прогона aiqme-run по D-39, шаги 0–2 (Ubuntu 24.04).
# Выполняет пользователь (не агент) из своей учётной записи с правом sudo:
#   bash docs/planning/edition-2/run/prepare-aiqme-run.sh /путь/к/корню
# Идемпотентный: выполненное пропускается. Перед каждым шагом с sudo спрашивает подтверждение;
# без ответа «y» шаг пропускается и ничего не меняет.
# Повторная копия (шаги 1 и 1а) — только после return-results.sh, иначе rsync затрёт несданные файлы агента.
set -euo pipefail

RUN_USER=aiqme-run
RUN_HOME=/home/$RUN_USER
COPY=$RUN_HOME/3D_website
NODE_MAJOR=22
NVM_VERSION=v0.40.3   # версия установщика nvm (D-39 её не задаёт)
SETTINGS_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/claude-settings.local.json"

if [ "$#" -ne 1 ]; then echo "Использование: $0 /путь/к/корню" >&2; exit 2; fi
if [ "$(id -u)" -eq 0 ]; then echo "Запускайте от своей учётной записи, не от root." >&2; exit 2; fi
ROOT="$(cd "$1" && pwd)"
if [ ! -f "$ROOT/AGENTS.md" ] || [ ! -d "$ROOT/aiqme-site" ]; then
  echo "$ROOT не похож на корень рабочего пространства (нет AGENTS.md или aiqme-site/)." >&2; exit 2
fi

confirm() {
  local answer
  printf '\n== %s\n' "$1"
  read -r -p "Выполнить (sudo)? [y/N] " answer
  [ "$answer" = "y" ] || [ "$answer" = "Y" ]
}

as_run() {  # команда под aiqme-run в пользовательской сессии systemd (sudo -iu её не даёт)
  sudo machinectl shell -q "$RUN_USER@" /bin/bash -c "$1"
}

# Домашний каталог пользователя должен быть закрыт для aiqme-run (0750) — только проверка, не меняется.
HOME_MODE="$(stat -c %a "$HOME")"
if [ "$((8#$HOME_MODE & 8#005))" -ne 0 ]; then
  echo "ВНИМАНИЕ: $HOME имеет права $HOME_MODE — другим учётным записям открыт (D-39 рассчитан на 0750)."
fi

# --- Шаг 0. Учётная запись и rootless Docker ---------------------------------
if confirm "Шаг 0: пакеты uidmap python3-venv systemd-container, учётная запись $RUN_USER, linger, rootless Docker"; then
  sudo apt install -y uidmap python3-venv systemd-container
  if id "$RUN_USER" >/dev/null 2>&1; then
    echo "Учётная запись $RUN_USER уже есть."
  else
    sudo useradd -m -s /bin/bash "$RUN_USER"
  fi
  sudo loginctl enable-linger "$RUN_USER"
  as_run '
    set -e
    if ! command -v dockerd-rootless-setuptool.sh >/dev/null; then
      echo "Нет dockerd-rootless-setuptool.sh (пакет docker-ce-rootless-extras)." >&2; exit 1
    fi
    if systemctl --user is-active --quiet docker; then
      echo "Rootless Docker уже работает."
    else
      dockerd-rootless-setuptool.sh install
    fi
    line="export DOCKER_HOST=unix:///run/user/\$(id -u)/docker.sock"
    grep -qxF "$line" ~/.bashrc || echo "$line" >> ~/.bashrc
  '
else
  echo "Шаг 0 пропущен."
fi

# --- Шаг 1. Копия корня без приватных данных ---------------------------------
# Задачам instance/, archive/ (в archive/reorganization-* — снимки корня вместе с instance/), переписка
# заказчика docs/customer/ и docs/planning/run/ не нужны; .env.example копируется.
STEP1_TEXT="Шаг 1: копия $ROOT → $COPY (без instance/, archive/, docs/customer/, docs/planning/run/, .env)"
if sudo test -d "$COPY"; then
  STEP1_TEXT="$STEP1_TEXT. Копия уже есть: продолжайте, только если результат уже возвращён return-results.sh"
fi
if confirm "$STEP1_TEXT"; then
  sudo rsync -a --chown="$RUN_USER:$RUN_USER" \
    --exclude /legacy-landing/instance/ --exclude /legacy-landing/archive/ \
    --exclude /docs/customer/ --exclude /docs/planning/run/ \
    --include .env.example --exclude .env --exclude '.env.*' \
    --exclude /.claude/settings.local.json \
    "$ROOT/" "$COPY/"
else
  echo "Шаг 1 пропущен."
fi

# --- Шаг 1а. Пути расширений PHP эксперимента в копии -------------------------
# runtime/conf/*.ini подключают .so по абсолютному пути из корня пользователя, закрытого для aiqme-run.
# runtime/ игнорируется Git; корень пользователя не меняется; при возврате experiments/ исключён.
if confirm "Шаг 1а: заменить $ROOT/ на $COPY/ в runtime/conf/*.ini копии"; then
  # повторный запуск ничего не меняет: старого пути в файлах уже нет
  sudo -u "$RUN_USER" env OLD="$ROOT/" NEW="$COPY/" bash -c \
    'sed -i "s#${OLD}#${NEW}#" "$NEW"aiqme-site/experiments/lunar-aiqme/runtime/conf/*.ini'
else
  echo "Шаг 1а пропущен."
fi

# --- Шаг 2. Node, агент, Playwright, правила D-28 ----------------------------
read -r -p $'\nКакие агенты поставить: claude, codex или оба через пробел [claude codex]: ' AGENTS
AGENTS="${AGENTS:-claude codex}"
PKGS=""
for a in $AGENTS; do
  case "$a" in
    claude) PKGS="$PKGS @anthropic-ai/claude-code" ;;
    codex)  PKGS="$PKGS @openai/codex" ;;
    *) echo "Неизвестный агент: $a" >&2; exit 2 ;;
  esac
done
if confirm "Шаг 2: под $RUN_USER — nvm и Node $NODE_MAJOR, npm install -g$PKGS, Playwright с Chromium в ~/pw, .claude/settings.local.json копии"; then
  as_run "
    set -e
    if [ ! -s ~/.nvm/nvm.sh ]; then
      curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/$NVM_VERSION/install.sh | bash
    fi
    . ~/.nvm/nvm.sh
    nvm install $NODE_MAJOR
    nvm alias default $NODE_MAJOR
    npm install -g$PKGS
    # системный Python 3.12 — EXTERNALLY-MANAGED, pip нет: Playwright в venv
    [ -x ~/pw/bin/python ] || python3 -m venv ~/pw
    ~/pw/bin/pip install playwright
    ~/pw/bin/python -m playwright install chromium
    line='export PATH=\"\$HOME/pw/bin:\$PATH\"'
    grep -qxF \"\$line\" ~/.bashrc || echo \"\$line\" >> ~/.bashrc
  "
  sudo install -d -o "$RUN_USER" -g "$RUN_USER" "$COPY/.claude"
  sudo install -m 0644 -o "$RUN_USER" -g "$RUN_USER" "$SETTINGS_SRC" "$COPY/.claude/settings.local.json"
else
  echo "Шаг 2 пропущен."
fi

cat <<EOF

Дальше вручную:
- Вход агента в сессии aiqme-run (вход пользователя в его ~ недоступен):
    sudo machinectl shell $RUN_USER@
    claude          # затем /login
    codex login
- Шаг 3 D-39: выключить удалённую отладку Chrome (chrome://inspect/#remote-debugging) — обязательно (D-30).
  Локальные сервисы других проектов на 127.0.0.1/0.0.0.0 учётной записи прогона доступны по сети
  (риск принят; альтернатива — остановить их на время прогона).
- Альтернатива D-39: в $COPY/aiqme-site/docs/progress.md отдельной строкой, без маркера списка и пробелов, ровно «D-39: вариант б» или «D-39: вариант в» (одну; обе — FAIL предпроверки).
- Запуск агента из сессии $RUN_USER в $COPY по D-28; первый шаг W-01 — run/preflight.sh.
EOF
