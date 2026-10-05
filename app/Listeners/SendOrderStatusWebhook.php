<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;

class SendOrderStatusWebhook implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300, 600];

    public function handle(OrderStatusChanged $event): void
    {
        $url = config('services.warung.status_webhook_url');

        if (! $url) {
            return;
        }

        $order = Order::query()
            ->with(['customer', 'items'])
            ->where('order_number', $event->orderNumber)
            ->firstOrFail();
        $payload = [
            'order_number' => $order->order_number,
            'from_status' => $event->fromStatus,
            'status' => $event->toStatus,
            'phone' => $order->customer?->phone,
            'total' => $order->total,
            'items' => $order->items->map(fn ($item): array => [
                'name' => $item->product_name,
                'qty' => $item->qty,
                'subtotal' => $item->subtotal,
            ])->all(),
            'changed_at' => now()->toIso8601String(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $secret = (string) config('services.warung.status_webhook_secret');
        $signature = hash_hmac('sha256', $body, $secret);

        Http::timeout(10)
            ->withHeaders(['X-Warung-Signature' => 'sha256='.$signature])
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }
}
