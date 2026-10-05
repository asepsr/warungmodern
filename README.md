# Warung Modern POS

Fondasi tahap 1 untuk POS Warung Modern: dashboard admin Tailwind berbasis Filament, katalog, stok, pelanggan, keranjang, pesanan, pembayaran, dan API Laravel. Integrasi WAHA, n8n, Gemini, aplikasi mobile, serta website pelanggan belum termasuk tahap ini.

## Menjalankan di Laragon

```powershell
cd D:\laragon\www\WARUNGMODERN\laravel-pos
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
php artisan serve
```

Buka `http://127.0.0.1:8000/admin`. Akun development bawaan: `owner@warung.local` dengan kata sandi `password`. Ganti kredensial ini sebelum aplikasi dipakai di luar mesin lokal. Atur `OWNER_NAME`, `OWNER_EMAIL`, dan `OWNER_PASSWORD` di `.env` untuk membuat akun owner sendiri.

Database memakai MySQL Laragon (`warung_modern`). Buat database tersebut di Laragon/phpMyAdmin, lalu sesuaikan `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di `.env` bila kredensial lokal Anda berbeda. Jalankan migrasi dan seeder setelah database tersedia. Atur koordinat asli toko (`store_lat`, `store_lng`) serta rekening/QRIS dari menu **Pengaturan toko** sebelum menerima pesanan.

Jika membuka aplikasi lewat domain virtual Laragon, sesuaikan `APP_URL` dengan domain tersebut agar URL gambar tetap satu origin.

## Operasional

- Jalankan queue worker saat development: `php artisan queue:work`.
- Scheduler pesanan berjalan setiap menit. Untuk development jalankan `php artisan schedule:work`; untuk Linux production tambahkan cron `* * * * * cd /path/laravel-pos && php artisan schedule:run >> /dev/null 2>&1`.
- `php artisan orders:process-lifecycle` membatalkan pesanan yang melewati tenggat pembayaran dan menyelesaikan pesanan yang lama dikirim.
- `php artisan warung:create-bot-token` menampilkan token Sanctum ability `bot` satu kali. Simpan token sebagai secret, jangan masukkan ke source control.
- Untuk webhook status, isi `ORDER_STATUS_WEBHOOK_URL` dan `ORDER_STATUS_WEBHOOK_SECRET`. Worker queue mengirim payload bertanda tangan pada header `X-Warung-Signature`.

## API tahap 1

- Publik: `GET /api/menu`, `GET /api/products/search?q=...`.
- Pelanggan bearer token Sanctum: cart, cek pengiriman, checkout, pesanan, pembatalan, konfirmasi penerimaan, dan unggah bukti pembayaran.
- Bot memakai token ability `bot`: deduplikasi inbound, identifikasi pelanggan, sesi, pencocokan produk, dan alamat.
- Checkout memakai transaksi database, penguncian stok, harga snapshot, kode pembayaran unik, idempotency key opsional, validasi radius/jam/minimum order, dan log pergerakan stok.

Jalankan pemeriksaan dengan `php artisan test` dan `npm run build`.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
