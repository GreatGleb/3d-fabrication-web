# Эксплуатация AIQME: образ и запуск (W-20)

План — [§8 плана](../../docs/planning/edition-2/08-operations.md). Копии, расписание, измерения и runbook — W-29 (ниже).
Сервер не арендован; ниже — то, что проверено в локальном prod-compose, а шаги только для сервера помечены «на сервере не проверено».

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
- Google Таблица: сайт не создаёт листы — в таблице заранее нужны листы `Orders`, `Requests`, `Contacts`, `Payments`,
  `Events` (`SheetsProjector::SHEETS`), доступ Editor у сервисного аккаунта; затем `$A aiqme:sheets-rebuild` пишет
  заголовки и строки. Без листа — `failed_jobs` «Unable to parse range: Orders!A:Q».
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

# Runbook (W-29)

Команды — из каталога `aiqme-site/` на сервере (`/srv/aiqme/aiqme-site` — пример пути) **от root**: `.env` принадлежит
`root:33` с правами `0640`, а `docker compose` читает `./.env` для подстановки `${…}`.

```bash
C="docker compose --env-file .env -f docker-compose.prod.yml"
A="$C exec -T -u www-data app php artisan"
```

## Развёртывание (§8.3)

1. Локально: `git archive <версия> aiqme-site | tar -x --exclude=aiqme-site/experiments -C <каталог>` → на сервер
   (`legacy-landing/`, `docs/`, `experiments/` и тестовые фикстуры не попадают; `tests/fixtures` образ исключает сам).
2. Первый раз: `.env` по `.env.example` (`AIQME_BACKUP_DIR` — вне каталога приложения),
   `chown root:33 .env && chmod 0640 .env` (при 0600 PHP-FPM его не прочитает).
3. `$C build` (тот же `docker/Dockerfile` W-20) → `$C up -d --wait`.
4. `$A migrate --force` → `$A aiqme:bootstrap --no-interaction`.
5. Только первый раз, пустая БД: `$A db:seed --class='Database\Seeders\PrototypeContentSeeder' --force --no-interaction`
   и `$A aiqme:make-admin <email> --name=AIQME --password-stdin < <файл-или-канал>` (D-29). При обновлениях `make-admin`
   не запускается — он меняет пароль существующего администратора (только при явной смене пароля).
6. `$A optimize` → `$C restart worker scheduler`.
7. `bin/browser-check` против рабочего сервера **не запускается** (W-20): он создаёт заявки и администратора. Проверка —
   открыть сайт, `/up` и Overview.

## Расписание (§8.4)

`scheduler` (`schedule:work`) выполняет: `aiqme:integration-retry` — каждые 5 минут (повтор каналов outbox, W-09/W-18);
`IssuePendingInvoices` — раз в сутки (W-17); `aiqme:orphans` — 03:30, файлы приватного диска (`stl/`, `contact-requests/`,
`invoices/`) без записи в БД пишутся в журнал (warning) и **не удаляются**; `aiqme:prune-sessions` — 03:40, строки
`sessions` старше `SESSION_LIFETIME`; `auth:clear-resets` — 03:45, просроченные токены сброса пароля. Список —
`$A schedule:list`. Время — часовой пояс приложения (UTC).

Ночную копию запускает **cron хоста** (в контейнере `app` нет `pg_dump` 17), строка в `crontab -e` root:

```cron
30 2 * * * cd /srv/aiqme/aiqme-site && bin/backup >> /var/log/aiqme-backup.log 2>&1
```

## Резервная копия (§8.5, D-13, D-31)

`bin/backup` (Python 3 хоста, только стандартная библиотека) — настройки в `.env`: `AIQME_BACKUP_DIR` (вне каталога
приложения, например `/var/backups/aiqme`), `AIQME_BACKUP_DAILY=14`, `AIQME_BACKUP_WEEKLY=8`.

- Порядок: дамп `exec -T db pg_dump --format=custom` → файлы `storage/app` (`tar` в `exec -T app`, не с диска хоста) →
  `files.sha256` (хеш каждого файла) и `SHA256SUMS` (хеши `db.dump`, `storage.tar`, `files.sha256`). Каталог копии
  `<ГГГГММДД-ччммсс>` появляется только после успеха (до того — `.incomplete-…`, при ошибке удаляется).
- Права: каталог копий и каталоги копий `0700`, файлы `0600`. Шифрования нет (D-31): внешнюю копию владелец хранит
  в своём хранилище с доступом только у себя (место — данные владельца, D-13; выгрузку настраивает владелец).
- После успеха — метка времени `storage/app/last-backup.txt` в томе `storage`; Overview админки показывает «Last backup:
  N hours ago» / «Paskutinė atsarginė kopija: …» или «No backup has been made yet.».
- Ротация: остаются последняя копия каждого из 14 последних дней и каждой из 8 последних ISO-недель; остальные удаляются.
  `bin/backup --rotate-only` — только ротация.
- Нечитаемый `.env` (запуск не от root) — явная ошибка `.env не читается`, код 1.
- Каждая копия полная (без сжатия и общих файлов между копиями): место под копии ≈ (14 + 8) × (размер БД + файлов).

