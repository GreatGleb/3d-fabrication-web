# AIQME — новый сайт

Здесь будет серверное приложение по [плану, редакция 2](../docs/planning/edition-2/README.md): Laravel 13 + Lunar 1.5, Blade/Livewire, JS-модули для STL и анимаций. **Реализация не начата**; задачи — [W-01…W-24](../docs/planning/edition-2/tasks.md), первая — каркас приложения в этом каталоге.

- Эталон внешнего вида и сценариев — [прототип заказчика](../docs/customer-prototype/README.md).
- Требования и решения — [docs/requirements.md](../docs/requirements.md).
- `experiments/` — проверенный эксперимент сравнения заказов с Lunar и без него (бывший `prototypes/`). Три каталога `lunar-aiqme/`, `laravel-orders-aiqme/`, `orders-comparison/` связаны относительными путями и должны оставаться соседями. Это не часть приложения; не изменять. [Запуск и тесты](experiments/orders-comparison/README.md), [вывод](../docs/research/lunar-vs-laravel-comparison-20261017.md).

Демо-сборка для GitHub Pages (только фронтенд, пометка «демо») запланирована задачей [W-24](../docs/planning/edition-2/tasks.md#w-24) в `demo/` (по умолчанию https://greatgleb.github.io/3d-fabrication-web/aiqme-site/demo/, [D-16](../docs/planning/edition-2/decisions.md#d-16)); пока не создана и не опубликована.

Отдельный `git init` здесь не нужен: весь корень рабочего пространства публикуется в один публичный репозиторий `GreatGleb/3d-fabrication-web`; приватное исключено корневым `.gitignore`. Секреты (`.env`) в Git не попадают.
