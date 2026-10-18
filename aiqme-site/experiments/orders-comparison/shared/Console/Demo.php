<?php

namespace Comparison\Console;

use App\Models\User;
use App\Payments\LocalTestAdapter;
use App\Services\OrderWorkflow;
use Comparison\ReadModel;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

class Demo extends Command
{
    protected $signature = 'comparison:demo {--stage=advance}';

    protected $description = 'Synthetic parity example; no external payments';

    public function handle(OrderWorkflow $workflow, LocalTestAdapter $adapter): int
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        $buyer = User::whereEmail('buyer@example.test')->firstOrFail();
        $admin = User::whereEmail('admin@example.test')->firstOrFail();
        $items = [];
        foreach (['bracket' => 'PLA', 'spacer' => 'PETG'] as $name => $material) {
            $items[] = ['file' => new UploadedFile(base_path('fixtures/'.$name.'.stl'), $name.'.stl', 'application/octet-stream', null, true), 'parameters' => ['material' => $material, 'layer_mm' => 0.2, 'quantity' => 1]];
        }
        $order = $workflow->submit($buyer, $items);
        $emit = function (string $step) use ($workflow, $order): void {
            $this->line(json_encode(['step' => $step, 'order_id' => $order->id, 'balance' => $workflow->balance($order->fresh()), 'production' => ReadModel::state($order->fresh())->state]));
        };
        $emit('submitted');
        if ($this->option('stage') === 'submitted') {
            return self::SUCCESS;
        }
        $workflow->confirmPrice($admin, $order->id, array_combine($order->lines->modelKeys(), [6000, 9000]), 'Synthetic review', 'synthetic-agreement');
        $emit('confirmed');
        $request = $workflow->requestPayment($admin, $order->id, 4000);
        $event = $adapter->event($request, 'evt_'.$request->public_id, 'test_charge_'.$request->public_id);
        $adapter->receive($event, $adapter->sign($event));
        $adapter->receive($event, $adapter->sign($event));
        $emit('advance');
        if ($this->option('stage') === 'advance') {
            return self::SUCCESS;
        }
        $workflow->startProduction($admin, $order->id);
        $emit('production_with_debt');
        $request = $workflow->requestPayment($admin, $order->id, 11000);
        $event = $adapter->event($request, 'evt_'.$request->public_id, 'test_charge_'.$request->public_id);
        $adapter->receive($event, $adapter->sign($event));
        $emit('balance_paid');

        return self::SUCCESS;
    }
}
