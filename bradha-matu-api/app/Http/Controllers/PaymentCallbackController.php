<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * Daraja STK Push callback endpoint.
     * Public — validated by matching provider identifiers to existing pending payments.
     * Idempotent — duplicate callbacks are safe.
     */
    public function mpesaCallback(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Enforce reasonable request size
        if (strlen($request->getContent()) > 10000) {
            Log::warning('Daraja callback rejected — oversized payload');
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Rejected']);
        }

        // Validate basic schema
        if (! isset($payload['Body']['stkCallback']['CheckoutRequestID'])) {
            Log::warning('Daraja callback rejected — missing CheckoutRequestID');
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Rejected']);
        }

        try {
            $payment = $this->paymentService->processCallback($payload);

            if (! $payment) {
                Log::warning('Daraja callback — unmatched payment');
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Received']);
            }
        } catch (\Throwable $e) {
            Log::error('Daraja callback processing error', ['error' => $e->getMessage()]);
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Processing error']);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Processed']);
    }
}
