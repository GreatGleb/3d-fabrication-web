#!/usr/bin/env bash
# Супервизор ночи AIQME: когда прогон остановлен из-за пропажи интернета, ждёт сеть
# (Wi-Fi включён, известные сети подключаются сами) и продолжает прогон через --resume
# до общего срока. Использование: night-supervise.sh <срок в секундах эпохи>
DEADLINE="$1"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE="${AIQME_NIGHTS:-$HOME/.local/share/aiqme}"
LOG="$STATE/supervisor.log"
MAX_RESTARTS=8

say() { echo "[$(date +%FT%T)] $*" >> "$LOG"; }
online() { curl -s -o /dev/null --max-time 10 https://api.anthropic.com; }
left() { echo $(( DEADLINE - $(date +%s) )); }

# Ждёт сеть до срока; false — срок вышел.
wait_online() {
  local ok=0
  while [ "$(left)" -gt 1200 ]; do
    nmcli radio wifi on >/dev/null 2>&1
    if online; then ok=$((ok + 1)); else ok=0; nmcli device wifi rescan >/dev/null 2>&1; fi
    [ "$ok" -ge 2 ] && return 0   # две удачные проверки подряд — сеть устойчива
    sleep 30
  done
  return 1
}

say "супервизор запущен, срок $(date -d @"$DEADLINE" +%H:%M)"
while systemctl --user is-active -q aiqme-night; do sleep 30; done

restarts=0
while :; do
  last="$(ls -dt "$STATE"/night-*/ | head -1)night.log"
  if ! tail -5 "$last" | grep -qE 'сторож: интернета нет|ночь остановлена'; then
    say "прогон закончился сам ($(tail -1 "$last" | cut -c1-200)) — супервизор выходит"; break
  fi
  if tail -5 "$last" | grep -q 'ночь остановлена' && ! tail -5 "$last" | grep -q 'сторож' && online; then
    say "прогон остановлен не из-за сети ($(tail -1 "$last" | cut -c1-200)) — не перезапускаю"; break
  fi
  [ "$restarts" -ge "$MAX_RESTARTS" ] && { say "перезапусков уже $restarts — хватит"; break; }
  say "интернета нет — жду сеть"
  wait_online || { say "срок вышел, сеть так и не появилась"; break; }
  hours="$(python3 -c "print(round($(left) / 3600, 2))")"
  restarts=$((restarts + 1))
  say "сеть есть — продолжаю прогон (--resume, ещё $hours ч, перезапуск $restarts)"
  AIQME_HOURS="$hours" bash "$HERE/night-netwatch.sh"
done
say "супервизор завершён"
