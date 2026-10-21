<?php

namespace App\Http\Controllers\Account;

use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\StlFile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lunar\Models\Order;

/**
 * Кабинет клиента (§1.3): вкладки прототипа Overview, Orders, Saved models, Addresses & company, Invoices. Гостю на любой
 * вкладке — форма входа (200, §4.6). Клиент видит только свои заказы (user_id заказа) и модели — STL своих заказов без
 * скрытых (W-15); скрытие файл не удаляет. Invoices — выпущенные счета своих заказов со скачиванием PDF (W-17).
 */
class AccountController extends Controller
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function overview(Request $request): View
    {
        return $this->tab($request, 'overview', fn (User $user): array => [
            'ordersCount' => $this->ownOrders($user)->count(),
            'modelsCount' => $this->ownModels($user)->count(),
            'orders' => $this->rows($this->ownOrders($user)->limit(1)->get()),
        ]);
    }

    public function orders(Request $request): View
    {
        return $this->tab($request, 'orders', fn (User $user): array => ['orders' => $this->rows($this->ownOrders($user)->get())]);
    }

    public function models(Request $request): View
    {
        return $this->tab($request, 'models', fn (User $user): array => [
            'models' => $this->ownModels($user)->latest('id')->get(),
            'numbers' => OrderMeta::whereIn('order_id', $this->ownOrders($user)->select('id'))->pluck('number', 'order_id'),
        ]);
    }

    /** Скрыть модель из Saved models: только своя модель своего заказа, иначе 404; файл и строка заказа остаются. */
    public function hideModel(Request $request, int $stlFile): RedirectResponse
    {
        $this->ownModels($request->user())->findOrFail($stlFile)->update(['hidden_at' => now()]);

        return redirect()->route(app()->getLocale().'.account.models')->with('status', __('account.models.hidden'));
    }

    public function invoices(Request $request): View
    {
        // Демо-сборка (§8.7): блока счетов нет.
        return $this->tab($request, 'invoices', fn (User $user): array => [
            'invoices' => app()->environment('demo') ? collect() : Invoice::whereIn('order_id', $this->ownOrders($user)->select('id'))->latest('id')->get(),
            'numbers' => OrderMeta::whereIn('order_id', $this->ownOrders($user)->select('id'))->pluck('number', 'order_id'),
        ]);
    }

    public function addresses(Request $request): View
    {
        return $this->tab($request, 'addresses', fn (User $user): array => ['profile' => $user->profile ?? new CustomerProfile]);
    }

    public function updateAddresses(Request $request): RedirectResponse
    {
        $rules = array_fill_keys(CustomerProfile::FIELDS, ['nullable', 'string', 'max:255']);
        // Форма отправляет весь профиль: незаполненное поле очищается.
        $data = [...array_fill_keys(CustomerProfile::FIELDS, null), ...$request->validate($rules, ['max' => __('account.messages.max')])];

        $request->user()->profile()->updateOrCreate([], $data);

        return redirect()->route(app()->getLocale().'.account.addresses')->with('status', __('account.addresses.saved'));
    }

    /** @param  callable(User): array<string, mixed>  $data */
    private function tab(Request $request, string $tab, callable $data): View
    {
        if (! $request->user()) {
            return view('account.auth', ['mode' => 'login']);
        }

        return view('account.index', ['tab' => $tab, ...$data($request->user())]);
    }

    /** @return Builder<Order> размещённые заказы клиента, новые сверху */
    private function ownOrders(User $user): Builder
    {
        return Order::where('user_id', $user->id)->whereNotNull('placed_at')->latest('id');
    }

    /** @return Builder<StlFile> модели Saved models: STL заказов клиента, не скрытые */
    private function ownModels(User $user): Builder
    {
        return StlFile::where('user_id', $user->id)->whereIn('order_id', $this->ownOrders($user)->select('id'))->whereNull('hidden_at');
    }

    /**
     * Строки истории заказов (§1.3): номер, дата, стадия производства, состояние оплаты, сумма.
     *
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array{number: int, date: string, stage: string, payment_state: string, total: int}>
     */
    private function rows(Collection $orders): Collection
    {
        $numbers = OrderMeta::whereIn('order_id', $orders->modelKeys())->pluck('number', 'order_id');
        $stages = ProductionState::whereIn('order_id', $orders->modelKeys())->pluck('stage', 'order_id');

        return $orders->map(fn (Order $order): array => [
            'number' => $numbers[$order->id],
            'date' => $order->placed_at->timezone(config('app.timezone'))->format('Y-m-d'),
            'stage' => $stages[$order->id]->value,
            'payment_state' => $this->workflow->balance($order)['payment_state'],
            'total' => $order->total->value,
        ]);
    }
}
