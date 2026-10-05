<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('No. pesanan')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('customer.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->placeholder('Walk-in'),
                TextColumn::make('channel')
                    ->label('Kanal')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'pending_payment' => 'warning',
                        'paid' => 'success',
                        'shipping' => 'info',
                        'completed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->sortable(),
                TextColumn::make('payment.status')
                    ->label('Pembayaran')
                    ->badge()
                    ->placeholder('Belum ada'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status pesanan')
                    ->options(Order::STATUSES),
            ])
            ->recordActions([
                Action::make('verifyPayment')
                    ->label('Verifikasi')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verifikasi pembayaran')
                    ->visible(fn (Order $record): bool => $record->status === 'pending_payment'
                        && (auth()->user()?->hasAnyRole(['owner', 'kasir']) ?? false))
                    ->action(function (Order $record): void {
                        $record->transitionTo('paid', auth()->id(), 'Pembayaran diverifikasi dari dashboard.');
                        Notification::make()->title('Pembayaran diverifikasi')->success()->send();
                    }),
                Action::make('rejectPayment')
                    ->label('Tolak bukti')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([Textarea::make('reject_reason')->label('Alasan penolakan')->required()->maxLength(500)])
                    ->visible(fn (Order $record): bool => $record->status === 'pending_payment'
                        && (auth()->user()?->hasAnyRole(['owner', 'kasir']) ?? false)
                        && $record->payment?->status === 'submitted'
                        && $record->payment->proofs->isNotEmpty())
                    ->action(function (Order $record, array $data): void {
                        $record->payment->update([
                            'status' => 'rejected',
                            'reject_reason' => $data['reject_reason'],
                        ]);
                        Notification::make()->title('Bukti pembayaran ditolak')->warning()->send();
                    }),
                Action::make('viewPaymentProof')
                    ->label('Lihat bukti')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->url(function (Order $record): ?string {
                        $proof = $record->payment?->proofs->last();

                        return $proof
                            ? route('admin.orders.payment-proof', [$record->order_number, $proof])
                            : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(fn (Order $record): bool => (auth()->user()?->hasAnyRole(['owner', 'kasir']) ?? false)
                        && ($record->payment?->proofs->isNotEmpty() ?? false)),
                Action::make('ship')
                    ->label('Kirim')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record): bool => $record->status === 'paid'
                        && (auth()->user()?->hasAnyRole(['owner', 'kurir']) ?? false))
                    ->action(fn (Order $record) => $record->transitionTo('shipping', auth()->id(), 'Pesanan mulai dikirim.')),
                Action::make('complete')
                    ->label('Selesai')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record): bool => $record->status === 'shipping'
                        && (auth()->user()?->hasAnyRole(['owner', 'kurir']) ?? false))
                    ->action(fn (Order $record) => $record->transitionTo('completed', auth()->id(), 'Pesanan selesai.')),
                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->form([Textarea::make('cancel_reason')->label('Alasan pembatalan')->required()->maxLength(500)])
                    ->visible(fn (Order $record): bool => in_array($record->status, ['pending_payment', 'paid'], true)
                        && (auth()->user()?->hasRole('owner') ?? false))
                    ->action(fn (Order $record, array $data) => $record->transitionTo('cancelled', auth()->id(), $data['cancel_reason'])),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'payment.proofs']));
    }
}
