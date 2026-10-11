<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\WalletTransaction;
use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function topUp(Customer $customer, int $amountMinor, ?string $description = null, ?Payment $payment = null): WalletTransaction
    {
        return DB::transaction(function () use ($customer, $amountMinor, $description, $payment) {
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $newBalance = $customer->balance_minor + $amountMinor;
            $customer->update(['balance_minor' => $newBalance]);

            return WalletTransaction::create([
                'id' => Str::uuid()->toString(),
                'id' => Str::uuid()->toString(),
                'customer_id' => $customer->id,
                'type' => 'topup',
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $newBalance,
                'description' => $description ?? 'Wallet top-up',
                'payment_id' => $payment?->id,
            ]);
        });
    }

    public function debit(Customer $customer, int $amountMinor, string $description, ?Purchase $purchase = null): WalletTransaction
    {
        return DB::transaction(function () use ($customer, $amountMinor, $description, $purchase) {
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if ($customer->balance_minor < $amountMinor) {
                throw new \RuntimeException('Insufficient wallet balance');
            }

            $newBalance = $customer->balance_minor - $amountMinor;
            $customer->update(['balance_minor' => $newBalance]);

            return WalletTransaction::create([
                'id' => Str::uuid()->toString(),
                'id' => Str::uuid()->toString(),
                'customer_id' => $customer->id,
                'type' => 'purchase',
                'amount_minor' => -$amountMinor,
                'balance_after_minor' => $newBalance,
                'description' => $description,
                'purchase_id' => $purchase?->id,
            ]);
        });
    }

    public function refund(Customer $customer, int $amountMinor, string $description, ?Purchase $purchase = null): WalletTransaction
    {
        return DB::transaction(function () use ($customer, $amountMinor, $description, $purchase) {
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $newBalance = $customer->balance_minor + $amountMinor;
            $customer->update(['balance_minor' => $newBalance]);

            return WalletTransaction::create([
                'id' => Str::uuid()->toString(),
                'id' => Str::uuid()->toString(),
                'customer_id' => $customer->id,
                'type' => 'refund',
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $newBalance,
                'description' => $description,
                'purchase_id' => $purchase?->id,
            ]);
        });
    }

    public function hasSufficientBalance(Customer $customer, int $amountMinor): bool
    {
        return $customer->balance_minor >= $amountMinor;
    }
}
