<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Filament\Pages\DeliveryCoverage;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Listeners\SendOrderStatusWebhook;
use App\Models\Address;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderCheckoutService;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_is_idempotent_snapshots_price_and_restores_stock_on_cancel(): void
    {
        $category = Category::create(['name' => 'Sembako', 'slug' => 'sembako']);
        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'TEST-001',
            'name' => 'Beras 5 kg',
            'unit' => 'karung',
            'price' => 30000,
            'stock' => 5,
            'min_stock' => 1,
            'is_active' => true,
        ]);
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan Uji']);
        $address = Address::create([
            'customer_id' => $customer->id,
            'recipient_name' => 'Pelanggan Uji',
            'phone' => $customer->phone,
            'full_address' => 'Jl. Uji No. 1',
            'lat' => -6.5971,
            'lng' => 106.8060,
            'is_default' => true,
        ]);
        $customer->cart()->create()->items()->create([
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        Setting::create(['key' => 'min_order', 'value' => '20000']);
        Setting::create(['key' => 'store_lat', 'value' => '-6.5971']);
        Setting::create(['key' => 'store_lng', 'value' => '106.8060']);
        Setting::create(['key' => 'delivery_radius_km', 'value' => '3']);
        Setting::create(['key' => 'delivery_flat_fee', 'value' => '5000']);
        Setting::create(['key' => 'delivery_hours', 'value' => '00:00-23:59']);
        Setting::create(['key' => 'checkout_code_lock', 'value' => '1']);

        $checkout = app(OrderCheckoutService::class);
        $order = $checkout->checkout($customer, ['address_id' => $address->id], 'checkout-unique-1');
        $product->refresh();
        $product->update(['price' => 35000]);

        $replayedOrder = $checkout->checkout($customer, ['address_id' => $address->id], 'checkout-unique-1');

        $this->assertSame($order->id, $replayedOrder->id);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(30000, $order->items->first()->price);
        $this->assertSame(1, Order::query()->count());

        $order->transitionTo('cancelled', null, 'Pembeli membatalkan pesanan.');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['order_id' => $order->id, 'type' => 'restore']);
        $this->assertCount(2, $order->fresh()->statusLogs);
    }

    public function test_order_rejects_an_illegal_status_transition(): void
    {
        $order = Order::create([
            'order_number' => 'WRG-20261001-TEST',
            'channel' => 'mobile',
            'status' => 'pending_payment',
            'subtotal' => 25000,
            'delivery_fee' => 5000,
            'unique_code' => 123,
            'total' => 30123,
        ]);

        $this->expectException(ValidationException::class);

        $order->transitionTo('shipping');
    }

    public function test_owner_can_verify_ship_and_complete_an_order_from_the_dashboard(): void
    {
        Role::findOrCreate('owner', 'web');
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan Status']);
        $order = Order::create([
            'order_number' => 'WRG-20261002-1001',
            'customer_id' => $customer->id,
            'channel' => 'mobile',
            'status' => 'pending_payment',
            'subtotal' => 25000,
            'delivery_fee' => 0,
            'unique_code' => 101,
            'total' => 25101,
        ]);
        $order->payment()->create(['method' => 'transfer', 'amount' => 25101, 'status' => 'pending']);

        Livewire::actingAs($owner)
            ->test(ListOrders::class)
            ->callTableAction('verifyPayment', $order);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('verified', $order->payment->fresh()->status);

        Livewire::actingAs($owner)
            ->test(ListOrders::class)
            ->callTableAction('ship', $order->fresh());

        $this->assertSame('shipping', $order->fresh()->status);

        Livewire::actingAs($owner)
            ->test(ListOrders::class)
            ->callTableAction('complete', $order->fresh());

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_order_lifecycle_command_cancels_expired_orders_and_restores_stock(): void
    {
        $category = Category::create(['name' => 'Sembako', 'slug' => 'sembako']);
        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'EXP-001',
            'name' => 'Produk kedaluwarsa',
            'unit' => 'pcs',
            'price' => 25000,
            'stock' => 3,
            'min_stock' => 1,
            'is_active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'WRG-20261002-1002',
            'channel' => 'mobile',
            'status' => 'pending_payment',
            'subtotal' => 50000,
            'delivery_fee' => 0,
            'unique_code' => 102,
            'total' => 50102,
            'expired_at' => now()->subMinute(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 25000,
            'qty' => 2,
            'subtotal' => 50000,
        ]);

        $this->artisan('orders:process-lifecycle')->assertSuccessful();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['order_id' => $order->id, 'type' => 'restore']);
    }

    public function test_order_status_webhook_sends_a_verifiable_hmac_signature(): void
    {
        Http::fake();
        config([
            'services.warung.status_webhook_url' => 'https://webhook.example.test/order-status',
            'services.warung.status_webhook_secret' => 'test-webhook-secret',
        ]);
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan Webhook']);
        $order = Order::create([
            'order_number' => 'WRG-20261002-1003',
            'customer_id' => $customer->id,
            'channel' => 'mobile',
            'status' => 'paid',
            'subtotal' => 25000,
            'delivery_fee' => 0,
            'unique_code' => 103,
            'total' => 25103,
        ]);
        $order->items()->create([
            'product_name' => 'Beras',
            'price' => 25000,
            'qty' => 1,
            'subtotal' => 25000,
        ]);

        app(SendOrderStatusWebhook::class)->handle(new OrderStatusChanged(
            $order->order_number,
            'pending_payment',
            'paid',
        ));

        Http::assertSent(function (HttpRequest $request): bool {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->body(), 'test-webhook-secret');

            return $request->url() === 'https://webhook.example.test/order-status'
                && $request->header('X-Warung-Signature') === [$expectedSignature]
                && $request->data()['status'] === 'paid';
        });
    }

    public function test_admin_login_page_is_available(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_owner_can_open_the_admin_dashboard(): void
    {
        Role::findOrCreate('owner', 'web');
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $this->assertTrue($owner->hasAnyRole(['owner', 'kasir', 'kurir']));

        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->actingAs($owner)->get('/admin/orders')->assertOk()->assertDontSee('New pesanan');
    }

    public function test_owner_can_view_and_save_delivery_coverage_settings(): void
    {
        Role::findOrCreate('owner', 'web');
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $this->actingAs($owner)
            ->get('/admin/delivery-coverage')
            ->assertOk()
            ->assertSee('Peta jangkauan pengiriman')
            ->assertSee('tile.openstreetmap.org');

        Livewire::actingAs($owner)
            ->test(DeliveryCoverage::class)
            ->call('saveSettings', -6.210000, 106.820000, 4.5)
            ->assertHasNoErrors();

        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan Peta']);
        $token = $customer->createToken('delivery-map-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/delivery/check', ['lat' => -6.21, 'lng' => 106.84])
            ->assertOk()
            ->assertJsonPath('data.reachable', true)
            ->assertJsonPath('data.delivery_radius_km', 4.5);

        $this->assertDatabaseHas('settings', ['key' => 'store_lat', 'value' => '-6.21']);
        $this->assertDatabaseHas('settings', ['key' => 'store_lng', 'value' => '106.82']);
        $this->assertDatabaseHas('settings', ['key' => 'delivery_radius_km', 'value' => '4.5']);
    }

    public function test_catalog_api_returns_active_products_and_matches_aliases(): void
    {
        $category = Category::create(['name' => 'Sembako', 'slug' => 'sembako']);
        Product::create([
            'category_id' => $category->id,
            'sku' => 'BR-5KG',
            'name' => 'Beras Setra Ramos 5 kg',
            'unit' => 'karung',
            'price' => 72500,
            'stock' => 10,
            'min_stock' => 2,
            'aliases' => ['beras', 'beras 5kg'],
            'is_active' => true,
        ]);

        $this->getJson('/api/menu')
            ->assertOk()
            ->assertJsonPath('data.0.products.0.sku', 'BR-5KG');

        $botUser = User::factory()->create();
        $botToken = $botUser->createToken('bot-test', ['bot'])->plainTextToken;

        $this->withToken($botToken)->postJson('/api/bot/products/match', [
            'items' => [['query' => 'beras 5kg', 'qty' => 1]],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.candidates.0.sku', 'BR-5KG');
    }

    public function test_cart_and_orders_require_a_customer_token(): void
    {
        $this->getJson('/api/cart')->assertUnauthorized();
        $this->postJson('/api/orders', [])->assertUnauthorized();
    }

    public function test_bot_inbound_deduplication_is_idempotent(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('bot-test', ['bot'])->plainTextToken;
        $payload = [
            'message_id' => 'wamid-unique-1',
            'phone' => '081234567890',
            'type' => 'text',
            'payload' => ['text' => 'Halo'],
        ];

        $this->withToken($token)
            ->postJson('/api/bot/inbound/dedupe', $payload)
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);

        $this->withToken($token)
            ->postJson('/api/bot/inbound/dedupe', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);
    }

    public function test_bot_can_manage_a_customer_cart_and_checkout_with_its_bot_token(): void
    {
        $category = Category::create(['name' => 'Sembako', 'slug' => 'sembako']);
        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'BOT-001',
            'name' => 'Beras Bot',
            'unit' => 'kg',
            'price' => 25000,
            'stock' => 5,
            'min_stock' => 1,
            'is_active' => true,
        ]);
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan Bot']);
        $address = Address::create([
            'customer_id' => $customer->id,
            'recipient_name' => 'Pelanggan Bot',
            'phone' => $customer->phone,
            'full_address' => 'Alamat bot',
            'lat' => -6.5971,
            'lng' => 106.8060,
        ]);
        Setting::create(['key' => 'min_order', 'value' => '20000']);
        Setting::create(['key' => 'store_lat', 'value' => '-6.5971']);
        Setting::create(['key' => 'store_lng', 'value' => '106.8060']);
        Setting::create(['key' => 'delivery_radius_km', 'value' => '3']);
        Setting::create(['key' => 'delivery_flat_fee', 'value' => '5000']);
        Setting::create(['key' => 'delivery_hours', 'value' => '00:00-23:59']);
        Setting::create(['key' => 'checkout_code_lock', 'value' => '1']);
        $botToken = User::factory()->create()->createToken('bot-test', ['bot'])->plainTextToken;

        $this->withToken($botToken)
            ->postJson('/api/bot/customers/081234567890/cart/items', [
                'product_id' => $product->id,
                'qty' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.qty', 2);

        $this->withToken($botToken)
            ->postJson('/api/bot/customers/081234567890/orders', [
                'address_id' => $address->id,
                'payment_method' => 'transfer',
            ], ['Idempotency-Key' => 'bot-checkout-1'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.payment.method', 'transfer');

        $this->withToken($botToken)
            ->getJson('/api/bot/customers/081234567890/orders?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_cashier_can_open_orders_but_not_store_settings(): void
    {
        Role::findOrCreate('kasir', 'web');
        $cashier = User::factory()->create();
        $cashier->assignRole('kasir');

        $this->actingAs($cashier)->get('/admin/orders')->assertOk();
        $this->actingAs($cashier)->get('/admin/settings')->assertForbidden();
    }

    public function test_owner_can_open_the_qris_image_upload_setting(): void
    {
        Role::findOrCreate('owner', 'web');
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        Setting::create(['key' => 'qris_image', 'value' => '']);

        $this->actingAs($owner)
            ->get('/admin/settings/qris_image/edit')
            ->assertOk()
            ->assertSee('Gambar QRIS statis')
            ->assertSee('QR nominal dibuat saat pelanggan checkout.');
    }

    public function test_customer_list_does_not_offer_manual_creation(): void
    {
        Role::findOrCreate('owner', 'web');
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $this->actingAs($owner)->get('/admin/customers')
            ->assertOk()
            ->assertDontSee('Buat pelanggan');
        $this->actingAs($owner)->get('/admin/customers/create')->assertNotFound();
    }

    public function test_customer_can_checkout_through_the_sanctum_api(): void
    {
        $category = Category::create(['name' => 'Sembako', 'slug' => 'sembako']);
        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'GL-1KG',
            'name' => 'Gula 1 kg',
            'unit' => 'pcs',
            'price' => 25000,
            'stock' => 4,
            'min_stock' => 1,
            'is_active' => true,
        ]);
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan API']);
        $address = Address::create([
            'customer_id' => $customer->id,
            'recipient_name' => 'Pelanggan API',
            'phone' => $customer->phone,
            'full_address' => 'Jl. API No. 1',
            'lat' => -6.5971,
            'lng' => 106.8060,
        ]);
        Setting::create(['key' => 'checkout_code_lock', 'value' => '1']);
        Setting::create(['key' => 'delivery_hours', 'value' => '00:00-23:59']);
        $token = $customer->createToken('mobile-app')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'qty' => 2])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/orders', ['address_id' => $address->id], ['Idempotency-Key' => 'api-checkout-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.items.0.price', 25000);

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_qris_checkout_returns_a_qr_with_the_exact_order_total(): void
    {
        Storage::fake('public');
        $staticImagePath = 'qris/static.png';
        $temporaryImage = tempnam(sys_get_temp_dir(), 'qris-');

        try {
            (new QRCode(new QROptions([
                'outputType' => QROutputInterface::GDIMAGE_PNG,
            ])))->render($this->staticQrisPayload(), $temporaryImage);
            Storage::disk('public')->put($staticImagePath, file_get_contents($temporaryImage));
        } finally {
            unlink($temporaryImage);
        }

        $category = Category::create(['name' => 'Sembako', 'slug' => 'sembako']);
        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'QR-TEST',
            'name' => 'Produk QR',
            'unit' => 'pcs',
            'price' => 25000,
            'stock' => 5,
            'min_stock' => 1,
            'is_active' => true,
        ]);
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan QR']);
        $customer->cart()->create()->items()->create(['product_id' => $product->id, 'qty' => 1]);
        Setting::create(['key' => 'min_order', 'value' => '20000']);
        Setting::create(['key' => 'checkout_code_lock', 'value' => '1']);
        Setting::create(['key' => 'qris_image', 'value' => $staticImagePath]);
        $token = $customer->createToken('qris-checkout-test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/orders', [
            'channel' => 'pos',
            'payment_method' => 'qris',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment.method', 'qris');

        $order = $response->json('data');
        $this->assertSame($order['total'], $order['payment']['amount']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $order['payment']['qris_image']);

        $payload = $customer->orders()->findOrFail($order['id'])->payment->qris_payload;
        $fields = $this->parseQrisPayload($payload);
        $this->assertSame('12', $fields['01']);
        $this->assertSame((string) $order['total'], $fields['54']);

        $this->withToken($token)
            ->getJson('/api/orders/'.$order['order_number'])
            ->assertOk()
            ->assertJsonPath('data.payment.qris_image', $order['payment']['qris_image']);
    }

    private function staticQrisPayload(): string
    {
        $body = implode('', [
            $this->qrisField('00', '01'),
            $this->qrisField('01', '11'),
            $this->qrisField('26', $this->qrisField('00', 'ID.CO.QRIS')),
            $this->qrisField('52', '0000'),
            $this->qrisField('53', '360'),
            $this->qrisField('58', 'ID'),
        ]);
        $crcInput = $body.'6304';

        return $crcInput.$this->qrisCrc($crcInput);
    }

    private function qrisField(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    private function parseQrisPayload(string $payload): array
    {
        $fields = [];
        $offset = 0;
        $bodyLength = strlen($payload) - 8;

        while ($offset < $bodyLength) {
            $tag = substr($payload, $offset, 2);
            $length = (int) substr($payload, $offset + 2, 2);
            $fields[$tag] = substr($payload, $offset + 4, $length);
            $offset += 4 + $length;
        }

        return $fields;
    }

    private function qrisCrc(string $value): string
    {
        $crc = 0xFFFF;

        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $crc ^= ord($value[$index]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
