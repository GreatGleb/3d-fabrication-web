#!/usr/bin/env bash
# Ночь AIQME (D-39, вариант «г» — как ночь Flexiron): ваша учётная запись и вход Claude,
# этот checkout, своя ветка auto/night-<время>, коммит на каждую принятую задачу.
#
#   bash docs/planning/edition-2/run/night-run.sh              # вся очередь L-задач
#   bash docs/planning/edition-2/run/night-run.sh --only W-01  # пробный запуск одной задачи
#   bash docs/planning/edition-2/run/night-run.sh --resume     # продолжить на текущей ветке auto/night-*
#
# Чтобы ночь пережила закрытие терминала — через systemd пользователя:
#   systemd-run --user --collect --unit=aiqme-night bash ~/PycharmProjects/3D_website/docs/planning/edition-2/run/night-run.sh
#   (в 01:30: добавить --on-calendar='*-*-* 01:30:00'; ход: journalctl --user -fu aiqme-night)
# Параметры: AIQME_HOURS (7), AIQME_TASK_MINUTES (120), AIQME_MODEL (модель аккаунта по умолчанию),
#   AIQME_NIGHT_SHARE (0.15 — доля недельного лимита на ночь), AIQME_WEEKLY_TOKENS (120000000),
#   AIQME_REVIEW (all | off | W-16,W-28 — где работает приёмщик), AIQME_FIX_ROUNDS (1 — доработок по замечаниям и красным проверкам),
#   AIQME_STOP_AFTER_SLICE (S1 — остановиться после этапа для просмотра; пусто — не останавливаться).
#
# Отказывается работать в занятом дереве: ночь, начатая поверх чужих правок, заберёт их в свой коммит.
# На время ночи push отключён (remote.origin.pushurl), после — восстанавливается.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
STATE_ROOT="${AIQME_NIGHTS:-$HOME/.local/share/aiqme}"
NO_PUSH="no-push://aiqme-night"
cd "$ROOT"

resume=0
args=()
for a in "$@"; do
  if [ "$a" = "--resume" ]; then resume=1; else args+=("$a"); fi
done

if [ -n "$(git status --porcelain)" ]; then
  echo "Дерево занято незакоммиченной работой — ночь не начинается (git status)." >&2
  exit 3
fi

stamp="$(date +%F-%H%M)"
if [ "$resume" = 1 ]; then
  branch="$(git branch --show-current)"
  case "$branch" in auto/night-*) ;; *) echo "--resume — только на ветке auto/night-*" >&2; exit 3 ;; esac
else
  branch="auto/night-$stamp"
  git switch -c "$branch"
fi
out="$STATE_ROOT/night-$stamp"
mkdir -p "$out"

# Push отключён на время ночи; запрет git push у агента — второй рубеж (night.py, DENY).
old_pushurl="$(git config --local --get remote.origin.pushurl || true)"
restore_push() {
  if [ -n "$old_pushurl" ]; then git config --local remote.origin.pushurl "$old_pushurl"
  else git config --local --unset remote.origin.pushurl || true; fi
}
trap restore_push EXIT
git config --local remote.origin.pushurl "$NO_PUSH"

echo "Ночь: ветка $branch, журнал $out"
status=0
AIQME_D39=g python3 "$ROOT/docs/planning/edition-2/run/night.py" --out "$out" \
  --hours "${AIQME_HOURS:-7}" --task-minutes "${AIQME_TASK_MINUTES:-120}" \
  --model "${AIQME_MODEL:-}" --night-share "${AIQME_NIGHT_SHARE:-0.15}" \
  --weekly-tokens "${AIQME_WEEKLY_TOKENS:-120000000}" --review "${AIQME_REVIEW:-all}" \
  --fix-rounds "${AIQME_FIX_ROUNDS:-1}" --stop-after-slice "${AIQME_STOP_AFTER_SLICE-S1}" "${args[@]}" 2>&1 | tee -a "$out/night.log" || status=$?
echo "Сводка: $out/СВОДКА.md"
exit "$status"
