<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateOrderSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $connection = 'database';
    public string $queue = 'orders';

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying a failed job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $summary = $this->generateSummary();

            $filename = "order_{$this->order->id}_summary.txt";
            Storage::disk('local')->put("orders/{$filename}", $summary);

            Log::info('Order summary generated successfully', [
                'order_id' => $this->order->id,
                'filename' => $filename,
            ]);
        } catch (\Exception $exception) {
            Log::error('Failed to generate order summary', [
                'order_id' => $this->order->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Generate order summary as text.
     */
    private function generateSummary(): string
    {
        $order = $this->order->load('items.product', 'user');

        $summary = "===============================================\n";
        $summary .= "ORDER SUMMARY\n";
        $summary .= "===============================================\n\n";

        $summary .= "Order ID: {$order->id}\n";
        $summary .= "Order Date: {$order->created_at->format('Y-m-d H:i:s')}\n";
        $summary .= "Status: {$order->status}\n\n";

        $summary .= "Customer Information:\n";
        $summary .= "Name: {$order->user->name}\n";
        $summary .= "Email: {$order->user->email}\n\n";

        $summary .= "Order Items:\n";
        $summary .= "-----------------------------------------------\n";

        foreach ($order->items as $item) {
            $summary .= "Product: {$item->product->name}\n";
            $summary .= "Quantity: {$item->quantity}\n";
            $summary .= "Price at Purchase: \${$item->price_at_purchase}\n";
            $summary .= "Subtotal: \$" . ($item->quantity * $item->price_at_purchase) . "\n";
            $summary .= "-----------------------------------------------\n";
        }

        $summary .= "\nOrder Total: \${$order->total_price}\n";
        $summary .= "===============================================\n";

        return $summary;
    }
}
