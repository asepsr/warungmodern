<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use UnitEnum;

class DeliveryCoverage extends Page
{
    protected static ?string $title = 'Jangkauan pengiriman';

    protected static ?string $navigationLabel = 'Jangkauan pengiriman';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'delivery-coverage';

    protected string $view = 'filament.pages.delivery-coverage';

    public float $storeLat;

    public float $storeLng;

    public float $deliveryRadius;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('owner') ?? false;
    }

    public function mount(): void
    {
        $this->storeLat = (float) Setting::value('store_lat', -6.5971);
        $this->storeLng = (float) Setting::value('store_lng', 106.8060);
        $this->deliveryRadius = (float) Setting::value('delivery_radius_km', 3);
    }

    public function saveSettings(float $storeLat, float $storeLng, float $deliveryRadius): void
    {
        $values = Validator::make([
            'store_lat' => $storeLat,
            'store_lng' => $storeLng,
            'delivery_radius_km' => $deliveryRadius,
        ], [
            'store_lat' => ['required', 'numeric', 'between:-90,90'],
            'store_lng' => ['required', 'numeric', 'between:-180,180'],
            'delivery_radius_km' => ['required', 'numeric', 'gt:0', 'max:100'],
        ])->validate();

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value],
            );
        }

        $this->storeLat = (float) $values['store_lat'];
        $this->storeLng = (float) $values['store_lng'];
        $this->deliveryRadius = (float) $values['delivery_radius_km'];

        Notification::make()
            ->title('Jangkauan pengiriman diperbarui')
            ->success()
            ->send();
    }
}
