<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $storeLat = (float) Setting::value('store_lat', -6.5971);
        $storeLng = (float) Setting::value('store_lng', 106.8060);
        $distance = $this->distanceInKilometers($storeLat, $storeLng, (float) $validated['lat'], (float) $validated['lng']);
        $radius = (float) Setting::value('delivery_radius_km', 3);
        $active = (bool) Setting::value('delivery_active', true);

        return response()->json([
            'success' => true,
            'data' => [
                'reachable' => $active && $distance <= $radius,
                'distance_km' => round($distance, 2),
                'delivery_radius_km' => $radius,
                'delivery_fee' => (int) Setting::value('delivery_flat_fee', 5000),
            ],
        ]);
    }

    private function distanceInKilometers(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latitudeDelta = deg2rad($lat2 - $lat1);
        $longitudeDelta = deg2rad($lng2 - $lng1);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }
}
