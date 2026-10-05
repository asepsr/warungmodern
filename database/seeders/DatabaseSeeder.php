<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['owner', 'kasir', 'kurir'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ([
            'store_lat' => '-6.5971',
            'store_lng' => '106.8060',
            'delivery_radius_km' => '3',
            'delivery_flat_fee' => '5000',
            'min_order' => '20000',
            'delivery_hours' => '08:00-20:00',
            'delivery_active' => 'true',
            'payment_expiry_minutes' => '120',
            'auto_complete_hours' => '48',
            'bank_accounts' => '[]',
            'qris_image' => '',
            'admin_whatsapp' => '',
            'checkout_code_lock' => '1',
        ] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        $ownerPassword = env('OWNER_PASSWORD');

        if (! $ownerPassword && app()->environment('local')) {
            $ownerPassword = 'password';
        }

        if ($ownerPassword) {
            $owner = User::firstOrCreate(
                ['email' => env('OWNER_EMAIL', 'owner@warung.local')],
                [
                    'name' => env('OWNER_NAME', 'Pemilik Warung'),
                    'password' => Hash::make($ownerPassword),
                ],
            );
            $owner->assignRole('owner');
        }

        $categories = [
            'Beras & Sembako' => 'beras-sembako',
            'Minyak & Bumbu' => 'minyak-bumbu',
            'Minuman' => 'minuman',
            'Kebutuhan Rumah' => 'kebutuhan-rumah',
        ];

        foreach ($categories as $name => $slug) {
            Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }

        $products = [
            ['BR-5KG', 'Beras Setra Ramos 5 kg', 'Beras & Sembako', 'karung', 72500, 18, 5, ['beras', 'beras 5kg'], 'beras.jpg', 'Beras pulen untuk kebutuhan makan sehari-hari.'],
            ['GL-1KG', 'Gula Pasir 1 kg', 'Beras & Sembako', 'pcs', 17500, 24, 6, ['gula', 'gula 1kg'], 'gula.jpg', 'Gula pasir putih untuk minuman dan kebutuhan memasak.'],
            ['MY-1L', 'Minyak Goreng 1 L', 'Minyak & Bumbu', 'pouch', 18500, 4, 8, ['minyak', 'migor'], 'minyak.jpg', 'Minyak bunga matahari untuk menumis dan menggoreng.'],
            ['TL-1KG', 'Telur Ayam 1 kg', 'Beras & Sembako', 'kg', 29000, 12, 4, ['telur', 'telor'], 'telur.jpg', 'Telur ayam segar untuk lauk dan bahan masakan.'],
            ['AM-600', 'Air Mineral 600 ml', 'Minuman', 'botol', 3500, 36, 12, ['air mineral', 'aqua'], 'air-mineral.jpg', 'Air mineral dalam kemasan praktis untuk dibawa.'],
            ['DS-800', 'Sabun Cuci Piring 800 ml', 'Kebutuhan Rumah', 'botol', 14500, 9, 3, ['sabun cuci', 'sabun piring'], 'sabun.jpg', 'Cairan pencuci piring untuk membersihkan peralatan dapur.'],
        ];

        foreach ($products as [$sku, $name, $category, $unit, $price, $stock, $minStock, $aliases, $imageFilename, $description]) {
            $product = Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'category_id' => Category::where('name', $category)->value('id'),
                    'name' => $name,
                    'unit' => $unit,
                    'price' => $price,
                    'stock' => $stock,
                    'min_stock' => $minStock,
                    'aliases' => $aliases,
                    'description' => $description,
                    'is_active' => true,
                ],
            );

            $sourcePath = resource_path("images/products/{$imageFilename}");
            $destinationPath = storage_path("app/public/products/{$imageFilename}");

            if (File::exists($sourcePath) && ! File::exists($destinationPath)) {
                File::ensureDirectoryExists(dirname($destinationPath));
                File::copy($sourcePath, $destinationPath);
            }

            $updates = [];

            if (! $product->image_path && File::exists($sourcePath)) {
                $updates['image_path'] = "products/{$imageFilename}";
            }

            if (! $product->description) {
                $updates['description'] = $description;
            }

            if ($updates) {
                $product->update($updates);
            }
        }
    }
}
