<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\ServiceAccount;
use App\Services\ActivationService;
use App\Services\PaymentService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CustomerDashboardController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private ActivationService $activationService,
        private InvoiceService $invoiceService,
    ) {}

    public function packages(Request $request): JsonResponse
    {
        $packages = Package::where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('price_minor')
            ->get()
            ->map(fn ($p) => $this->packageResource($p));

        return response()->json(['packages' => $packages]);
    }

    public function paymentMethods(): JsonResponse
    {
        return response()->json(['methods' => $this->paymentService->availablePaymentMethods()]);
    }

    public function serviceStatus(Request $request): JsonResponse
    {
        $customer = $request->user();

        $activeAccount = ServiceAccount::where('customer_id', $customer->id)
            ->whereIn('activation_status', [ServiceAccount::STATUS_ACTIVE, ServiceAccount::STATUS_PENDING])
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        $latestExpired = ServiceAccount::where('customer_id', $customer->id)
            ->where('activation_status', ServiceAccount::STATUS_EXPIRED)
            ->latest()
            ->first();

        return response()->json([
            'active_package' => $activeAccount ? $this->serviceAccountResource($activeAccount) : null,
            'next_due_at' => $activeAccount?->expires_at?->toIso8601String(),
            'last_expired' => $latestExpired ? $this->serviceAccountResource($latestExpired) : null,
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $purchases = $request->user()
            ->purchases()
            ->with(['payments', 'serviceAccount'])
            ->latest()
            ->get()
            ->map(fn ($p) => $this->purchaseResource($p));

        return response()->json(['purchases' => $purchases]);
    }

    public function purchaseDetail(Request $request, string $id): JsonResponse
    {
        $purchase = $request->user()
            ->purchases()
            ->with(['payments', 'serviceAccount', 'package'])
            ->where('id', $id)
            ->first();

        if (! $purchase) {
            return response()->json(['error' => 'Purchase not found'], 404);
        }

        return response()->json($this->purchaseResource($purchase));
    }

    public function purchaseInvoice(Request $request, string $id): JsonResponse
    {
        $purchase = $request->user()
            ->purchases()
            ->with(['customer', 'package'])
            ->whereNotNull('invoice_number')
            ->where('id', $id)
            ->first();

        if (! $purchase) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        return response()->json($this->invoiceService->buildInvoiceData($purchase));
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = $request->user()
            ->purchases()
            ->with('payments')
            ->get()
            ->pluck('payments')
            ->flatten()
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($p) => $this->paymentResource($p));

        return response()->json(['payments' => $payments]);
    }

    public function renew(Request $request): JsonResponse
    {
        return $this->initiatePurchase($request);
    }

    public function initiatePurchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|string',
            'provider' => 'nullable|in:mpesa,pesapal,flutterwave,paystack',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::requiredIf(fn () => in_array($request->input('provider'), ['flutterwave', 'paystack'], true)),
            ],
        ]);

        $package = Package::where('id', $validated['package_id'])
            ->where('is_enabled', true)
            ->first();

        if (! $package) {
            return response()->json(['error' => 'Package not available'], 404);
        }

        $customer = $request->user();

        if ($customer->status !== 'active') {
            return response()->json(['error' => 'Account suspended'], 403);
        }

        try {
            $result = $this->paymentService->initiatePurchase(
                $customer,
                $validated['package_id'],
                $validated['provider'] ?? 'mpesa',
                $validated['email'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        } catch (\Throwable) {
            return response()->json(['error' => 'Unable to initiate the payment. Please try again.'], 500);
        }

        // Attempt immediate activation if payment is already successful (sandbox/manual)
        if ($result['payment']->state === 'successful') {
            try {
                $this->activationService->activate($result['purchase']);
            } catch (\Throwable $e) {
                // Activation will be retried by scheduler
            }
        }

        return response()->json([
            'purchase' => $this->purchaseResource($result['purchase']),
            'payment' => $this->paymentResource($result['payment']),
            'daraja_response' => $result['daraja_response'],
            'checkout_url' => $result['checkout_url'],
        ]);
    }

    public function paymentStatus(Request $request, string $id): JsonResponse
    {
        $payment = $request->user()
            ->purchases()
            ->with('payments')
            ->get()
            ->pluck('payments')
            ->flatten()
            ->firstWhere('id', $id);

        if (! $payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        return response()->json($this->paymentResource($payment->fresh()));
    }

    public function supportTickets(Request $request): JsonResponse
    {
        $tickets = $request->user()->supportTickets()->latest()->get();

        return response()->json(['tickets' => $tickets]);
    }

    public function createSupportTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'priority' => 'nullable|in:normal,urgent',
        ]);

        $ticket = $request->user()->supportTickets()->create([
            'id' => Str::uuid()->toString(),
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'status' => 'open',
            'priority' => $validated['priority'] ?? 'normal',
        ]);

        return response()->json(['ticket' => $ticket], 201);
    }

    private function packageResource(Package $p): array
    {
        $quote = $this->invoiceService->quote((int) $p->price_minor);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price_ksh' => $p->price_minor / 100,
            'price_minor' => $p->price_minor,
            'checkout_total_ksh' => $quote['total_minor'] / 100,
            'checkout_tax_ksh' => $quote['tax_minor'] / 100,
            'tax_inclusive' => $quote['tax_inclusive'],
            'duration_minutes' => $p->duration_minutes,
            'duration_display' => $this->formatDuration($p->duration_minutes),
            'access_type' => $p->access_type,
            'download_kbps' => $p->download_limit_kbps,
            'upload_kbps' => $p->upload_limit_kbps,
            'max_devices' => $p->max_devices,
        ];
    }

    private function purchaseResource($p): array
    {
        return [
            'id' => $p->id,
            'order_id' => $p->order_id,
            'status' => $p->status,
            'amount_ksh' => $p->amount_minor / 100,
            'package_name' => $p->package_snapshot['name'] ?? 'Unknown',
            'created_at' => $p->created_at?->toIso8601String(),
            'completed_at' => $p->completed_at?->toIso8601String(),
            'invoice_number' => $p->invoice_number,
            'payment' => $p->payments->isNotEmpty() ? $this->paymentResource($p->payments->first()) : null,
            'service_account' => $p->serviceAccount ? $this->serviceAccountResource($p->serviceAccount) : null,
        ];
    }

    private function paymentResource($p): array
    {
        return [
            'id' => $p->id,
            'state' => $p->state,
            'amount_ksh' => $p->amount_minor / 100,
            'provider' => $p->provider,
            'provider_reference' => $p->provider_reference,
            'failure_reason' => $p->failure_reason,
            'initiated_at' => $p->initiated_at?->toIso8601String(),
            'completed_at' => $p->completed_at?->toIso8601String(),
        ];
    }

    private function serviceAccountResource($sa): array
    {
        $snapshot = $sa->purchase?->package_snapshot ?? [];

        return [
            'id' => $sa->id,
            'package_id' => $sa->purchase?->package_id,
            'package_name' => $snapshot['name'] ?? null,
            'price_ksh' => isset($snapshot['price_minor']) ? $snapshot['price_minor'] / 100 : null,
            'activation_status' => $sa->activation_status,
            'access_type' => $sa->access_type,
            'activated_at' => $sa->activated_at?->toIso8601String(),
            'expires_at' => $sa->expires_at?->toIso8601String(),
            'deactivation_reason' => $sa->deactivation_reason,
        ];
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes < 60) return "{$minutes} min";
        if ($minutes < 1440) {
            $h = intdiv($minutes, 60);
            return "{$h} hr" . ($h > 1 ? 's' : '');
        }
        $d = intdiv($minutes, 1440);
        return "{$d} day" . ($d > 1 ? 's' : '');
    }
}
