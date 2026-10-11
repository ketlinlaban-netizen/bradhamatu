<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\ServiceAccount;
use App\Services\ActivationService;
use App\Services\PaymentService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBillingController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private ActivationService $activationService,
        private InvoiceService $invoiceService,
    ) {}

    public function customers(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('account_number', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        $customers = $query->withCount(['purchases', 'serviceAccounts'])
            ->latest()
            ->paginate(20)
            ->through(fn (Customer $customer): array => [
                'id' => $customer->id,
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'phone_display' => $customer->phone_display,
                'email' => $customer->email,
                'location' => $customer->location,
                'status' => $customer->status,
                'created_at' => $customer->created_at?->toIso8601String(),
                'purchases_count' => $customer->purchases_count,
                'service_accounts_count' => $customer->service_accounts_count,
            ]);

        return response()->json($customers);
    }

    public function customerDetail(string $id): JsonResponse
    {
        $customer = Customer::with([
            'networkIdentities.router:id,name,ip_address,status_override,last_error_category',
            'networkIdentities.networkClient',
            'purchases.payments',
            'purchases.serviceAccount',
            'purchases.networkIdentity.router:id,name,ip_address,status_override,last_error_category',
            'supportTickets',
        ])
            ->findOrFail($id);

        return response()->json([
            'id' => $customer->id,
            'account_number' => $customer->account_number,
            'full_name' => $customer->full_name,
            'phone_display' => $customer->phone_display,
            'email' => $customer->email,
            'location' => $customer->location,
            'notes' => $customer->notes,
            'status' => $customer->status,
            'network_identities' => $customer->networkIdentities,
            'purchases' => $customer->purchases,
            'support_tickets' => $customer->supportTickets,
        ]);
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

    public function invoices(): JsonResponse
    {
        $invoices = Purchase::whereNotNull('invoice_number')
            ->with(['customer:id,account_number,full_name,phone_display,email,location', 'payments', 'networkIdentity.router:id,name'])
            ->latest()
            ->paginate(20);

        return response()->json($invoices);
    }

    public function invoice(string $id): JsonResponse
    {
        $purchase = Purchase::whereNotNull('invoice_number')
            ->with(['customer', 'package'])
            ->findOrFail($id);

        return response()->json($this->invoiceService->buildInvoiceData($purchase));
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

    public function activationFailures(): JsonResponse
    {
        $accounts = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVATION_FAILED)
            ->with(['customer:id,full_name,phone_display', 'purchase:id,order_id,amount_minor'])
            ->latest()
            ->paginate(20);

        return response()->json($accounts);
    }

    public function revenueReport(Request $request): JsonResponse
    {
        $period = $request->validate([
            'period' => 'sometimes|in:today,week,month,all',
        ])['period'] ?? 'today';

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

        $failedCount = Payment::where('state', 'failed')
            ->when($range[0], fn ($q) => $q->where('completed_at', '>=', $range[0]))
            ->when($range[1], fn ($q) => $q->where('completed_at', '<=', $range[1]))
            ->count();
        $pendingCount = Payment::where('state', 'pending')->count();
        $activeSubscribers = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVE)
            ->where('expires_at', '>', now())->count();
        $expiredCount = ServiceAccount::where('activation_status', ServiceAccount::STATUS_EXPIRED)->count();
        $activationFailures = ServiceAccount::where('activation_status', ServiceAccount::STATUS_ACTIVATION_FAILED)->count();
        $trendStart = now()->subDays(6)->startOfDay();
        $dailyTotals = Payment::where('state', 'successful')
            ->whereBetween('completed_at', [$trendStart, now()->endOfDay()])
            ->selectRaw('DATE(completed_at) as day, SUM(amount_minor) as amount_minor, COUNT(*) as payment_count')
            ->groupByRaw('DATE(completed_at)')
            ->get()
            ->keyBy('day');
        $dailyRevenue = collect(range(0, 6))->map(function (int $offset) use ($trendStart, $dailyTotals): array {
            $day = $trendStart->copy()->addDays($offset)->toDateString();
            $total = $dailyTotals->get($day);

            return [
                'date' => $day,
                'revenue_ksh' => (int) ($total->amount_minor ?? 0) / 100,
                'successful_payments' => (int) ($total->payment_count ?? 0),
            ];
        });

        return response()->json([
            'period' => $period,
            'total_revenue_ksh' => $totalMinor / 100,
            'successful_payments' => $count,
            'failed_payments' => $failedCount,
            'pending_payments' => $pendingCount,
            'active_subscribers' => $activeSubscribers,
            'expired_packages' => $expiredCount,
            'activation_failures' => $activationFailures,
            'daily_revenue' => $dailyRevenue,
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
