<?php

namespace App\Providers;

use App\Admin\ManufacturingOrderExtension;
use App\Models\ProductionState;
use App\Models\StlPart;
use App\Services\RequestCartValidator;
use Comparison\Bindings;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Facades\Telemetry;
use Lunar\Models\Order;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Bindings::register($this->app);
        LunarPanel::register();
    }

    public function boot(): void
    {
        View::addNamespace('comparison', base_path('../../orders-comparison/shared/views'));
        config(['lunar.cart.validators.order_create' => [RequestCartValidator::class]]);
        Telemetry::optOut();
        LunarPanel::extensions([ManageOrder::class => ManufacturingOrderExtension::class]);
        Relation::morphMap(['stl-part' => StlPart::class]);
        Order::resolveRelationUsing('production', fn (Order $order) => $order->hasOne(ProductionState::class, 'order_id'));
        config(['lunar.orders.statuses' => [
            'submitted' => ['label' => 'Submitted', 'color' => '#777777', 'mailers' => [], 'notifications' => []],
            'confirmed' => ['label' => 'Price confirmed', 'color' => '#2266aa', 'mailers' => [], 'notifications' => []],
            'cancelled' => ['label' => 'Cancelled', 'color' => '#aa3333', 'mailers' => [], 'notifications' => []],
        ]]);
    }
}
