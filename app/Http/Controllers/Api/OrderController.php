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

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $query = $customer->orders()->with(['items', 'payment'])->latest();

        if ($request->query('status') === 'active') {
            $query->whereIn('status', ['pending_payment', 'paid', 'shipping']);
        }

        return response()->json(['success' => true, 'data' => $query->paginate(15)]);
    }

    public function store(Request $request, OrderCheckoutService $checkout, QrisPayloadService $qris): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'in:whatsapp,mobile,web,pos'],
            'payment_method' => ['nullable', 'in:transfer,qris'],
        ]);
        $order = $checkout->checkout(
            $this->customer($request),
            $validated,
            $request->header('Idempotency-Key'),
        );
        $this->includeQrisImage($order, $qris);

        return response()->json(['success' => true, 'data' => $order], 201);
    }

    public function show(Request $request, string $number, QrisPayloadService $qris): JsonResponse
    {
        $order = $this->customer($request)->orders()
            ->with(['items', 'payment.proofs', 'address'])
            ->where('order_number', $number)
            ->firstOrFail();
        $this->includeQrisImage($order, $qris);

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function cancel(Request $request, string $number): JsonResponse
    {
        $order = $this->customer($request)->orders()->where('order_number', $number)->firstOrFail();

        if ($order->status !== 'pending_payment') {
            throw ValidationException::withMessages([
                'order' => 'Pesanan hanya dapat dibatalkan pelanggan sebelum pembayaran diverifikasi.',
            ]);
        }

        $order->transitionTo('cancelled', null, 'Dibatalkan oleh pelanggan.');

        return response()->json(['success' => true, 'data' => $order->fresh()]);
    }

    public function confirmReceived(Request $request, string $number): JsonResponse
    {
        $order = $this->customer($request)->orders()->where('order_number', $number)->firstOrFail();
        $order->transitionTo('completed', null, 'Dikonfirmasi diterima oleh pelanggan.');

        return response()->json(['success' => true, 'data' => $order->fresh()]);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user();

        abort_unless($customer instanceof Customer, 403, 'Token pelanggan diperlukan.');

        return $customer;
    }

    private function includeQrisImage(Order $order, QrisPayloadService $qris): void
    {
        if ($order->payment && filled($order->payment->qris_payload)) {
            $order->payment->setAttribute('qris_image', $qris->renderDataUri($order->payment->qris_payload));
        }
    }
}
