#!/usr/bin/env bash
# Дождаться текущего прогона (служба aiqme-night), затем --resume по всем оставшимся задачам
# со сторожем сети и супервизором до срока. Использование: finish-all.sh <срок в секундах эпохи>
# (так прошли 23.10). Сон ноутбука — снаружи, после снятия блокировки.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE="${AIQME_NIGHTS:-$HOME/.local/share/aiqme}"
DL="$1"
say() { echo "[$(date +%FT%T)] finish-all: $*" >> "$STATE/supervisor.log"; }
online() { curl -s -o /dev/null --max-time 10 https://api.anthropic.com; }

say "жду окончания текущего прогона (aiqme-night)"
while systemctl --user is-active -q aiqme-night; do sleep 20; done
say "текущий прогон закончился: $(tail -1 "$(ls -dt "$STATE"/night-*/ | head -1)night.log" | cut -c1-200)"

until online; do
  [ "$(date +%s)" -ge $((DL - 1200)) ] && { say "срок вышел без сети"; exit 0; }
  nmcli radio wifi on >/dev/null 2>&1; sleep 30
done
hours=$(python3 -c "print(round(($DL - $(date +%s)) / 3600, 2))")
say "запускаю --resume по оставшимся задачам на $hours ч"
AIQME_HOURS="$hours" bash "$STATE/night-netwatch.sh"
bash "$STATE/night-supervise.sh" "$DL"
say "все задачи прогнаны — ноутбук на сон"
