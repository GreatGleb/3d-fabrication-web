#!/usr/bin/env bash
# Ночь AIQME со сторожем сети: прогон идёт, пока есть интернет; после 4 неудачных
# проверок подряд (≈2 мин) прогон останавливается, а работа текущей задачи уходит в
# git stash «night-blocked W-xx» — как при остановке самим оркестратором (--resume её подхватит).
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../../.." && pwd)"
STATE="${AIQME_NIGHTS:-$HOME/.local/share/aiqme}"
cd "$ROOT" || exit 1

AIQME_HOURS="${AIQME_HOURS:-24}" bash "$HERE/night-run.sh" --resume &
RUN=$!

online() { curl -s -o /dev/null --max-time 10 https://api.anthropic.com; }

stop_night() {
  local dir log tid
  dir="$(ls -dt "$STATE"/night-*/ | head -1)"; log="$dir/night.log"
  pgrep -f 'edition-2/run/night.py' >/dev/null || return 0   # оркестратор уже вышел сам
  tid="$(grep -oE 'W-[0-9]+ start:' "$log" | tail -1 | cut -d' ' -f1)"
  pkill -TERM -f 'edition-2/run/night.py'
  sleep 2
  # Сессии Claude и проверки запускаются в своих группах процессов — гасим группы целиком.
  for p in $(pgrep -f "claude --print --output-format json --add-dir $ROOT") \
           $(pgrep -f "$ROOT/aiqme-site/bin/"); do
    kill -TERM -- "-$(ps -o pgid= -p "$p" | tr -d ' ')" 2>/dev/null
  done
  sleep 5
  echo "[$(date +%FT%T)] сторож: интернета нет ~2 мин — ночь остановлена на ${tid:-?}" >> "$log"
  if [ -n "$tid" ] && [ -n "$(git status --porcelain)" ]; then
    git stash push -u -m "night-blocked $tid"
    printf '\n\n## %s: заблокирована ночным прогоном (%s)\n\nПричина (оркестратор): ночь остановлена сторожем — пропал интернет, работа прервана на середине. Работа — в git stash «night-blocked %s».\n' \
      "$tid" "$(date '+%Y-%m-%d %H:%M')" "$tid" >> aiqme-site/docs/progress.md
    git add aiqme-site/docs/progress.md
    git commit -q -m "night-note: $tid остановлена сторожем сети"
    echo "[$(date +%FT%T)] сторож: работа $tid — в git stash «night-blocked $tid»" >> "$log"
  fi
}

fails=0
while kill -0 "$RUN" 2>/dev/null; do
  sleep 30
  if online; then fails=0; else fails=$((fails + 1)); fi
  if [ "$fails" -ge 4 ]; then stop_night; break; fi
done
wait "$RUN"
