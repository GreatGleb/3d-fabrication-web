# Сравнение заказов AIQME с Lunar и без него

Два реально работающих приложения, один физический набор тестов, одинаковый интерфейс и Stripe-адаптер. [Вывод и методика](../../../docs/research/lunar-vs-laravel-comparison-20261017.md). Сравнение не является полной реализацией сайта.

- `../lunar-aiqme/app/` — Laravel 13.37 + Lunar 1.5 + Livewire 3.8.10.
- `../laravel-orders-aiqme/app/` — Laravel 13.37 + Livewire 3.8.10 + Stripe SDK 16.6, **без Lunar/Filament**.
- `shared/` — общий интерфейс, intake STL, SDK-адаптер, CLI и приёмочные тесты. Общие исходники намеренно не копируются.
- `evidence/` — JUnit, результаты, screenshots, нормализованные трассы, состав кода/зависимостей и схем.

## Запуск

Из корня проекта, на проверенном Ubuntu amd64 с PHP 8.4, Composer, Docker, Python 3, rg, apt/dpkg-deb:

```bash
./aiqme-site/experiments/orders-comparison/bin/setup.sh
python3 aiqme-site/experiments/orders-comparison/bin/compare.py
```

Скрипт setup повторно применим: использует существующий изолированный PHP runtime, создаёт/запускает только помеченный контейнер `aiqme-lunar-probe-postgres` и четыре **новые синтетические БД**. Проверяет допустимые имена до миграций. Не очищает demo-БД; тесты используют RefreshDatabase только в двух БД с суффиксом `_test`. Оригинальные `aiqme_probe`, `aiqme_probe_test` сохранены и не используются новым runner. `legacy-landing/instance/` рабочего пространства не читается.

Для demo (каждый запуск создаёт новый заказ):

```bash
cd aiqme-site/experiments/lunar-aiqme/app
../bin/php artisan comparison:demo --stage=paid
../bin/php artisan comparison:demo
cd ../../laravel-orders-aiqme/app
../bin/php artisan comparison:demo --stage=paid
../bin/php artisan comparison:demo
```

Для интерфейса в двух терминалах из корня:

```bash
./aiqme-site/experiments/lunar-aiqme/bin/serve.sh
```

```bash
./aiqme-site/experiments/laravel-orders-aiqme/bin/serve.sh
```

Адреса: http://127.0.0.1:8857 и http://127.0.0.1:8858. Покупатель `buyer@example.test`, администратор `admin@example.test`, пароль обоих `local-probe-only`. После входа открывается список доступных заказов. Можно создать корзину, загрузить STL из `app/fixtures/`, изменить количество, удалить позицию и отправить заявку без оплаты. Войти администратором можно в новой приватной браузерной сессии.

Штатная панель Lunar дополнительно доступна на первом адресе `/lunar/login`, те же реквизиты администратора. На втором адресе панели Filament нет. Собственный интерфейс у двух приложений общий. Demo-цены 50/70 EUR — фиксированные серверные fixtures; исходный калькулятор сайта не переносился. Платёжные запросы в UI пока возвращают JSON; это проверка операций, не финальная пользовательская вёрстка.

## Автоматические проверки

```bash
python3 aiqme-site/experiments/orders-comparison/bin/compare.py
python3 aiqme-site/experiments/orders-comparison/bin/metrics.py
```

38 одинаковых тестов / 144 assertions в каждом варианте. Тестовые секреты Stripe не нужны: SDK-интерфейс замокан, webhook подписан локальной fixture-строкой и проверяется настоящим Stripe SDK. Внешние API не вызываются, live-события отвергаются. Это не подтверждение работоспособности Stripe-аккаунта, Elements/3DS или сетевой доставки webhook.

Регрессия штатного Lunar отдельно:

```bash
cd aiqme-site/experiments/lunar-aiqme/app
../bin/php artisan test --compact tests/Feature
```

35 тестов / 117 assertions. Два разных набора проверяют разные вещи; общий контракт запускается через `compare.py`.

С работающими двумя HTTP-серверами и установленным Playwright/bundled Chromium:

```bash
python3 aiqme-site/experiments/orders-comparison/bin/browser_check.py
```

Браузерный runner использует отдельный Chromium, не пользовательский Chrome. Создаёт новые синтетические корзины/заказы, проверяет реальную загрузку, отправку, админское подтверждение, производство без оплаты, частичный долг и скачивание STL. Сохраняет screenshots. Не подключает внешние оплаты.

## Изоляция и зависимости

Точные пакеты зафиксированы отдельными `composer.lock` в обоих приложениях; стандартные `package-lock.json` сохранены, для этих Blade-экранов npm-сборка не нужна. Общий каталог `shared/` подключён PSR-4 `Comparison\` из обоих composer.json. Для переноса нужно сохранять все три соседних каталога прототипов вместе; runtime общий и изолирован от системной установки PHP.

БД: `aiqme_lunar_comparison`, `aiqme_lunar_comparison_test`, `aiqme_plain_comparison`, `aiqme_plain_comparison_test`; PostgreSQL на `127.0.0.1:55439`. Fixtures и приватные файлы каждого приложения находятся отдельно. `.env` не хранит реальных ключей. Boost установлен для Codex в обоих приложениях; setup пересоздаёт только их проектные `.codex/config.toml`, не глобальные настройки и не корневой AGENTS.md.

Остановить собственные HTTP-серверы: Ctrl+C в соответствующем терминале. Остановить только созданный контейнер:

```bash
docker stop aiqme-lunar-probe-postgres
```

Данные сохраняются в Docker volume. Setup не удаляет контейнеры/тома, сайт не публикует. Исторические логи `*-first.*` исключены из сравнения из-за исправленной ошибки стенда; объяснение — `evidence/harness-correction.json`.
