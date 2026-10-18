<?php

namespace Tests;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Payments\LocalTestAdapter;
use App\Services\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;

abstract class WorkflowTestCase extends TestCase
{
    use RefreshDatabase;

    protected OrderWorkflow $flow;

    protected User $buyer;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->flow = app(OrderWorkflow::class);
        $this->buyer = User::whereEmail('buyer@example.test')->firstOrFail();
        $this->admin = User::whereEmail('admin@example.test')->firstOrFail();
    }

    protected function submitted(): Order
    {
        return $this->flow->submit($this->buyer, array_map(fn ($name, $material) => [
            'file' => new UploadedFile(base_path('fixtures/'.$name.'.stl'), $name.'.stl', 'application/octet-stream', null, true),
            'parameters' => ['material' => $material, 'layer_mm' => 0.2, 'quantity' => 1],
        ], ['bracket', 'spacer'], ['PLA', 'PETG']));
    }

    protected function confirmed(): Order
    {
        $order = $this->submitted();

        return $this->flow->confirmPrice($this->admin, $order->id, array_combine($order->lines->modelKeys(), [6000, 9000]), 'Reviewed STL', 'synthetic-consent-001');
    }

    protected function receipt(PaymentRequest $request, string $id = 'evt_1', string $reference = 'test_charge_1', string $status = 'succeeded'): void
    {
        $adapter = app(LocalTestAdapter::class);
        $event = $adapter->event($request, $id, $reference, $status);
        $adapter->receive($event, $adapter->sign($event));
    }
}
