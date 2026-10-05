<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\Customer;
use App\Models\WaMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function dedupe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'type' => ['nullable', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
        ]);
        $message = WaMessage::query()->firstOrCreate(
            ['message_id' => $validated['message_id']],
            [
                'phone' => $this->normalizePhone($validated['phone']),
                'direction' => 'inbound',
                'type' => $validated['type'] ?? 'text',
                'payload' => $validated['payload'] ?? null,
                'processed_at' => now(),
            ],
        );

        return response()->json([
            'success' => true,
            'data' => ['duplicate' => ! $message->wasRecentlyCreated],
        ], $message->wasRecentlyCreated ? 201 : 200);
    }

    public function identifyCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $phone = $this->normalizePhone($validated['phone']);
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => $validated['name'] ?? null]);

        if (! $customer->name && isset($validated['name'])) {
            $customer->update(['name' => $validated['name']]);
        }

        return response()->json(['success' => true, 'data' => $customer]);
    }

    public function getSession(string $phone): JsonResponse
    {
        $session = ChatSession::query()->firstOrCreate(
            ['phone' => $this->normalizePhone($phone)],
            ['state' => 'IDLE', 'context' => [], 'last_activity_at' => now()],
        );

        return response()->json(['success' => true, 'data' => $session]);
    }

    public function updateSession(Request $request, string $phone): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'in:IDLE,BROWSING,AWAITING_CHOICE,AWAITING_LOCATION,AWAITING_CONFIRMATION,AWAITING_PAYMENT'],
            'context' => ['nullable', 'array'],
        ]);
        $session = ChatSession::query()->updateOrCreate(
            ['phone' => $this->normalizePhone($phone)],
            [
                'state' => $validated['state'],
                'context' => $validated['context'] ?? [],
                'last_activity_at' => now(),
            ],
        );

        return response()->json(['success' => true, 'data' => $session]);
    }

    public function addresses(string $phone): JsonResponse
    {
        $customer = Customer::query()->where('phone', $this->normalizePhone($phone))->firstOrFail();

        return response()->json(['success' => true, 'data' => $customer->addresses()->latest()->get()]);
    }

    public function saveAddress(Request $request, string $phone): JsonResponse
    {
        $customer = Customer::query()->where('phone', $this->normalizePhone($phone))->firstOrFail();
        $validated = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'full_address' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if ($validated['is_default'] ?? false) {
            $customer->addresses()->update(['is_default' => false]);
        }

        $address = $customer->addresses()->create($validated);

        return response()->json(['success' => true, 'data' => $address], 201);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        return $phone;
    }
}
