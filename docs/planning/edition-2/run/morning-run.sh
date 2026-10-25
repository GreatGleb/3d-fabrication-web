#!/usr/bin/env bash
# Часть night-at.sh: ждёт пробуждения, затем интернета, затем идёт прогон со сторожем сети
# и супервизором (night-netwatch.sh, night-supervise.sh рядом).
WAKE="$1"; HOURS="$2"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE="${AIQME_NIGHTS:-$HOME/.local/share/aiqme}"
LOG="$STATE/morning.log"
say() { echo "[$(date '+%F %T')] $*" >> "$LOG"; }
online() { curl -s -o /dev/null --max-time 10 https://api.anthropic.com; }

say "ждём пробуждения в $(date -d @"$WAKE" +%H:%M)"
while [ "$(date +%s)" -lt "$WAKE" ]; do sleep 10; done
say "проснулся, жду интернет"
for _ in $(seq 60); do   # до 30 минут
  nmcli radio wifi on >/dev/null 2>&1
  online && break
  nmcli device wifi rescan >/dev/null 2>&1
  sleep 30
done
online || { say "интернета нет 30 минут — прогон не запускаю"; exit 1; }

DL=$(( $(date +%s) + ${HOURS%.*} * 3600 ))
say "интернет есть — прогон на $HOURS ч (до $(date -d @"$DL" +%H:%M))"
AIQME_HOURS="$HOURS" bash "$STATE/night-netwatch.sh" >> "$LOG" 2>&1
# Пропала сеть посреди прогона — супервизор дождётся её и продолжит до срока.
bash "$STATE/night-supervise.sh" "$DL"
say "готово"
