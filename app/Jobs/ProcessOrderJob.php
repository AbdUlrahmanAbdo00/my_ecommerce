<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $connection = 'database';
    public string $queue = 'orders';
    public int $tries = 3;
    public int $backoff = 10;

    public int $orderId;

    public function __construct(Order $order)
    {
        $this->orderId = $order->id;
    }

    public function handle(): void
    {
        $order = Order::query()->with('items.product')->find($this->orderId);
        if (! $order) {
            Log::warning('ProcessOrderJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        Log::info('Processing order in background', ['order_id' => $order->id]);

        // Simulate background work (invoice generation, notifications, etc.)
        sleep(1);

        // Mark as processing to indicate background work started.
        try {
            $order->update(['status' => 'processing']);
        } catch (\Throwable $e) {
            Log::error('ProcessOrderJob: failed to update order status', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        Log::info('ProcessOrderJob completed', ['order_id' => $order->id]);
    }
}
