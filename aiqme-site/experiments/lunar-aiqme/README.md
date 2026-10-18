# AIQME Lunar prototype

Изолированный рабочий прототип Laravel + Lunar. После первого исследования дополнен для **сопоставимого сравнения с приложением без Lunar**: основной путь теперь проходит через настоящую корзину Lunar и штатное создание заказа.

[Запуск, сравнение и текущие результаты](../orders-comparison/README.md) · [Текущий вывод](../../../docs/research/lunar-vs-laravel-comparison-20261017.md) · [Исторический первый отчёт](../../../docs/research/lunar-evaluation-20261017.md).

Версии: PHP 8.4.24; Laravel 13.37.0; Lunar core/admin/Stripe 1.5.0; Livewire 3.8.10; Filament 4.15.2; Boost 2.10.3. Точные зависимости — [composer.lock](app/composer.lock). Ничего не подключено к основному сайту.

Корзина — [BasketService](app/app/Services/BasketService.php), приватная модель — [StlPart](app/app/Models/StlPart.php), прикладные правила — [OrderWorkflow](app/app/Services/OrderWorkflow.php). Заявка создаётся через Cart.createOrder с [валидатором предварительной заявки](app/app/Services/RequestCartValidator.php), без фиктивного платёжного адреса. Суммы и транзакции хранятся в Lunar; состояние производства связано отдельно.

Порт 8857, новые БД aiqme_lunar_comparison/aiqme_lunar_comparison_test. Старые aiqme_probe/aiqme_probe_test и исходные логи в evidence сохранены. `bin/setup.sh` теперь запускает общий setup стенда; он сохраняет исходные данные и создаёт четыре сравнительные БД.

38 общих приёмочных тестов / 144 assertions; дополнительно 35 прежних Lunar-тестов / 117 assertions. Реальные платежи не выполняются. Общий тестируемый SDK-адаптер находится в соседнем `orders-comparison/shared/Payments/StripeInstallments.php`; штатный Stripe-addon сохранён для проверок ограничений, его checkout не используется общим сценарием.

Готовая панель `/lunar/login` и [расширение Filament](app/app/Admin/ManufacturingOrderExtension.php) сохранены. Покупатель buyer@example.test, администратор admin@example.test, синтетический пароль local-probe-only. User и штатный Lunar Staff представляют одного администратора в двух guard-моделях.

Boost настроен для Codex проектным `.codex/config.toml`; используется `bin/php` с изолированными расширениями. Корневой AGENTS.md не изменён, глобальные настройки Codex не затронуты.
