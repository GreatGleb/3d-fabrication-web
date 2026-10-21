<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Orders\Index;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Вкладка Customers (§1.4, W-14): зарегистрированные клиенты (без администраторов) с числом заказов, карточка клиента
 * (?customer=<id>: контакты, «Addresses & company details» из customer_profiles и заказы) и гостевые контакты — контакты
 * заявок гостевых заказов. Последние 100 записей; без выгрузки CSV (вместо неё — ссылка на Google Таблицу, W-18).
 * Только администратору, иначе 404.
 */
class Customers extends Component
{
    public const LIMIT = 100;

    #[Locked]
    public string $uiLocale;

    #[Locked]
    public ?int $customerId = null;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(?int $customerId = null): void
    {
        $this->uiLocale = app()->getLocale();
        $this->customerId = $customerId === null ? null : User::where('is_admin', false)->findOrFail($customerId)->id;
    }

    public function render(): View
    {
        $customer = $this->customerId === null ? null : User::with('profile')->where('is_admin', false)->findOrFail($this->customerId);
        $orderCounts = DB::table('lunar_orders')->whereNotNull('user_id')->groupBy('user_id')->selectRaw('user_id, count(*) as orders');

        return view('admin.customers-panel', [
            'customer' => $customer,
            'customerOrders' => $customer === null ? collect() : Index::rows()->where('lunar_orders.user_id', $customer->id)->get(),
            'customers' => User::where('is_admin', false)
                ->leftJoinSub($orderCounts, 'counts', 'counts.user_id', '=', 'users.id')
                ->select('users.*', DB::raw('coalesce(counts.orders, 0) as orders'))
                ->orderByDesc('users.id')->limit(self::LIMIT)->get(),
            'guests' => DB::table('order_meta')
                ->join('lunar_orders', 'lunar_orders.id', '=', 'order_meta.order_id')
                ->whereNull('lunar_orders.user_id')
                ->select('lunar_orders.id', 'order_meta.number', 'order_meta.contact', 'lunar_orders.created_at')
                ->orderByDesc('lunar_orders.id')->limit(self::LIMIT)->get(),
        ]);
    }
}
