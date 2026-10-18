#!/usr/bin/env bash
set -euo pipefail
proto_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$proto_root/app"
../bin/php artisan test --compact tests/Feature --log-junit ../evidence/junit.xml | tee ../evidence/tests-final.log
