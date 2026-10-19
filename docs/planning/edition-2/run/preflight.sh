#!/usr/bin/env bash
# Предпроверка окружения ночного прогона — первый шаг W-01 (D-28, D-30, D-39).
# Запуск из корня копии: bash docs/planning/edition-2/run/preflight.sh
# Каждая проверка печатает OK / FAIL / WARN с именем; код возврата 1 при любом FAIL.
# Вывод печатается и сам дописывается в aiqme-site/docs/progress.md (агенту перенаправлять не нужно);
# при FAIL W-01 блокируется и прогон останавливается.
#
# Права Claude Code (dontAsk) сверяются с командой, которую запускает агент, — здесь это сам скрипт
# (правило в run/claude-settings.local.json). Перенаправления внутри скрипта на правило не влияют.
# Если агент выполняет проверки вручную, ssh и gh — ровно в виде ниже, без 2>&1 и других
# перенаправлений, иначе правило D-28 не совпадёт.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT" || exit 2

FAILS=0
ok()   { printf 'OK    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; FAILS=$((FAILS + 1)); }
warn() { printf 'WARN  %s\n' "$*"; }

# Вариант D-39: только строка, записанная пользователем в aiqme-site/docs/progress.md до запуска;
# засчитывается строка целиком, не подстрока. Обе строки сразу — FAIL (вариант неоднозначен).
LOG=aiqme-site/docs/progress.md
VARIANT="default"
if [ -f "$LOG" ]; then
  HAS_B=0; HAS_V=0
  grep -qxF 'D-39: вариант б' "$LOG" && HAS_B=1
  grep -qxF 'D-39: вариант в' "$LOG" && HAS_V=1
  if [ "$HAS_B" = 1 ] && [ "$HAS_V" = 1 ]; then VARIANT="conflict"
  elif [ "$HAS_B" = 1 ]; then VARIANT="b"
  elif [ "$HAS_V" = 1 ]; then VARIANT="v"
  fi
fi

# Вариант «г» (как ночь Flexiron: учётная запись и вход пользователя, этот checkout) задаёт night-run.sh
# переменной AIQME_D39=g; она важнее строки в progress.md.
if [ "${AIQME_D39:-}" = "g" ]; then VARIANT="g"; fi

main() {
echo "## Предпроверка $(date '+%Y-%m-%d %H:%M')"
echo
if [ "$VARIANT" = "conflict" ]; then
  fail "D-39: в progress.md есть обе строки «D-39: вариант б» и «D-39: вариант в» — оставьте одну"
  VARIANT="default"
else
  echo "D-39: вариант ${VARIANT} (default — по умолчанию, b — «б», v — «в», g — «г», как ночь Flexiron)"
fi

# 1. Docker доступен.
if docker ps >/dev/null 2>&1; then ok "docker ps"; else fail "docker ps: Docker недоступен"; fi

# 2. Реестр npm (D-20).
if npm ping >/dev/null 2>&1; then ok "npm ping"; else fail "npm ping: реестр npm недоступен"; fi

# 3. Packagist (D-20).
if curl -sI https://repo.packagist.org/packages.json >/dev/null 2>&1; then
  ok "curl -sI https://repo.packagist.org/packages.json"
else
  fail "curl -sI https://repo.packagist.org/packages.json: Packagist недоступен"
fi

# 4. Нет ключа с доступом к GitHub (D-28). accept-new — чтобы у новой учётной записи без known_hosts
#    не было «Host key verification failed», которое ключ не проверяет.
if [ "$VARIANT" = "g" ]; then
  # Свой ключ пользователя есть — проверяется, что push отключён на время ночи (night-run.sh).
  if [ "$(git remote get-url --push origin 2>/dev/null)" = "no-push://aiqme-night" ]; then
    ok "push отключён на время ночи (remote.origin.pushurl)"
  else
    fail "push не отключён: запускайте ночь через run/night-run.sh (D-39 г)"
  fi
else
SSH_OUT="$(ssh -T -o BatchMode=yes -o StrictHostKeyChecking=accept-new git@github.com 2>&1)"
if printf '%s' "$SSH_OUT" | grep -q 'successfully authenticated'; then
  fail "ssh git@github.com: приветствие GitHub — ключ с доступом, остановка (D-28)"
elif printf '%s' "$SSH_OUT" | grep -q 'Permission denied'; then
  ok "ssh git@github.com: Permission denied (ключей push нет)"
else
  fail "ssh git@github.com: ожидался отказ «Permission denied», получено: ${SSH_OUT}"
fi
fi

# 5. gh не авторизован (если установлен).
if command -v gh >/dev/null 2>&1; then
  if gh auth status >/dev/null 2>&1 && [ "$VARIANT" = "g" ]; then
    warn "gh auth status: вход выполнен — агенту gh запрещён (D-39 г)"
  elif gh auth status >/dev/null 2>&1; then
    fail "gh auth status: вход выполнен — токен GitHub доступен (D-28)"
  else
    ok "gh auth status: вход не выполнен"
  fi
else
  ok "gh не установлен"
fi

# 6. Rootless Docker (D-39; снимается вариантами б и в).
if [ "$VARIANT" = "default" ]; then
  if docker info --format '{{.SecurityOptions}}' 2>/dev/null | grep -q rootless; then
    ok "docker rootless"
  else
    fail "docker rootless: Docker с правами root: изоляция ключей не обеспечена, D-39"
  fi
else
  ok "docker rootless: снято вариантом D-39 ${VARIANT}"
fi

# 7. Входы задач в копии (D-39, шаг 1).
if ls -d docs/customer-prototype/audit-20261016 \
         docs/customer-prototype/evidence/audit-20261016/verified-route-screens \
         docs/customer-prototype/evidence/audit-20261016/states-followup \
         docs/customer-prototype/source-backup-20261016/AIQME-site/dist/app.js \
         aiqme-site/experiments/lunar-aiqme/runtime \
         aiqme-site/experiments/lunar-aiqme/app/vendor >/dev/null; then
  ok "входы задач есть"
else
  fail "входы задач: рабочая копия не подготовлена по D-39"
fi

# 8. В копии нет instance/ и archive/ (D-39; снимается вариантом в).
if [ "$VARIANT" = "v" ] || [ "$VARIANT" = "g" ]; then
  ok "legacy-landing без instance и archive: снято вариантом D-39 ${VARIANT} (чтение instance/ агенту запрещено)"
elif ls legacy-landing | grep -qxE 'instance|archive'; then
  fail "legacy-landing содержит instance или archive: рабочая копия не подготовлена по D-39"
else
  ok "legacy-landing без instance и archive"
fi

# 9. PHP эксперимента с расширениями (D-39, шаг 1а). Засчитываются только строки, целиком равные имени.
PHP_OUT="$(aiqme-site/experiments/lunar-aiqme/bin/php -m 2>&1)"
PHP_OK=1
for ext in bcmath intl gd pdo_pgsql pgsql zip; do
  printf '%s\n' "$PHP_OUT" | grep -qx "$ext" || { PHP_OK=0; echo "      нет расширения $ext"; }
done
if printf '%s' "$PHP_OUT" | grep -q 'Unable to load dynamic library'; then
  PHP_OK=0; echo "      есть «Unable to load dynamic library»"
fi
if [ "$PHP_OK" = 1 ]; then
  ok "bin/php эксперимента: bcmath intl gd pdo_pgsql pgsql zip"
else
  fail "bin/php эксперимента: рабочая копия не подготовлена по D-39 — пути runtime/conf/*.ini в копии не переписаны (D-39, шаг 1а)"
fi

# 10. Python Playwright (D-39, шаг 2): без него bin/browser-check не заработал бы при зелёных остальных проверках.
if python3 -c "import playwright" >/dev/null 2>&1; then
  ok "python3 import playwright"
else
  fail "python3 import playwright: рабочая копия не подготовлена по D-39"
fi

# 11. Node и npm в PATH сессии (nvm; иначе правила Bash(npm *)/Bash(node *) не совпадут).
if which node npm >/dev/null 2>&1; then ok "which node npm"; else fail "which node npm: node/npm не в PATH сессии"; fi

# 12. Удалённая отладка Chrome (D-30): только предупреждение, не остановка. CDP агенту запрещён всегда.
if [ "$VARIANT" = "v" ] || [ "$VARIANT" = "g" ]; then
  DTAP="$HOME/.config/google-chrome/DevToolsActivePort"
  DTAP_ERR="$(LC_ALL=C ls "$DTAP" 2>&1 >/dev/null)"
  if [ -z "$DTAP_ERR" ]; then
    warn "удалённая отладка Chrome включена (DevToolsActivePort есть), D-30"
  elif printf '%s' "$DTAP_ERR" | grep -q 'Permission denied'; then
    warn "удалённая отладка Chrome: не проверено (D-39) — права сессии не пустили проверку"
  else
    ok "DevToolsActivePort нет"
  fi
else
  warn "удалённая отладка Chrome: не проверено (D-39) — выключение — шаг подготовки пользователя"
fi

echo
if [ "$FAILS" -gt 0 ]; then
  echo "Предпроверка: FAIL (${FAILS}). W-01 заблокирована, прогон останавливается."
  return 1
fi
echo "Предпроверка: OK."
}

mkdir -p "$(dirname "$LOG")"
main 2>&1 | tee -a "$LOG"
exit "${PIPESTATUS[0]}"
