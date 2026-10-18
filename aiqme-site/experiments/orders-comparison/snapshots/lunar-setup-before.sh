#!/usr/bin/env bash
set -euo pipefail
proto_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$proto_root"
if [[ "$(/usr/bin/php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')" != "8.4" ]]; then
  echo 'This local runtime bootstrap targets Ubuntu amd64 PHP 8.4; use a compatible PHP 8.3+ with required extensions elsewhere.' >&2
  exit 1
fi
mkdir -p runtime/packages runtime/conf
while IFS= read -r package; do
  package_name="${package%%=*}"
  if ! compgen -G "runtime/packages/${package_name}_*.deb" >/dev/null; then
    (cd runtime/packages && apt download "$package")
  fi
done < runtime-packages.txt
sha256sum -c evidence/runtime-packages.sha256
for package in runtime/packages/*.deb; do dpkg-deb -x "$package" runtime; done
for extension in bcmath intl gd zip pdo_sqlite sqlite3 pdo_pgsql pgsql; do
  if ! /usr/bin/php -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
    printf 'extension=%s/runtime/usr/lib/php/20240924/%s.so\n' "$proto_root" "$extension" > "runtime/conf/$extension.ini"
  fi
done
bin/php "$(command -v composer)" install --working-dir=app --no-interaction --prefer-dist
python3 - <<'PY'
from pathlib import Path
root = Path('.').resolve()
p = root/'app/.env'
if not p.exists():
    p.write_text((root/'app/.env.example').read_text())
p = root/'app/.codex/config.toml'
p.parent.mkdir(exist_ok=True)
p.write_text('[mcp_servers.laravel-boost]\ncommand = "'+str(root/'bin/php')+'"\nargs = ["'+str(root/'app/artisan')+'", "boost:mcp"]\ncwd = "'+str(root/'app')+'"\n')
PY
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
if [[ "$(docker exec aiqme-lunar-probe-postgres psql -U probe -d aiqme_probe -tAc "SELECT 1 FROM pg_database WHERE datname='aiqme_probe_test'")" != '1' ]]; then
  docker exec aiqme-lunar-probe-postgres createdb -U probe aiqme_probe_test
fi
cd app
if ! rg -q '^APP_KEY=base64:' .env; then ../bin/php artisan key:generate --no-interaction; fi
../bin/php artisan migrate --force --no-interaction
../bin/php artisan db:seed --force --no-interaction
../bin/php artisan filament:assets --no-interaction
