<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingOrders extends TableWidget
{
    protected static ?string $heading = 'Antrean verifikasi pembayaran';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Order::query()
                ->with(['customer', 'payment'])
                ->where('status', 'pending_payment')
                ->latest())
            ->columns([
                TextColumn::make('order_number')
                    ->label('No. pesanan')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('customer.name')
                    ->label('Pelanggan')
                    ->placeholder('Walk-in'),
                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
                TextColumn::make('created_at')
                    ->label('Masuk')
                    ->since(),
            ])
            ->recordActions([
                Action::make('verifyPayment')
                    ->label('Verifikasi')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Order $record): void {
                        $record->transitionTo('paid', auth()->id(), 'Pembayaran diverifikasi dari dashboard.');
                        Notification::make()->title('Pembayaran diverifikasi')->success()->send();
                    }),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5);
    }
}
