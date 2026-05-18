<?php

namespace App\Services;

use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CheckoutService
{
    public function checkout(User $user): Order
    {
        $cartItems = $user->cartItems()->with('product')->get();

        if ($cartItems->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $totalPrice = 0;
        $orderItemsPayload = [];

        foreach ($cartItems as $cartItem) {
            $product = Product::query()->findOrFail($cartItem->product_id);

            Log::info('Checkout started for user', [
                'user_id' => $user->id,
                'product_id' => $product->id,
                'stock_before' => $product->stock,
                'quantity' => $cartItem->quantity,
            ]);

            if ($product->stock < $cartItem->quantity) {
                throw new RuntimeException('Out of stock for product: ' . $product->name);
            }

            $product->stock = $product->stock - $cartItem->quantity;
            $product->save();

            Log::info('Product stock updated during checkout', [
                'user_id' => $user->id,
                'product_id' => $product->id,
                'stock_after' => $product->stock,
            ]);

            $totalPrice += $product->price * $cartItem->quantity;
            $orderItemsPayload[] = [
                'product_id' => $product->id,
                'quantity' => $cartItem->quantity,
                'price_at_purchase' => $product->price,
            ];
        }

        $order = Order::query()->create([
            'user_id' => $user->id,
            'total_price' => $totalPrice,
            'status' => 'pending',
        ]);

        $order->items()->createMany($orderItemsPayload);

        $user->cartItems()->delete();

        Log::info('Order created successfully', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'total_price' => $order->total_price,
        ]);

        // Dispatch background job to process order (invoice, notifications, etc.)
        try {
            ProcessOrderJob::dispatch($order);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch ProcessOrderJob', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        return $order->load('items.product');
    }
}
