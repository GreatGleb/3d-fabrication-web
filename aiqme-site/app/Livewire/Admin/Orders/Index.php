<?php

namespace App\Livewire\Admin\Orders;

use App\Domain\Orders\ProductionStage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Список заказов вкладки Orders (§1.4, W-14): поиск по номеру (начало номера) и фильтр по стадии, последние 100 заказов;
 * «Valdyti» · «Manage» открывает карточку под списком (?order=<id>). Без массовых операций и CSV. Каждый запрос — только
 * администратору, иначе 404.
 */
class Index extends Component
{
    public const LIMIT = 100;

    #[Locked]
    public string $uiLocale;

    public string $search = '';

    public string $stage = '';

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(): void
    {
        $this->uiLocale = app()->getLocale();
    }

    /** Строки списка заказов (номер, дата, стадия, сумма в центах) — новые сверху; общие с Overview. */
    public static function rows(): Builder
    {
        return DB::table('lunar_orders')
            ->join('order_meta', 'order_meta.order_id', '=', 'lunar_orders.id')
            ->join('production_states', 'production_states.order_id', '=', 'lunar_orders.id')
            ->select('lunar_orders.id', 'order_meta.number', 'lunar_orders.created_at', 'production_states.stage', 'lunar_orders.total')
            ->orderByDesc('lunar_orders.id');
    }

    public function render(): View
    {
        $digits = preg_replace('/\D/', '', $this->search);
        $stage = ProductionStage::tryFrom($this->stage);

        return view('admin.orders-index', [
            'orders' => self::rows()
                ->when($digits !== '', fn (Builder $query) => $query->whereRaw('CAST(order_meta.number AS TEXT) LIKE ?', [$digits.'%']))
                ->when($stage !== null, fn (Builder $query) => $query->where('production_states.stage', $stage->value))
                ->limit(self::LIMIT)
                ->get(),
        ]);
    }
}
