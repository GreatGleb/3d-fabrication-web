<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Payments\LocalTestAdapter;
use App\Services\OrderWorkflow;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

class ProbeDemo extends Command
{
    protected $signature = 'probe:demo {--stop-after=paid : submitted, confirmed, advance, start or paid}';

    protected $description = 'Create one synthetic two-STL order; run only local test receipts';

    public function handle(OrderWorkflow $flow, LocalTestAdapter $adapter): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Local/testing only.');

            return self::FAILURE;
        }
        $stop = $this->option('stop-after');
        if (! in_array($stop, ['submitted', 'confirmed', 'advance', 'start', 'paid'], true)) {
            $this->error('Invalid stop-after stage.');

            return self::FAILURE;
        }
        $buyer = User::whereEmail('buyer@example.test')->firstOrFail();
        $admin = User::whereEmail('admin@example.test')->firstOrFail();
        $items = [];
        foreach (['bracket' => 'PLA', 'spacer' => 'PETG'] as $name => $material) {
            $items[] = ['file' => new UploadedFile(base_path('fixtures/'.$name.'.stl'), $name.'.stl', 'application/octet-stream', null, true), 'parameters' => ['material' => $material, 'layer_mm' => 0.2, 'quantity' => 1]];
        }
        $order = $flow->submit($buyer, $items);
        $emit = function (string $step) use ($flow, $order): void {
            $this->line(json_encode(['step' => $step, 'order_id' => $order->id, 'balance' => $flow->balance($order->fresh()), 'production' => $order->fresh()->production->state], JSON_UNESCAPED_SLASHES));
        };
        $emit('submitted');
        if ($stop === 'submitted') {
            return self::SUCCESS;
        }
        $order = $flow->confirmPrice($admin, $order->id, array_combine($order->lines->modelKeys(), [6000, 9000]), 'Synthetic STL review', 'synthetic-consent-demo');
        $emit('confirmed');
        if ($stop === 'confirmed') {
            return self::SUCCESS;
        }
        $advance = $flow->requestPayment($admin, $order->id, 4000);
        $event = $adapter->event($advance, 'evt_'.$advance->public_id, 'test_charge_'.$advance->public_id);
        $adapter->receive($event, $adapter->sign($event));
        $adapter->receive($event, $adapter->sign($event));
        $emit('advance_with_duplicate_receipt');
        if ($stop === 'advance') {
            return self::SUCCESS;
        }
        $flow->startProduction($admin, $order->id);
        $emit('start_with_outstanding_balance');
        if ($stop === 'start') {
            return self::SUCCESS;
        }
        $balance = $flow->requestPayment($admin, $order->id, 11000);
        $event = $adapter->event($balance, 'evt_'.$balance->public_id, 'test_charge_'.$balance->public_id);
        $adapter->receive($event, $adapter->sign($event));
        $emit('separate_balance_paid');

        return self::SUCCESS;
    }
}
