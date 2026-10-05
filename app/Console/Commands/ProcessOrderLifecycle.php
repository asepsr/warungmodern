<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Console\Command;

class ProcessOrderLifecycle extends Command
{
    protected $signature = 'orders:process-lifecycle';

    protected $description = 'Batalkan pesanan kedaluwarsa dan selesaikan pesanan yang sudah lama dikirim';

    public function handle(): int
    {
        $expiredOrders = Order::query()
            ->where('status', 'pending_payment')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', now())
            ->get();

        foreach ($expiredOrders as $order) {
            $order->transitionTo('cancelled', null, 'Batas waktu pembayaran terlewati.');
        }

        $autoCompleteHours = max(1, (int) Setting::value('auto_complete_hours', 48));
        $shippedOrders = Order::query()
            ->where('status', 'shipping')
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', now()->subHours($autoCompleteHours))
            ->get();

        foreach ($shippedOrders as $order) {
            $order->transitionTo('completed', null, 'Diselesaikan otomatis oleh scheduler.');
        }

        $this->info("{$expiredOrders->count()} pesanan kedaluwarsa dibatalkan; {$shippedOrders->count()} pesanan diselesaikan.");

        return self::SUCCESS;
    }
}
