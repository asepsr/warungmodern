<?php

namespace App\Models;

use App\Events\OrderStatusChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    public const STATUSES = [
        'pending_payment' => 'Menunggu pembayaran',
        'paid' => 'Pembayaran berhasil',
        'shipping' => 'Dalam pengiriman',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    protected $fillable = [
        'order_number', 'customer_id', 'address_id', 'channel', 'status', 'subtotal',
        'delivery_fee', 'unique_code', 'total', 'distance_km', 'idempotency_key',
        'expired_at', 'paid_at', 'shipped_at', 'completed_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'expired_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function transitionTo(string $status, ?int $userId = null, ?string $note = null): void
    {
        $allowedTransitions = [
            'pending_payment' => ['paid', 'cancelled'],
            'paid' => ['shipping', 'cancelled'],
            'shipping' => ['completed'],
            'completed' => [],
            'cancelled' => [],
        ];

        $fromStatus = null;

        DB::transaction(function () use ($status, $userId, $note, $allowedTransitions, &$fromStatus) {
            $order = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $from = $order->status;
            $fromStatus = $from;

            if (! in_array($status, $allowedTransitions[$from] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "Transisi dari {$from} ke {$status} tidak diizinkan.",
                ]);
            }

            if ($status === 'paid') {
                if (! $order->payment) {
                    throw ValidationException::withMessages([
                        'payment' => 'Pesanan tidak memiliki pembayaran untuk diverifikasi.',
                    ]);
                }

                $order->payment()->update([
                    'status' => 'verified',
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ]);
                $order->paid_at = now();
            }

            if ($status === 'shipping') {
                $order->shipped_at = now();
            }

            if ($status === 'completed') {
                $order->completed_at = now();
            }

            if ($status === 'cancelled') {
                foreach ($order->items()->get() as $item) {
                    $product = Product::query()->lockForUpdate()->find($item->product_id);

                    if (! $product) {
                        continue;
                    }

                    $stockBefore = $product->stock;
                    $product->increment('stock', $item->qty);
                    StockMovement::create([
                        'product_id' => $product->id,
                        'order_id' => $order->id,
                        'type' => 'restore',
                        'quantity' => $item->qty,
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockBefore + $item->qty,
                        'note' => 'Stok dikembalikan dari pembatalan pesanan.',
                        'created_by' => $userId,
                    ]);
                }

                $order->cancel_reason = $note;
            }

            $order->status = $status;
            $order->save();

            $order->statusLogs()->create([
                'from_status' => $from,
                'to_status' => $status,
                'changed_by' => $userId,
                'note' => $note,
            ]);
        });

        $this->refresh();
        OrderStatusChanged::dispatch($this->order_number, $fromStatus, $status);
    }
}
