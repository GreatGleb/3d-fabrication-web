<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Models\Staff;
use Lunar\Admin\Support\Facades\LunarPanel;
use Tests\WorkflowTestCase;

class LunarAdminTest extends WorkflowTestCase
{
    public function test_lunar_login_and_order_and_customer_resources_render_for_admin(): void
    {
        $order = $this->confirmed();
        $this->get('/lunar/login')->assertOk();
        $this->actingAs(Staff::firstOrFail(), 'staff');
        $this->get('/lunar/orders')->assertOk();
        $this->get('/lunar/customers')->assertOk();
        $this->get('/lunar/orders/'.$order->id)->assertOk()->assertSee('AIQME manufacturing')->assertSee('15000');
    }

    public function test_documented_admin_actions_call_shared_workflow(): void
    {
        $order = $this->confirmed();
        $this->actingAs(Staff::firstOrFail(), 'staff');
        Filament::setCurrentPanel(LunarPanel::getPanel());
        Livewire::test(ManageOrder::class, ['record' => $order->id])
            ->assertSee('AIQME manufacturing')
            ->callAction('requestPayment', ['amount' => 4000])
            ->assertHasNoActionErrors()
            ->callAction('startProduction')->assertHasNoActionErrors();
        $this->assertDatabaseHas('payment_requests', ['order_id' => $order->id, 'amount' => 4000]);
        $this->assertSame('in-production', $order->fresh()->production->state);
        $this->assertSame(0, $this->flow->balance($order)['paid']);
    }

    public function test_admin_confirms_price_and_downloads_private_stl_through_native_panel(): void
    {
        $order = $this->submitted();
        $part = $order->lines[0]->purchasable;
        $this->get(route('probe.staff-file', $part))->assertRedirect();
        $this->actingAs(Staff::firstOrFail(), 'staff');
        Filament::setCurrentPanel(LunarPanel::getPanel());
        $prices = $order->lines->mapWithKeys(fn ($line) => ['p_'.$line->id => 8000])->all();
        Livewire::test(ManageOrder::class, ['record' => $order->id])
            ->callAction('confirmPrice', [...$prices, 'reason' => 'Reviewed in panel', 'agreement' => 'synthetic-panel-consent'])
            ->assertHasNoActionErrors();
        $this->assertSame(16000, $order->fresh()->total->value);
        $this->get(route('probe.staff-file', $part))->assertDownload('bracket.stl');
    }
}
