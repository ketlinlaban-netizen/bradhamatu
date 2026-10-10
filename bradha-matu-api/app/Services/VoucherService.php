<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Package;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherService
{
    public function __construct(
        private ActivationService $activationService,
        private InvoiceService $invoiceService,
    ) {}

    public function generateBatch(Package $package, int $quantity, ?string $createdBy = null): array
    {
        $batchId = 'VB-' . now()->format('YmdHis');
        $vouchers = [];

        for ($i = 0; $i < $quantity; $i++) {
            $vouchers[] = Voucher::create([
                'id' => Str::uuid()->toString(),
                'code' => Voucher::generateCode(),
                'package_id' => $package->id,
                'status' => Voucher::STATUS_UNUSED,
                'created_by' => $createdBy,
                'batch_id' => $batchId,
            ]);
        }

        return $vouchers;
    }

    public function redeem(string $code, Customer $customer): Purchase
    {
        return DB::transaction(function () use ($code, $customer) {
            $voucher = Voucher::where('code', $code)
                ->where('status', Voucher::STATUS_UNUSED)
                ->lockForUpdate()
                ->first();

            if (!$voucher) {
                throw new \RuntimeException('Voucher code is invalid or already used');
            }

            $package = $voucher->package;
            $snapshot = $package->toSnapshot();

            $purchase = Purchase::create([
                'id' => Str::uuid()->toString(),
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'package_snapshot' => $snapshot,
                'order_id' => 'VCH-' . Str::upper(Str::random(10)),
                'amount_minor' => 0,
                'status' => 'voucher_redeemed',
                'completed_at' => now(),
            ]);

            $this->invoiceService->applyInvoiceAndTax($purchase);

            $voucher->update([
                'status' => Voucher::STATUS_USED,
                'customer_id' => $customer->id,
                'used_at' => now(),
            ]);

            $this->activationService->activate($purchase);

            return $purchase->fresh();
        });
    }
}
