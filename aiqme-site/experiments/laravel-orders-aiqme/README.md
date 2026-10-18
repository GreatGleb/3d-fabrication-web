# AIQME без Lunar

Рабочий изолированный вариант собственных заказов Laravel. **Lunar и Filament отсутствуют в composer.lock и vendor.**

Laravel 13.37.0, Livewire 3.8.10, Stripe SDK 16.6.0; точные зависимости — [composer.lock](app/composer.lock). Модели заказа, позиций, транзакций и корзины находятся в [app/Models](app/app/Models/); правила — [OrderWorkflow](app/app/Services/OrderWorkflow.php) и [BasketService](app/app/Services/BasketService.php).

[Запуск и одинаковые тесты двух вариантов](../orders-comparison/README.md) · [Отчёт](../../../docs/research/lunar-vs-laravel-comparison-20261017.md).

Порт 8858; только отдельные синтетические БД aiqme_plain_comparison и aiqme_plain_comparison_test. Интерфейс, загрузка и SDK-адаптер используются из соседнего `orders-comparison/shared/`, PHP runtime — из соседнего `lunar-aiqme/runtime/`. Это общие ресурсы стенда, не зависимость plain-приложения от пакета Lunar.
