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
