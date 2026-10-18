#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../app"
exec ../bin/php artisan serve --host=127.0.0.1 --port=8858 --no-reload
