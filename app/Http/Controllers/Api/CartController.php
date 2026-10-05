<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->cartFor($request)->load('items.product')]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $product = Product::query()->where('is_active', true)->findOrFail($validated['product_id']);
        $cart = $this->cartFor($request);
        $item = $cart->items()->firstOrNew(['product_id' => $product->id]);
        $newQuantity = (int) $item->qty + $validated['qty'];

        if ($product->stock < $newQuantity) {
            throw ValidationException::withMessages(['qty' => "Stok {$product->name} tidak mencukupi."]);
        }

        $item->qty = $newQuantity;
        $item->save();

        return response()->json(['success' => true, 'data' => $item->load('product')], 201);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate(['qty' => ['required', 'integer', 'min:1', 'max:999']]);
        $cart = $this->cartFor($request);
        $cartItem = $cart->items()->with('product')->findOrFail($item);

        if ($cartItem->product->stock < $validated['qty']) {
            throw ValidationException::withMessages(['qty' => "Stok {$cartItem->product->name} tidak mencukupi."]);
        }

        $cartItem->update(['qty' => $validated['qty']]);

        return response()->json(['success' => true, 'data' => $cartItem->fresh('product')]);
    }

    public function removeItem(Request $request, int $item): JsonResponse
    {
        $this->cartFor($request)->items()->findOrFail($item)->delete();

        return response()->json(['success' => true, 'message' => 'Item dihapus dari keranjang.']);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cartFor($request)->items()->delete();

        return response()->json(['success' => true, 'message' => 'Keranjang dikosongkan.']);
    }

    private function cartFor(Request $request): Cart
    {
        $customer = $request->user();

        abort_unless($customer instanceof Customer, 403, 'Token pelanggan diperlukan.');

        return $customer->cart()->firstOrCreate([]);
    }
}
