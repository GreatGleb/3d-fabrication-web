<?php

namespace Comparison\Tests;

use App\Models\User;
use App\Services\BasketService;
use Comparison\ReadModel;
use Illuminate\Http\UploadedFile;
use Tests\WorkflowTestCase;

class BasketAndScreensTest extends WorkflowTestCase
{
    private function item(string $name = 'bracket', string $material = 'PLA'): array
    {
        return ['file' => new UploadedFile(base_path('fixtures/'.$name.'.stl'), $name.'.stl', 'application/octet-stream', null, true), 'parameters' => ['material' => $material, 'layer_mm' => 0.2, 'quantity' => 1]];
    }

    public function test_real_basket_add_update_remove_recalculate_and_submit_are_persistent(): void
    {
        $service = app(BasketService::class);
        $basket = $service->open($this->buyer);
        $service->add($this->buyer, $basket->id, $this->item());
        $service->add($this->buyer, $basket->id, $this->item('spacer', 'PETG'));
        $view = $service->view($this->buyer, $basket->id);
        $this->assertSame(12000, $view['total']);
        $service->change($this->buyer, $basket->id, $view['lines'][0]['id'], 3);
        $this->assertSame(22000, $service->view($this->buyer, $basket->id)['total']);
        $service->remove($this->buyer, $basket->id, $view['lines'][1]['id']);
        $this->assertSame(15000, $service->view($this->buyer, $basket->id)['total']);
        $order = $service->submit($this->buyer, $basket->id);
        $this->assertSame(15000, ReadModel::cents($order->total));
        $this->assertSame(3, $order->lines[0]->quantity);
        $this->assertSame(3, $order->lines[0]->meta['parameters']['quantity']);
        $this->assertSame(0, $this->flow->balance($order)['paid']);
        $this->assertSame($order->id, $service->submit($this->buyer, $basket->id)->id);
    }

    public function test_submitted_basket_cannot_change_quantity_or_remove_files(): void
    {
        $service = app(BasketService::class);
        $basket = $service->open($this->buyer);
        $service->add($this->buyer, $basket->id, $this->item());
        $id = $service->view($this->buyer, $basket->id)['lines'][0]['id'];
        $service->submit($this->buyer, $basket->id);
        $this->actingAs($this->buyer)->postJson(route('comparison.change', [$basket->id, $id]), ['quantity' => 2])->assertUnprocessable();
        $this->deleteJson(route('comparison.remove', [$basket->id, $id]))->assertUnprocessable();
    }

    public function test_http_upload_multiple_stl_and_submit_without_payment(): void
    {
        $this->actingAs($this->buyer)->post(route('comparison.create'))->assertRedirect();
        $basket = app(BasketService::class)->open($this->buyer);
        foreach ([$this->item(), $this->item('spacer', 'PETG')] as $item) {
            $this->post(route('comparison.add', $basket->id), ['file' => $item['file'], ...$item['parameters']])->assertRedirect();
        }
        $this->get(route('comparison.basket', $basket->id))->assertOk()->assertSee('12000')->assertSee('bracket.stl')->assertSee('spacer.stl');
        $this->post(route('comparison.submit', $basket->id))->assertRedirect();
        $this->assertSame(1, ReadModel::orderClass()::count());
    }

    public function test_other_buyer_cannot_read_edit_submit_or_attach_to_private_basket(): void
    {
        $service = app(BasketService::class);
        $basket = $service->open($this->buyer);
        $service->add($this->buyer, $basket->id, $this->item());
        $id = $service->view($this->buyer, $basket->id)['lines'][0]['id'];
        $this->actingAs(User::factory()->create())->get(route('comparison.basket', $basket->id))->assertForbidden();
        $this->postJson(route('comparison.change', [$basket->id, $id]), ['quantity' => 2])->assertForbidden();
        $this->postJson(route('comparison.submit', $basket->id))->assertForbidden();
        $item = $this->item();
        $this->postJson(route('comparison.add', $basket->id), ['file' => $item['file'], ...$item['parameters']])->assertForbidden();
    }

    public function test_empty_basket_and_bad_quantity_are_rejected(): void
    {
        $basket = app(BasketService::class)->open($this->buyer);
        $this->actingAs($this->buyer)->postJson(route('comparison.submit', $basket->id))->assertUnprocessable();
        $this->postJson(route('comparison.change', [$basket->id, 999]), ['quantity' => 0])->assertUnprocessable();
    }

    public function test_admin_order_search_and_customer_lists_and_detail_work_in_both_apps(): void
    {
        $order = $this->confirmed();
        $this->actingAs($this->admin)->get(route('comparison.orders'))->assertOk()->assertSee($order->reference)->assertSee('15000');
        $this->get(route('comparison.orders', ['q' => 'absent-reference']))->assertOk()->assertDontSee($order->reference);
        $this->get(route('comparison.customers'))->assertOk()->assertSee('buyer@example.test');
        $this->get(route('probe.order', $order))->assertOk()->assertSee('Confirm agreed prices')->assertSee('Start production');
        $this->actingAs($this->buyer)->get(route('comparison.customers'))->assertForbidden();
    }

    public function test_order_list_does_not_expose_another_buyers_order(): void
    {
        $order = $this->submitted();
        $this->actingAs(User::factory()->create())->get(route('comparison.orders'))->assertOk()->assertDontSee($order->reference);
    }
}
