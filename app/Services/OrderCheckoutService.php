<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderCheckoutService
{
    public function __construct(private readonly QrisPayloadService $qrisPayloadService) {}

    public function checkout(Customer $customer, array $data, ?string $idempotencyKey = null): Order
    {
        return DB::transaction(function () use ($customer, $data, $idempotencyKey): Order {
            if ($idempotencyKey) {
                $existingOrder = Order::query()
                    ->where('customer_id', $customer->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingOrder) {
                    return $existingOrder->load(['items', 'payment']);
                }
            }

            Setting::query()->whereKey('checkout_code_lock')->lockForUpdate()->first();

            $cart = Cart::query()
                ->with(['items.product'])
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $cart || $cart->items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Keranjang masih kosong.']);
            }

            $subtotal = 0;
            $lockedProducts = [];

            foreach ($cart->items as $cartItem) {
                $product = Product::query()->lockForUpdate()->find($cartItem->product_id);

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['cart' => 'Ada produk yang sudah tidak tersedia.']);
                }

                if ($product->stock < $cartItem->qty) {
                    throw ValidationException::withMessages([
                        'stock' => "Stok {$product->name} tidak mencukupi.",
                    ]);
                }

                $lockedProducts[$product->id] = $product;
                $subtotal += $product->price * $cartItem->qty;
            }

            $minimumOrder = (int) Setting::value('min_order', 20000);

            if ($subtotal < $minimumOrder) {
                throw ValidationException::withMessages([
                    'subtotal' => 'Minimal belanja Rp '.number_format($minimumOrder, 0, ',', '.').'.',
                ]);
            }

            $channel = $data['channel'] ?? 'mobile';
            $address = null;
            $distanceKm = null;
            $deliveryFee = 0;

            if ($channel !== 'pos') {
                if (! Setting::value('delivery_active', true)) {
                    throw ValidationException::withMessages(['delivery' => 'Pengiriman sedang tidak tersedia.']);
                }

                [$deliveryOpens, $deliveryCloses] = array_pad(
                    explode('-', (string) Setting::value('delivery_hours', '08:00-20:00'), 2),
                    2,
                    null,
                );

                if ($deliveryOpens && $deliveryCloses) {
                    $currentTime = now()->format('H:i');

                    if ($currentTime < $deliveryOpens || $currentTime > $deliveryCloses) {
                        throw ValidationException::withMessages(['delivery' => 'Pesanan di luar jam pengiriman.']);
                    }
                }

                $address = Address::query()
                    ->where('customer_id', $customer->id)
                    ->find($data['address_id'] ?? null);

                if (! $address || $address->lat === null || $address->lng === null) {
                    throw ValidationException::withMessages(['address_id' => 'Alamat pengiriman dengan pin lokasi wajib dipilih.']);
                }

                $storeLat = (float) Setting::value('store_lat', -6.5971);
                $storeLng = (float) Setting::value('store_lng', 106.8060);
                $distanceKm = $this->distanceInKilometers($storeLat, $storeLng, (float) $address->lat, (float) $address->lng);
                $deliveryRadius = (float) Setting::value('delivery_radius_km', 3);

                if ($distanceKm > $deliveryRadius) {
                    throw ValidationException::withMessages(['address_id' => 'Alamat berada di luar radius pengiriman.']);
                }

                $deliveryFee = (int) Setting::value('delivery_flat_fee', 5000);
            }

            $uniqueCode = $this->availableUniqueCode($subtotal + $deliveryFee);
            $total = $subtotal + $deliveryFee + $uniqueCode;
            $paymentMethod = $data['payment_method'] ?? 'transfer';
            $qrisPayload = null;

            if ($paymentMethod === 'qris') {
                $qrisImagePath = Setting::value('qris_image');

                if (! is_string($qrisImagePath) || blank($qrisImagePath)) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'QRIS toko belum diunggah pada pengaturan toko.',
                    ]);
                }

                try {
                    $qrisPayload = $this->qrisPayloadService->convertStaticImageToDynamic(
                        Storage::disk('public')->path($qrisImagePath),
                        $total,
                    );
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'Gambar QRIS toko tidak dapat dibaca. Unggah ulang QRIS statis yang valid.',
                    ]);
                }
            }

            $order = Order::query()->create([
                'order_number' => 'TMP-'.Str::uuid(),
                'customer_id' => $customer->id,
                'address_id' => $address?->id,
                'channel' => $channel,
                'status' => 'pending_payment',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'unique_code' => $uniqueCode,
                'total' => $total,
                'distance_km' => $distanceKm,
                'idempotency_key' => $idempotencyKey,
                'expired_at' => now()->addMinutes((int) Setting::value('payment_expiry_minutes', 120)),
            ]);
            $order->update([
                'order_number' => 'WRG-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 4, '0', STR_PAD_LEFT),
            ]);

            foreach ($cart->items as $cartItem) {
                $product = $lockedProducts[$cartItem->product_id];
                $stockBefore = $product->stock;
                $product->decrement('stock', $cartItem->qty);

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $product->price,
                    'qty' => $cartItem->qty,
                    'subtotal' => $product->price * $cartItem->qty,
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'order_id' => $order->id,
                    'type' => 'sale',
                    'quantity' => $cartItem->qty,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockBefore - $cartItem->qty,
                    'note' => 'Stok direservasi saat checkout.',
                ]);
            }

            $order->payment()->create([
                'method' => $paymentMethod,
                'amount' => $total,
                'status' => 'pending',
                'qris_payload' => $qrisPayload,
            ]);
            $order->statusLogs()->create([
                'from_status' => null,
                'to_status' => 'pending_payment',
                'note' => 'Pesanan dibuat melalui checkout.',
            ]);
            $cart->items()->delete();

            return $order->load(['items', 'payment']);
        }, 3);
    }

    private function availableUniqueCode(int $baseAmount): int
    {
        $codes = range(1, 999);
        shuffle($codes);

        foreach ($codes as $code) {
            $isTaken = Order::query()
                ->where('status', 'pending_payment')
                ->where('total', $baseAmount + $code)
                ->exists();

            if (! $isTaken) {
                return $code;
            }
        }

        throw ValidationException::withMessages(['total' => 'Kode pembayaran sedang penuh, silakan coba lagi.']);
    }

    private function distanceInKilometers(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $latitudeDelta = deg2rad($lat2 - $lat1);
        $longitudeDelta = deg2rad($lng2 - $lng1);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }
}
