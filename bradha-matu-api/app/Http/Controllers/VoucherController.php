<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\Package;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(
        private VoucherService $voucherService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Voucher::with('package');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($batch = $request->get('batch')) {
            $query->where('batch_id', $batch);
        }

        return response()->json($query->latest()->paginate(50));
    }

    public function generateBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'quantity' => 'required|integer|min:1|max:500',
        ]);

        $package = Package::findOrFail($validated['package_id']);
        $vouchers = $this->voucherService->generateBatch($package, $validated['quantity']);

        return response()->json(['vouchers' => $vouchers, 'count' => count($vouchers)], 201);
    }

    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $customer = $request->user();

        try {
            $purchase = $this->voucherService->redeem($validated['code'], $customer);
            return response()->json(['purchase' => $purchase], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
