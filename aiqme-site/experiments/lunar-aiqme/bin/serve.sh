#!/usr/bin/env bash
set -euo pipefail
proto_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$proto_root/app"
exec ../bin/php artisan serve --host=127.0.0.1 --port=8857 --no-reload