## Восстановление (§8.5)

`bin/restore <каталог копии>` (от root) делает шаги 1–5 и останавливается; 6–8 — вручную.

1. Проверяет `SHA256SUMS` копии (повреждённая копия — отказ до любых действий).
2. `php artisan down` (новые заявки и webhook не принимаются; Stripe повторит доставку) → `$C stop worker scheduler`.
3. БД: `pg_restore --clean --if-exists --single-transaction` в контейнере `db` (на пустую БД и поверх существующей).
4. Файлы: `storage.tar` → том `storage` через контейнер `app`.
5. Сверка: каждый файл копии в томе с тем же хешем; у каждой ссылки восстановленной БД (`stl_files.path` + `sha256`,
   вложения `contact_requests.attachments` + `sha256`, `invoices.pdf_path` — наличие) есть файл копии с тем же хешем;
   затем сброс сессий (таблица `sessions`). Любое расхождение — список и код 1.
6. Сверить платежи Stripe после времени копии: успешные списания, которых нет в БД, вносятся по событиям Stripe
   (повторная доставка webhook), а не заново.
7. Проверить, что номер следующего счёта больше последнего выданного (выпущенные PDF и Google Таблица).
8. `$A optimize` → `$A up` → `$C start worker scheduler`.

Если сеть Docker пересоздана (`down`), проверить шлюз и `TRUSTED_PROXIES` (раздел выше). Тестовое восстановление —
только без рабочих ключей Stripe, почты, бота и Sheets.

## Права `.env`, смена ключей, HSTS

- `.env`: `chown root:33 .env && chmod 0640 .env`; всё с `docker compose` и cron `bin/backup` — от root.
- Смена ключа (Stripe, SMTP, Telegram, Google, пароль БД — только вместе с ролью в PostgreSQL): правка `.env` →
  `$C restart app worker scheduler` → `$A optimize` (файл смонтирован одним файлом — без перезапуска контейнер видит
  старый). `APP_KEY` не меняется без необходимости: он шифрует сессии и данные `encrypted`. Telegram — после смены
  токена `$A aiqme:telegram-webhook` (§5.4); Stripe — новый секрет webhook из кабинета Stripe.
- HSTS включается в общем прокси хоста после того, как домен стабильно работает по HTTPS (W-23; начать с короткого
  `max-age`).
- Защита хоста (SSH только по ключу, UFW, автоматические обновления безопасности) — ответственность владельца сервера (D-01).

## Требования к серверу (§8.2, измерение W-29)

**AIQME требует ≈ 1 ГБ RAM, 1 vCPU, 3 ГБ диска + рост ≈ размер загруженных STL (гипотеза ≈ 1,5 ГиБ/30 дней) и
(14 + 8)-кратный объём данных под копии на сервере.** Складывается с требованиями других проектов (их нагрузка не оценивалась).

Метод: локальный prod-compose W-20 (лимиты по умолчанию, 8 ядер хоста), `docker stats --no-stream` в цикле
(~1 замер/1,5 с, 145 строк) во время `bin/browser-check --base-url` — сценарий 2 §9.3 (2 STL, один 500 000 треугольников),
заявка из 10 STL по 500 000 треугольников (≈ 250 МБ, 15,8 с), контактная форма с 2 вложениями, страницы админки и гостя.
Один пользователь последовательно; одновременная нагрузка не измерялась.

| Контейнер | Память: простой → пик | CPU пик | Лимит (рекомендуемый = по умолчанию) |
|---|---|---|---|
| `app` (PHP-FPM, до 5 процессов по 512M) | 52 → 222 МиБ (анализ 10 STL по 500 000 треугольников) | 103 % (упирается в лимит) | 1g / 1.0 |
| `worker` | 49 → 56 МиБ | 7 % | 768m / 0.5 |
| `scheduler` | 49 МиБ | 21 % | 256m / 0.25 |
| `web` (nginx) | 4 → 14 МиБ | 5 % | 256m / 0.5 |
| `db` (PostgreSQL 17) | 21 → 32 МиБ (БД 14 МБ) | 31 % | 512m / 1.0 |

- PDF счёта (dompdf, 12 строк, литовские символы) — в контейнере `app` отдельным процессом: пик памяти процесса
  60,5 МиБ (34 МиБ до рендера), 0,3 с, файл 24 КБ.
- Лимиты по умолчанию оставлены: измеренные пики в 2,5–18 раз ниже; запас `app` — на одновременные загрузки
  (5 процессов FPM × `memory_limit` 512M теоретически больше 1g — при росте нагрузки поднимать лимит `app`), `db` —
  на рост `shared_buffers`/кэша с данными.
- Диск: образы `app` 705 МБ, `web` 168 МБ, `postgres:17-trixie` 454 МБ (≈ 1,3 ГБ); БД 72 МБ тома; STL хранятся
  как загружены (24 файла — 525 МиБ, без сжатия); копия = дамп 0,4 МБ + `storage.tar` 550 МБ, `bin/backup` — 3 с.
