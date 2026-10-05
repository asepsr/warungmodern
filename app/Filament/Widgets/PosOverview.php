<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PosOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $todayOrders = Order::query()->whereDate('created_at', today());
        $salesToday = (clone $todayOrders)
            ->whereIn('status', ['paid', 'shipping', 'completed'])
            ->sum('total');

        return [
            Stat::make('Penjualan hari ini', 'Rp '.number_format($salesToday, 0, ',', '.'))
                ->description((clone $todayOrders)->count().' pesanan masuk hari ini')
                ->color('success'),
            Stat::make('Menunggu verifikasi', Order::query()->where('status', 'pending_payment')->count())
                ->description('Pesanan menunggu pembayaran')
                ->color('warning'),
            Stat::make('Dalam pengiriman', Order::query()->where('status', 'shipping')->count())
                ->description('Pesanan yang sedang dikirim')
                ->color('info'),
            Stat::make('Stok menipis', Product::query()->where('is_active', true)->whereColumn('stock', '<=', 'min_stock')->count())
                ->description('Produk mencapai batas minimum')
                ->color('danger'),
        ];
    }
}
