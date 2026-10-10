<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\ServiceAccount;
use App\Services\ActivationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBillingController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private ActivationService $activationService
    ) {}

    public function customers(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $customers = $query->withCount(['purchases', 'serviceAccounts'])
            ->latest()
            ->paginate(20);

        return response()->json($customers);
    }

    public function customerDetail(string $id): JsonResponse
    {
        $customer = Customer::with(['purchases.payments', 'purchases.serviceAccount', 'supportTickets'])
            ->findOrFail($id);

        return response()->json($customer);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with('purchase.customer');

        if ($state = $request->get('state')) {
            $query->where('state', $state);
        }

        $payments = $query->latest()->paginate(20);

        return response()->json($payments);
    }

    public function pendingPayments(): JsonResponse
    {
        $payments = Payment::where('state', 'pending')
            ->with('purchase.customer')
            ->latest()
            ->paginate(20);

        return response()->json($payments);
    }

    public function reconciliationQueue(): JsonResponse
    {
        $payments = Payment::whereIn('state', ['reconciliation_required', 'pending'])
            ->where(function ($q) {
                $q->where('state', 'reconciliation_required')
                  ->orWhere('initiated_at', '<', now()->subMinutes(5));
            })
            ->with('purchase.customer')
            ->latest()
            ->get();

        return response()->json(['payments' => $payments]);
    }

    public function reconcilePayment(Request $request, string $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        try {
            $payment = $this->paymentService->reconcilePayment($payment);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['payment' => $payment]);
    }

    public function retryActivation(Request $request, string $serviceAccountId): JsonResponse
    {
        $account = ServiceAccount::findOrFail($serviceAccountId);

        try {
            $account = $this->activationService->retryActivation($account);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['service_account' => $account]);
    }

    public function revenueReport(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $query = Payment::where('state', 'successful');

        $range = match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'all' => [null, null],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        if ($range[0]) $query->where('completed_at', '>=', $range[0]);
        if ($range[1]) $query->where('completed_at', '<=', $range[1]);

        $totalMinor = (clone $query)->sum('amount_minor');
        $count = (clone $query)->count();

        $failedCount = Payment::where('state', 'failed')->when($range[0], fn ($q) => $q->where('completed_at', '>=', $range[0]))->count();
        $pendingCount = Payment::where('state', 'pending')->count();
        $activeSubscribers = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVE)
            ->where('expires_at', '>', now())->count();
        $expiredCount = ServiceAccount::where('activation_status', ServiceAccount::STATUS_EXPIRED)->count();
        $activationFailures = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVATION_FAILED)->count();

        return response()->json([
            'period' => $period,
            'total_revenue_ksh' => $totalMinor / 100,
            'successful_payments' => $count,
            'failed_payments' => $failedCount,
            'pending_payments' => $pendingCount,
            'active_subscribers' => $activeSubscribers,
            'expired_packages' => $expiredCount,
            'activation_failures' => $activationFailures,
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $query = \App\Models\AuditLog::latest();

        if ($action = $request->get('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        return response()->json($query->paginate(50));
    }
}
