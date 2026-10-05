<?php

use App\Http\Controllers\Api\BotCartController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\BotOrderController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentProofController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('/menu', [ProductController::class, 'menu'])->middleware('throttle:60,1');
Route::get('/products/search', [ProductController::class, 'search'])->middleware('throttle:60,1');

Route::prefix('bot')->middleware(['auth:sanctum', CheckAbilities::class.':bot', 'throttle:120,1'])->group(function () {
    Route::post('/inbound/dedupe', [BotController::class, 'dedupe']);
    Route::post('/customers/identify', [BotController::class, 'identifyCustomer']);
    Route::get('/sessions/{phone}', [BotController::class, 'getSession']);
    Route::put('/sessions/{phone}', [BotController::class, 'updateSession']);
    Route::post('/products/match', [ProductController::class, 'match']);
    Route::post('/delivery/check', [DeliveryController::class, 'check']);
    Route::get('/customers/{phone}/addresses', [BotController::class, 'addresses']);
    Route::post('/customers/{phone}/addresses', [BotController::class, 'saveAddress']);
    Route::get('/customers/{phone}/cart', [BotCartController::class, 'show']);
    Route::post('/customers/{phone}/cart/items', [BotCartController::class, 'addItem']);
    Route::patch('/customers/{phone}/cart/items/{item}', [BotCartController::class, 'updateItem']);
    Route::delete('/customers/{phone}/cart/items/{item}', [BotCartController::class, 'removeItem']);
    Route::delete('/customers/{phone}/cart', [BotCartController::class, 'clear']);
    Route::get('/customers/{phone}/orders', [BotOrderController::class, 'index']);
    Route::post('/customers/{phone}/orders', [BotOrderController::class, 'store']);
    Route::get('/customers/{phone}/orders/{number}', [BotOrderController::class, 'show']);
    Route::post('/customers/{phone}/orders/{number}/cancel', [BotOrderController::class, 'cancel']);
    Route::post('/customers/{phone}/orders/{number}/confirm-received', [BotOrderController::class, 'confirmReceived']);
    Route::post('/customers/{phone}/orders/{number}/payment-proof', [BotOrderController::class, 'paymentProof']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/cart', [CartController::class, 'show']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem']);
    Route::delete('/cart', [CartController::class, 'clear']);

    Route::post('/delivery/check', [DeliveryController::class, 'check']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/orders/{number}', [OrderController::class, 'show']);
    Route::post('/orders/{number}/payment-proof', [PaymentProofController::class, 'store']);
    Route::post('/orders/{number}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{number}/confirm-received', [OrderController::class, 'confirmReceived']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
