#!/usr/bin/env bash
set -euo pipefail
comparison_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$comparison_root/.."
experiments_root="$PWD"
lunar-aiqme/bin/runtime.sh
if [[ -z "$(docker ps -aq --filter name='^/aiqme-lunar-probe-postgres$')" ]]; then
  docker run --name aiqme-lunar-probe-postgres --label aiqme.scope=lunar-probe \
    -e POSTGRES_USER=probe -e POSTGRES_PASSWORD=synthetic-local-only -e POSTGRES_DB=aiqme_probe \
    -p 127.0.0.1:55439:5432 -d \
    postgres:17@sha256:2d2b8998d31037bf721cfdf764d76ba74171b4fab3431b7f72c27c56ddbdf9e3
else
  [[ "$(docker inspect -f '{{index .Config.Labels "aiqme.scope"}}' aiqme-lunar-probe-postgres)" == 'lunar-probe' ]] || { echo 'Container name belongs to another task.' >&2; exit 1; }
  docker start aiqme-lunar-probe-postgres >/dev/null
fi
for attempt in {1..30}; do
  if docker exec aiqme-lunar-probe-postgres pg_isready -U probe >/dev/null; then break; fi
  sleep 1
done

for database in aiqme_lunar_comparison aiqme_lunar_comparison_test aiqme_plain_comparison aiqme_plain_comparison_test; do
  if [[ "$(docker exec aiqme-lunar-probe-postgres psql -U probe -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$database'")" != '1' ]]; then
    docker exec aiqme-lunar-probe-postgres createdb -U probe "$database"
  fi
done
for variant in lunar-aiqme laravel-orders-aiqme; do
  app_root="$experiments_root/$variant/app"
  if [[ ! -f "$app_root/.env" ]]; then cp "$app_root/.env.example" "$app_root/.env"; fi
  if [[ "$variant" == 'lunar-aiqme' ]]; then expected_db=aiqme_lunar_comparison; else expected_db=aiqme_plain_comparison; fi
  rg -q "^DB_DATABASE=$expected_db$" "$app_root/.env" || { echo "Unexpected database configuration for $variant" >&2; exit 1; }
  lunar-aiqme/bin/php "$(command -v composer)" install --working-dir="$app_root" --no-interaction --prefer-dist
  (
    cd "$app_root"
    if ! rg -q '^APP_KEY=base64:' .env; then ../../lunar-aiqme/bin/php artisan key:generate --no-interaction; fi
    ../../lunar-aiqme/bin/php artisan migrate --force --no-interaction
    ../../lunar-aiqme/bin/php artisan db:seed --force --no-interaction
    if [[ "$variant" == 'lunar-aiqme' ]]; then ../../lunar-aiqme/bin/php artisan filament:assets --no-interaction; fi
  )
done
python3 "$comparison_root/bin/configure_codex.py"
