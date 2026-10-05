<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderCheckoutService;
use App\Services\QrisPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BotOrderController extends Controller
{
    public function index(Request $request, string $phone): JsonResponse
    {
        $orders = $this->customer($phone)->orders()
            ->with(['items', 'payment'])
            ->when($request->query('status') === 'active', fn ($query) => $query->whereIn('status', [
                'pending_payment', 'paid', 'shipping',
            ]))
            ->latest()
            ->limit(20)
            ->get();

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function store(
        Request $request,
        string $phone,
        OrderCheckoutService $checkout,
        QrisPayloadService $qris,
    ): JsonResponse {
        $validated = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'in:transfer,qris'],
        ]);
        $order = $checkout->checkout(
            $this->customer($phone),
            [...$validated, 'channel' => 'whatsapp'],
            $request->header('Idempotency-Key'),
        );
        $this->includeQrisImage($order, $qris);

        return response()->json(['success' => true, 'data' => $order], 201);
    }

    public function show(string $phone, string $number, QrisPayloadService $qris): JsonResponse
    {
        $order = $this->customer($phone)->orders()
            ->with(['items', 'payment.proofs', 'address'])
            ->where('order_number', $number)
            ->firstOrFail();
        $this->includeQrisImage($order, $qris);

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function cancel(string $phone, string $number): JsonResponse
    {
        $order = $this->customer($phone)->orders()->where('order_number', $number)->firstOrFail();

        if ($order->status !== 'pending_payment') {
            throw ValidationException::withMessages([
                'order' => 'Pesanan hanya dapat dibatalkan sebelum pembayaran diverifikasi.',
            ]);
        }

        $order->transitionTo('cancelled', null, 'Dibatalkan melalui bot.');

        return response()->json(['success' => true, 'data' => $order->fresh()]);
    }

    public function confirmReceived(string $phone, string $number): JsonResponse
    {
        $order = $this->customer($phone)->orders()->where('order_number', $number)->firstOrFail();
        $order->transitionTo('completed', null, 'Dikonfirmasi diterima melalui bot.');

        return response()->json(['success' => true, 'data' => $order->fresh()]);
    }

    public function paymentProof(Request $request, string $phone, string $number): JsonResponse
    {
        $order = $this->customer($phone)->orders()->with('payment')->where('order_number', $number)->firstOrFail();

        if ($order->status !== 'pending_payment' || ! $order->payment) {
            throw ValidationException::withMessages(['payment' => 'Bukti pembayaran tidak dapat diterima untuk pesanan ini.']);
        }

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $path = $validated['proof']->store('payment-proofs', 'local');
        $order->payment->proofs()->create([
            'file_path' => $path,
            'source' => 'whatsapp',
        ]);
        $order->payment->update(['status' => 'submitted']);

        return response()->json([
            'success' => true,
            'message' => 'Bukti pembayaran diterima dan menunggu verifikasi admin.',
        ], 201);
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

    private function includeQrisImage(Order $order, QrisPayloadService $qris): void
    {
        if ($order->payment && filled($order->payment->qris_payload)) {
            $order->payment->setAttribute('qris_image', $qris->renderDataUri($order->payment->qris_payload));
        }
    }
}
