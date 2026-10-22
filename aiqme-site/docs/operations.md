# Эксплуатация AIQME: образ и запуск (W-20)

План — [§8 плана](../../docs/planning/edition-2/08-operations.md). Копии, расписание, измерения и runbook — W-29.
Сервер не арендован; ниже — то, что проверено в локальном prod-compose.

## Состав

| Файл | Назначение |
|---|---|
| `docker/Dockerfile` | Цели `app` (php:8.4-fpm-trixie, расширения §2.1, `composer install --no-dev`, Vite из Node-стадии) и `web` (nginx с `public/` образа `app`) |
| `docker/php/aiqme.ini`, `docker/php/zz-aiqme-fpm.conf` | Лимиты §6.4; `clear_env = yes` (официальный образ ставит `no`) |
| `docker/nginx/default.conf` | nginx приложения: PHP только `^/index\.php`, dot-файлы запрещены, журнал без Referer и гостевых адресов |
| `docker/proxy/Caddyfile.example` | Общий прокси хоста (D-35); `docker/proxy/nginx-proxy.conf.example` — альтернатива |
| `docker-compose.prod.yml` | Проект `aiqme-prod`: `app`, `web`, `worker`, `scheduler`, `db`; тома `storage`, `db-data` |
| `bin/build-context` | Чистый контекст: `build/context.tar` и `build/context/` из `git ls-files -co --exclude-standard` без `experiments/` |
| `.env.example` | Шаблон рабочего `.env` (без секретов) |

Закреплённые теги: `php:8.4-fpm-trixie`, `node:22-trixie-slim`, `composer:2.8.12`, `nginx:1.30-trixie`, `postgres:17-trixie`.

Все команды — из `aiqme-site/` с тем же `.env`, что монтируется в PHP-сервисы:

```bash
C="docker compose --env-file <.env> -f docker-compose.prod.yml"   # сервер: ./.env; локально: build/prod-local/.env
A="$C exec -T -u www-data app php artisan"
```

## Сборка

- Сервер: `git archive` версии каталога `aiqme-site/` без `experiments/` → распаковать → `.env` по `.env.example`
  (`AIQME_BUILD_CONTEXT=.`, `AIQME_ENV_FILE=./.env`; `chown root:33 .env && chmod 0640 .env`) → `$C build`.
- Локально: `bin/build-context`, затем `$C build` (в `build/prod-local/.env` — `AIQME_BUILD_CONTEXT=build/context`).
  Рабочее дерево (`vendor/`, `node_modules/`, `.env`, `storage/`) в контекст не попадает.

## Первый запуск и обновление (§8.3)

```bash
$C up -d --wait
$A migrate --force
$A aiqme:bootstrap --no-interaction                       # каждый раз, идемпотентно
$A db:seed --class='Database\Seeders\PrototypeContentSeeder' --force --no-interaction   # только первый раз, пустая БД
$A aiqme:make-admin <email> --name=AIQME --password-stdin < файл-или-канал-с-паролем   # только первый раз (D-29)
$A optimize
$C restart worker scheduler
```

- Доверенный прокси при `web` на `127.0.0.1:<AIQME_WEB_PORT>` — шлюз сети проекта:
  `docker network inspect aiqme-prod_default --format '{{(index .IPAM.Config 0).Gateway}}'` → `TRUSTED_PROXIES=<шлюз>`;
  проверка: `$A config:show aiqme.trusted_proxies`.
- `.env` смонтирован одним файлом: редакторы и `sed -i` заменяют файл, и работающий контейнер видит старый. После правки
  `.env` — `$C restart app worker scheduler`, затем `$A optimize` (так проверено локально).
- Общий прокси (Caddy на хосте) направляет домен на `127.0.0.1:<AIQME_WEB_PORT>` — `docker/proxy/Caddyfile.example`.
  HSTS — пункт runbook W-23 после подключения домена.

## Локальный prod-compose

`build/prod-local/.env` (игнорируется Git, права 0644, синтетические значения) — копия `.env.example` с отличиями:
`APP_URL=http://127.0.0.1:8792`, `APP_KEY` — случайный, `SESSION_SECURE_COOKIE=false`, `MAIL_MAILER=log`,
`DB_PASSWORD=prod-local-only-not-a-secret`, `AIQME_ORDERS_PER_HOUR=100`, `AIQME_ENV_FILE=build/prod-local/.env`,
`AIQME_BUILD_CONTEXT=build/context`, `AIQME_WEB_PORT=8792`, `TRUSTED_PROXIES=<шлюз aiqme-prod_default>`; ключи Stripe,
Google и Telegram пустые (события этих каналов остаются неотправленными — так и должно быть).

Проверка: `bin/browser-check --base-url http://127.0.0.1:8792 --compose-file docker-compose.prod.yml` — compose config,
образы, healthy `web`, доверенный прокси, сценарий 2 §9.3 без оплаты, заявка из 10 STL, том `storage`, журнал `web`.
Отчёт — `build/verification/browser-check/prod-summary.md`. Остановить: `$C down` (тома остаются; `down -v` удаляет данные
только проекта `aiqme-prod`).
