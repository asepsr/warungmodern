<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BotCartController extends Controller
{
    public function show(string $phone): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->cartFor($phone)->load('items.product'),
        ]);
    }

    public function addItem(Request $request, string $phone): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $product = Product::query()->where('is_active', true)->findOrFail($validated['product_id']);
        $item = $this->cartFor($phone)->items()->firstOrNew(['product_id' => $product->id]);
        $newQuantity = (int) $item->qty + $validated['qty'];

        if ($product->stock < $newQuantity) {
            throw ValidationException::withMessages(['qty' => "Stok {$product->name} tidak mencukupi."]);
        }

        $item->qty = $newQuantity;
        $item->save();

        return response()->json(['success' => true, 'data' => $item->load('product')], 201);
    }

    public function updateItem(Request $request, string $phone, int $item): JsonResponse
    {
        $validated = $request->validate(['qty' => ['required', 'integer', 'min:1', 'max:999']]);
        $cartItem = $this->cartFor($phone)->items()->with('product')->findOrFail($item);

        if ($cartItem->product->stock < $validated['qty']) {
            throw ValidationException::withMessages(['qty' => "Stok {$cartItem->product->name} tidak mencukupi."]);
        }

        $cartItem->update(['qty' => $validated['qty']]);

        return response()->json(['success' => true, 'data' => $cartItem->fresh('product')]);
    }

    public function removeItem(string $phone, int $item): JsonResponse
    {
        $this->cartFor($phone)->items()->findOrFail($item)->delete();

        return response()->json(['success' => true, 'message' => 'Item dihapus dari keranjang.']);
    }

    public function clear(string $phone): JsonResponse
    {
        $this->cartFor($phone)->items()->delete();

        return response()->json(['success' => true, 'message' => 'Keranjang dikosongkan.']);
    }

    private function cartFor(string $phone): Cart
    {
        $customer = $this->customer($phone);

        return $customer->cart()->firstOrCreate([]);
    }

    private function customer(string $phone): Customer
    {
        return Customer::query()->where('phone', $this->normalizePhone($phone))->firstOrFail();
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($phone, '0') ? '62'.substr($phone, 1) : $phone;
    }
}
