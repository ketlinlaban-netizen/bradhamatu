<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Purchase;

class InvoiceService
{
    /**
     * @return array{subtotal_minor: int, tax_minor: int, total_minor: int, tax_rate_percent: float, tax_inclusive: bool}
     */
    public function quote(int $baseAmountMinor): array
    {
        if ($baseAmountMinor < 0) {
            throw new \InvalidArgumentException('Purchase amount cannot be negative.');
        }

        $taxRate = AppSetting::getDecimal('tax_rate_percent', 0);
        if ($taxRate < 0 || $taxRate > 100) {
            throw new \RuntimeException('Configured tax rate must be between 0 and 100 percent.');
        }

        $taxInclusive = AppSetting::get('tax_inclusive', 'true') === 'true';
        if ($taxRate <= 0 || $baseAmountMinor === 0) {
            return [
                'subtotal_minor' => $baseAmountMinor,
                'tax_minor' => 0,
                'total_minor' => $baseAmountMinor,
                'tax_rate_percent' => $taxRate,
                'tax_inclusive' => $taxInclusive,
            ];
        }

        if ($taxInclusive) {
            $subtotalMinor = (int) round($baseAmountMinor / (1 + $taxRate / 100));
            $taxMinor = $baseAmountMinor - $subtotalMinor;
            $totalMinor = $baseAmountMinor;
        } else {
            $subtotalMinor = $baseAmountMinor;
            $taxMinor = (int) round($baseAmountMinor * $taxRate / 100);
            $totalMinor = $subtotalMinor + $taxMinor;
        }

        return [
            'subtotal_minor' => $subtotalMinor,
            'tax_minor' => $taxMinor,
            'total_minor' => $totalMinor,
            'tax_rate_percent' => $taxRate,
            'tax_inclusive' => $taxInclusive,
        ];
    }

    public function generateInvoiceNumber(): string
    {
        $prefix = AppSetting::get('invoice_prefix', 'BM');
        $year = now()->format('Y');
        $month = now()->format('m');

        $lastInvoice = Purchase::where('invoice_number', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderByDesc('invoice_number')
            ->first();

        $sequence = 1;
        if ($lastInvoice && preg_match('/-(\d+)$/', $lastInvoice->invoice_number, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $sequence);
    }

    public function applyInvoiceAndTax(Purchase $purchase): Purchase
    {
        if ($purchase->invoice_number) {
            return $purchase->fresh();
        }

        if ($purchase->subtotal_minor !== null && $purchase->tax_minor !== null) {
            $subtotalMinor = $purchase->subtotal_minor;
            $taxMinor = $purchase->tax_minor;
        } elseif ($purchase->status === 'voucher_redeemed' || $purchase->amount_minor === 0) {
            $subtotalMinor = 0;
            $taxMinor = 0;
        } else {
            // Historical purchases predate stored tax quotes; preserve the amount actually charged.
            $paidAmount = $purchase->amount_minor;
            $taxRate = AppSetting::getDecimal('tax_rate_percent', 0);
            $subtotalMinor = $taxRate > 0
                ? (int) round($paidAmount / (1 + $taxRate / 100))
                : $paidAmount;
            $taxMinor = $paidAmount - $subtotalMinor;
        }

        $purchase->update([
            'invoice_number' => $this->generateInvoiceNumber(),
            'subtotal_minor' => $subtotalMinor,
            'tax_minor' => $taxMinor,
        ]);

        return $purchase->fresh();
    }

    public function buildInvoiceData(Purchase $purchase): array
    {
        $companyName = AppSetting::get('company_name', 'Bradha Matu WiFi');
        $companyPhone = AppSetting::get('company_phone', '');
        $companyEmail = AppSetting::get('company_email', '');
        $taxRate = AppSetting::getDecimal('tax_rate_percent', 0);
        $currency = AppSetting::get('currency', 'KSh');

        return [
            'invoice_number' => $purchase->invoice_number,
            'date' => $purchase->created_at?->toDateString(),
            'company' => [
                'name' => $companyName,
                'phone' => $companyPhone,
                'email' => $companyEmail,
            ],
            'customer' => [
                'name' => $purchase->customer?->full_name,
                'phone' => $purchase->customer?->phone_display,
                'email' => $purchase->customer?->email,
            ],
            'package' => $purchase->package_snapshot,
            'subtotal' => $purchase->subtotal_minor / 100,
            'tax_rate' => $taxRate,
            'tax_amount' => $purchase->tax_minor / 100,
            'total' => $purchase->amount_minor / 100,
            'currency' => $currency,
            'status' => $purchase->status,
            'billing_period_start_at' => $purchase->billing_period_start_at?->toIso8601String(),
            'billing_period_end_at' => $purchase->billing_period_end_at?->toIso8601String(),
            'due_at' => $purchase->due_at?->toIso8601String(),
            'account_number' => $purchase->customer?->account_number,
            'location' => $purchase->customer?->location,
            'network_identity' => $purchase->networkIdentity ? [
                'friendly_name' => $purchase->networkIdentity->friendly_name,
                'mac_address' => $purchase->networkIdentity->mac_address,
                'ip_address' => $purchase->networkIdentity->ip_address,
                'router_name' => $purchase->networkIdentity->router?->name,
            ] : null,
        ];
    }
}
