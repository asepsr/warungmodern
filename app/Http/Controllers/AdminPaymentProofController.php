<?php

namespace App\Http\Controllers;

use App\Models\PaymentProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminPaymentProofController extends Controller
{
    public function show(Request $request, string $orderNumber, PaymentProof $proof): BinaryFileResponse
    {
        abort_unless($request->user()->hasAnyRole(['owner', 'kasir']), 403);
        abort_unless(
            $proof->payment()->whereHas('order', fn ($query) => $query->where('order_number', $orderNumber))->exists(),
            404,
        );

        $path = Storage::disk('local')->path($proof->file_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Disposition' => 'inline']);
    }
}
