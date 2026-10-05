<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_token_can_check_delivery_coverage(): void
    {
        $customer = Customer::create(['phone' => '6281234567890', 'name' => 'Pelanggan Uji']);
        $token = $customer->createToken('n8n-bot', ['bot'])->plainTextToken;
        Setting::create(['key' => 'store_lat', 'value' => '-6.5971']);
        Setting::create(['key' => 'store_lng', 'value' => '106.8060']);
        Setting::create(['key' => 'delivery_radius_km', 'value' => '3']);
        Setting::create(['key' => 'delivery_flat_fee', 'value' => '5000']);
        Setting::create(['key' => 'delivery_active', 'value' => 'true']);

        $this->withToken($token)
            ->postJson('/api/bot/delivery/check', ['lat' => -6.5971, 'lng' => 106.8060])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reachable', true)
            ->assertJsonPath('data.delivery_fee', 5000);
    }

    public function test_delivery_check_rejects_requests_without_bot_authentication(): void
    {
        $this->postJson('/api/bot/delivery/check', ['lat' => -6.5971, 'lng' => 106.8060])
            ->assertUnauthorized();
    }
}
