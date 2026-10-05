<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentProofController extends Controller
{
    public function store(Request $request, string $number): JsonResponse
    {
        $customer = $request->user();

        abort_unless($customer instanceof Customer, 403, 'Token pelanggan diperlukan.');

        $order = $customer->orders()->with('payment')->where('order_number', $number)->firstOrFail();

        if ($order->status !== 'pending_payment' || ! $order->payment) {
            throw ValidationException::withMessages(['payment' => 'Bukti pembayaran tidak dapat diterima untuk pesanan ini.']);
        }

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $path = $validated['proof']->store('payment-proofs', 'local');
        $order->payment->proofs()->create([
            'file_path' => $path,
            'source' => 'app',
        ]);
        $order->payment->update(['status' => 'submitted']);

        return response()->json([
            'success' => true,
            'message' => 'Bukti pembayaran diterima dan menunggu verifikasi admin.',
        ], 201);
    }
}
