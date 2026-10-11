<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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

    public function pesapalIpn(Request $request): JsonResponse
    {
        return $this->hostedCallback($request, 'pesapal');
    }

    public function flutterwaveWebhook(Request $request): JsonResponse
    {
        return $this->hostedCallback($request, 'flutterwave');
    }

    public function paystackWebhook(Request $request): JsonResponse
    {
        return $this->hostedCallback($request, 'paystack');
    }

    private function hostedCallback(Request $request, string $provider): JsonResponse
    {
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['error' => 'Rejected'], 413);
        }

        try {
            $gateway = match ($provider) {
                'pesapal' => app(\App\Services\PesapalGatewayClient::class),
                'flutterwave' => app(\App\Services\FlutterwaveGatewayClient::class),
                'paystack' => app(\App\Services\PaystackGatewayClient::class),
            };
            $callback = $gateway->callbackReference($request);
            if ($callback !== null) {
                $this->paymentService->processHostedCallback($provider, $callback);
            }
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'Invalid notification'], 401);
        } catch (Throwable) {
            Log::warning('Hosted payment notification could not be processed', ['provider' => $provider]);
            return response()->json(['error' => 'Notification processing failed'], 500);
        }

        return response()->json(['status' => 200]);
    }
}
