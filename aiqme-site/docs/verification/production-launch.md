# Рабочий запуск — уровень P (W-23), журнал

**Статус: сайт установлен на aiqme.com (28.10), приём реальных заказов не открыт.** Начат 2026-10-26 вместе с пользователем по [runbook](../operations.md#runbook-w-29); запуск разрешён
пользователем. Секреты (ключи Stripe, пароли БД, почты, администратора) вводятся на сервере через `read -rs` и в отчёт
не попадают. Приём реальных заказов не открыт: нет внешней копии и проверки восстановления (стоп-лист §9.5).

## Решения W-23 (26.10.2026)

Записаны в [decisions.md](../../../docs/planning/edition-2/decisions.md#w-23-решения-рабочего-запуска-26102026).

| ID | Решение |
|---|---|
| D-01 | VPS Hostinger (дата-центр — Германия) вместо предложенного Hetzner; Ubuntu 24.04.5 LTS, 4 vCPU, 15 ГБ RAM, 193 ГБ диска; Docker Compose проекта `aiqme-prod` |
| D-02, D-03 | По умолчанию: PostgreSQL 17 в compose, очередь `database`, один `worker`, `scheduler` |
| D-04, D-05, D-22, D-23, D-34 | Варианты по умолчанию подтверждены пользователем **без ответа бухгалтера**; меняются, если бухгалтер скажет иначе. Реквизиты — из публичного реестра (AIQME, UAB, плательщик VAT), ставка 21 %, серия `AIQ`, первый номер `1` |
| D-13, D-31 | 14 дневных + 8 недельных; cron `bin/backup` и внешняя копия — отложены пользователем |
| D-15 | Почта домена — Hostinger Email (MX, SPF `include:_spf.mail.hostinger.com`, DKIM `hostingermail-a/b/c`, DMARC `p=none` появились в зоне 26.10); отправка — SMTP `smtp.hostinger.com:465`; ящик (info@ или отдельный для сайта) — выбирает заказчик |
| D-27 | Готовых текстов у заказчика нет (на прежнем сайте aiqme.eu — только окно согласия cookies с аналитикой и рекламой, к новому сайту не подходит). Заказчик публикует политики сам через бота или админку («Тексты страниц»); до этого — заготовки D-11. Риск: персональные данные собираются до публикации политики конфиденциальности — тексты нужны до первых реальных заказов |
| D-35 | Caddy из официального репозитория (2.11.7), без журнала доступа, `request_body max_size 260MB` |
| D-42 | Заказчик включает Apple Pay и Google Pay; регистрация домена в Stripe — после переключения aiqme.com |

## Сервер (этапы 1–2)

| Пункт | Результат |
|---|---|
| Защита хоста (D-01) | SSH только по ключу (`passwordauthentication no`, `kbdinteractiveauthentication no`, `permitrootlogin without-password`); UFW: deny incoming, разрешены 22, 80, 443; `unattended-upgrades` активен (`Unattended-Upgrade "1"`) |
| Программы | Docker 29.9.2, Compose v5.6.1, Caddy 2.11.7, Python 3.12.3 |
| DNS aiqme.com «до» | NS Hostinger (`dns-parking.com`); `A @` → `2.57.91.91` (парковка Hostinger, TTL 50), `CNAME www` → `aiqme.com` (TTL 300); AAAA и CAA нет; почтовые записи — см. D-15. Экспорт зоны 27.10 хранится у пользователя (вне репозитория) |
| Переключение 27.10 | изменена только `A @` → `187.126.17.111`; Caddy: `aiqme.com` — заглушка 503 с `Retry-After` до рабочей установки, `www` → 301 на `aiqme.com`; сертификаты Let's Encrypt для обоих имён; MX и SPF снаружи не изменились. Откат — вернуть `2.57.91.91` |

## Staging (этап 3)

Код — `e3a5497` (= `origin/main`): клон в `/srv/aiqme/repo`, `git archive … aiqme-site` без `experiments/` в
`/srv/aiqme/aiqme-site`. Адрес — `https://187-126-17-111.sslip.io` (сертификат Let's Encrypt; `staging.aiqme.com` не
понадобился). Ключи Stripe — test; `MAIL_MAILER=log` до подключения почты. БД staging временная: после проверок —
`down -v` и чистая установка для aiqme.com.

| Шаг | Результат |
|---|---|
| `build`, `up -d --wait` | образы собраны; `web` unhealthy из-за испорченного `.env` (ниже), после исправления — `/up` 200 |
| `migrate --force`, `aiqme:bootstrap` | все миграции DONE; базовые данные Lunar готовы |
| `TRUSTED_PROXIES` | шлюз `aiqme-prod_default` — `172.18.0.1`, `config:show` подтверждает |
| Сидер, администратор | `PrototypeContentSeeder` выполнен; временный администратор |
| Почта (D-15) | ящик `noreply@aiqme.com` в Hostinger Email; `MAIL_MAILER=smtp`, `smtp.hostinger.com:465`, `smtps`, From = `noreply@aiqme.com`; сброс пароля на внешний gmail — письмо доставлено 27.10. Уведомления о формах и заявках — на `info@aiqme.com` (адрес в `config/aiqme.php`, не в `.env`), Reply-To — клиент; на staging тестовые заявки тоже уходят на info@ |
| Google Таблица | тестовая таблица и сервисный аккаунт W-22; `aiqme:sheets-rebuild` перестроил листы по БД staging (строки W-22 из локальной БД исчезли — ожидаемо); контактная форма → новые строки в листах таблицы с сервера |
| Telegram | тестовый бот W-22; `TELEGRAM_WEBHOOK_SECRET` — `openssl rand -hex 32` на сервере; `aiqme:telegram-webhook`; бот отвечает через webhook (опроса на сервере нет); черновик проекта → Published LT → `/lt/projektai/hilisao` 200, EN 404 |
| Реквизиты, заказ, счёт | реквизиты продавца введены в админке (Settings → Invoice details); заявка из калькулятора → платёжный запрос → оплата тестовой картой по гостевой ссылке → Check status → PDF-счёт `AIQ` выпущен; в таблице появились строки Orders, Payments, Events. PDF вышел на английском: язык счёта берётся из языка заявки, а не из страницы скачивания; счёт заказа 2 (заявка с LT) — на литовском, литовские буквы и реквизиты в PDF в порядке |
| Обновление и откат (§8.3) | 28.10: на сервере уже был `68af859` (калькулятор: своя кнопка файла, 50 МиБ, разбор STL по одному; миграций с `e3a5497` нет); Caddy `max_size 510MB`. Выкладка — `git archive <версия>` во временный каталог → `rsync -a --delete --exclude=.env` в `/srv/aiqme/aiqme-site` → `build`, `up -d --wait`, `migrate --force`, `aiqme:bootstrap`, `optimize`, `restart worker scheduler`. Откат на `e3a5497` (`512M 25M`, старый калькулятор, заказ 2 и счёт на месте) и возврат на `68af859` → `/up` 200, `768M 50M` |
| Снаружи | `/up` 200, `/` → 301 `/lt`, `/lt/` и `/en/` 200, CSP и `X-Robots-Tag: noindex` есть, `/.env` 403, `/lunar` 404 |

### Stripe test на staging (27.10)

- Stripe test-webhook на staging проверен 27.10 (заказ 2, 30,39 EUR): оплата через webhook (Resend) → 200; частичный
  возврат 5,00 в Dashboard → `charge.refunded` 200, долг 5,00; запрос на 5,00 при отключённом webhook, оплата, Cancel
  request в необновлённой карточке → запрос `Paid`, не отменён (ветка `payment_intent_unexpected_state`). Повтор события
  после включения webhook не проверялся: Stripe не доставлял его отключённому endpoint, Resend нет (защита от повтора —
  W-22). Возврат с причиной *Fraudulent* внёс тестовую карту в Radar `card_fingerprint_blocklist` (оплаты отклонялись),
  запись удалена — сказать заказчику: для обычных возвратов эту причину не выбирать.
- Отказ карты (`4000 0000 0000 0002`) на staging не повторялся (проверен в W-22). Dashboard не
  предлагает `2025-02-24.acacia` из плана — только текущую версию аккаунта `2026-09-30.endive` и preview; выбрана
  `2026-09-30.endive` (код требует не старее `2022-11-15`, `StripeWebhook::MINIMUM_API_VERSION`; на ней прошли проверки W-22).
  Первая доставка `payment_intent.succeeded` (заказ 2) — **400**: при `APP_ENV=production` контроллер принимает только
  `livemode=true`; staging переведён на `APP_ENV=staging` (влияет только на проверку livemode и запрет `_live_`-ключей,
  `APP_DEBUG=false`), Resend → 200. На проде — снова `production` с live-ключами.
## Рабочая установка aiqme.com (этап 4, 28.10)

| Шаг | Результат |
|---|---|
| Staging | тестовый webhook в Stripe отключён; `$C down -v` — тома `aiqme-prod` удалены |
| `.env` | `APP_ENV=production`, `APP_URL=https://aiqme.com`, новый `APP_KEY`, новый `TELEGRAM_WEBHOOK_SECRET`; live `pk_live`/`rk_live` перенесены с компьютера пользователя через ssh без вывода; почта — прежняя (`noreply@`) |
| Установка | `68af859`; `up -d --wait`, `migrate --force`, `aiqme:bootstrap`, сидер, `aiqme:make-admin info@aiqme.com` (пароль через `read -rs`), `optimize`; `TRUSTED_PROXIES` = шлюз |
| Caddy | `aiqme.com` → `reverse_proxy 127.0.0.1:8080`, `max_size 510MB`, `X-Robots-Tag: noindex, nofollow` до открытия; `www` → 301; блок sslip.io убран; заглушка — `/etc/caddy/Caddyfile.stub`. `/up` 200, `/` → 301 `/lt` |
| Google Таблица | новая таблица (`1UH5q…`), тот же сервисный аккаунт `aiqme-sheets-test@aiqme-test`; пять листов вручную → `sheets-rebuild` → заголовки; контактная форма → строка Contacts |
| Telegram | тестовый бот; `aiqme:telegram-webhook` → `https://aiqme.com/webhooks/telegram`; привязка кодом из Integrations; публикация черновика → виден на `/lt/projektai`; удаления контента нет (только Unpublish) |
| Stripe live | webhook `https://aiqme.com/webhooks/stripe` (три события, текущая версия аккаунта), секрет через `read -rs`; неподписанный POST → 400 (маршрут и секрет на месте; доставка Stripe подтвердится первой оплатой); Payment method domains — `aiqme.com` (D-42), статусы Apple Pay и Google Pay — по словам пользователя в порядке |
| Админка | реквизиты продавца и ссылка на таблицу введены заново (БД новая) |

## Ждёт проверки

- Telegram-бот заказчика (сейчас тестовый бот W-22), сервисный аккаунт Google — в проект заказчика.
- Контрольная live-оплата (по решению пользователя), HSTS, внешний мониторинг `/up`, SPF/DKIM/DMARC по заголовкам письма.
- **До приёма реальных заказов (стоп-лист §9.5):** бэкап, внешняя копия, проверенное восстановление; политики (D-27);
  затем снять `X-Robots-Tag: noindex` в Caddy: на сервере `/root/aiqme-open-indexing` (напоминание при входе по SSH;
  копия Caddyfile — `/etc/caddy/Caddyfile.noindex`).

## Замечания

- Строка вставленного блока команд попала в скрытый `read -rs` и записалась в `STRIPE_KEY` → «The environment file is
  invalid», `worker`/`scheduler` в перезапуске. Ввод секрета — отдельной командой; проверка — префиксы через `grep -oE`.
- `aiqme:make-admin` принял адрес `ваш@email` (правило `email` мягче браузерной проверки), войти с ним нельзя; на staging
  адрес исправлен в БД. Стоит ужесточить проверку.
- В счёте нет банка и IBAN (в Settings таких полей нет), хотя чек-лист заказчика их запрашивал — добавить, если нужно бухгалтеру.
- Live restricted key заказчик переслал в переписке; рекомендован новый ключ при переходе на live (решение — за пользователем).
- Новая таблица прода (28.10): rebuild упал с «Unable to parse range: Orders!A:Q» — листы сайт не создаёт; пять листов
  создаются вручную (добавлено в operations.md). Сервисный аккаунт прода пока из Google-проекта пользователя `aiqme-test` —
  перенести в проект заказчика.
