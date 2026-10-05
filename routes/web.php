<?php

use App\Http\Controllers\AdminPaymentProofController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.pages.dashboard');
});

Route::get('/admin/orders/{orderNumber}/payment-proofs/{proof}', [AdminPaymentProofController::class, 'show'])
    ->middleware('auth')
    ->name('admin.orders.payment-proof');
