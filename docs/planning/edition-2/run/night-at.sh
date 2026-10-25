#!/usr/bin/env bash
# Прогон AIQME по будильнику: ноутбук засыпает сейчас, просыпается в ЧЧ:ММ и сам
# запускает прогон, даже с закрытой крышкой.
#   bash docs/planning/edition-2/run/night-at.sh 04:58 [часов=6]
# Пароль sudo спрашивается один раз — для rtcwake. Прогон идёт в службе aiqme-morning
# (терминал можно закрыть после засыпания); пока она жива, крышка не усыпляет ноутбук.
# После прогона блокировка снимается, и при закрытой крышке ноутбук засыпает сам.
set -euo pipefail
AT="${1:?время пробуждения, например 04:58}"
HOURS="${2:-6}"
WAKE=$(date -d "today $AT" +%s)
[ "$WAKE" -gt "$(date +%s)" ] || WAKE=$(date -d "tomorrow $AT" +%s)

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../../.." && pwd)"
if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
  echo "В дереве незакоммиченные изменения — night-run.sh не стартует. Сначала git status." >&2; exit 3
fi
systemctl --user is-active -q aiqme-morning && { echo "aiqme-morning уже запущена" >&2; exit 3; }

sudo -v
systemd-run --user --collect --unit=aiqme-morning \
  systemd-inhibit --what=sleep:handle-lid-switch --why="AIQME morning run" \
  bash "$HERE/morning-run.sh" "$WAKE" "$HOURS"
echo "Пробуждение: $(date -d @"$WAKE" '+%d.%m %H:%M'), прогон на $HOURS ч. Засыпаю."
sudo rtcwake -m mem -t "$WAKE"
