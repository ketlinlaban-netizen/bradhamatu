<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Purchase;

class InvoiceService
{
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
        $taxRate = AppSetting::getDecimal('tax_rate_percent', 0);
        $taxInclusive = AppSetting::get('tax_inclusive', 'true') === 'true';

        $amountMinor = $purchase->amount_minor;

        if ($taxRate > 0) {
            if ($taxInclusive) {
                $subtotalMinor = (int) round($amountMinor / (1 + $taxRate / 100));
                $taxMinor = $amountMinor - $subtotalMinor;
            } else {
                $taxMinor = (int) round($amountMinor * $taxRate / 100);
                $subtotalMinor = $amountMinor;
            }
        } else {
            $subtotalMinor = $amountMinor;
            $taxMinor = 0;
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
        ];
    }
}
