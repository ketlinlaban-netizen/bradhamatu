<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        $customer = $request->user();
        return response()->json([
            'balance_minor' => $customer->balance_minor,
            'balance_ksh' => $customer->balance_minor / 100,
            'auto_renewal' => $customer->auto_renewal,
        ]);
    }

    public function toggleAutoRenewal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $request->user()->update(['auto_renewal' => $validated['enabled']]);
        return response()->json(['auto_renewal' => $validated['enabled']]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = WalletTransaction::where('customer_id', $request->user()->id)
            ->latest()
            ->paginate(50);

        return response()->json($transactions);
    }

    public function topUp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:100',
            'payment_id' => 'nullable|exists:payments,id',
        ]);

        $customer = $request->user();
        $payment = $request->get('payment_id') ? \App\Models\Payment::find($validated['payment_id']) : null;

        $txn = $this->walletService->topUp($customer, $validated['amount_minor'], 'Wallet top-up via M-Pesa', $payment);

        return response()->json(['transaction' => $txn], 201);
    }
}
