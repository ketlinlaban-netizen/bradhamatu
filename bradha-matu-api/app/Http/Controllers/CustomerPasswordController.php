<?php

namespace App\Http\Controllers;

use App\Services\RadiusProvisioningClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CustomerPasswordController extends Controller
{
    public function __construct(
        private RadiusProvisioningClient $radiusProvisioning,
    ) {}

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|max:64|different:current_password',
        ]);

        $customer = $request->user();

        if (!Hash::check($validated['current_password'], $customer->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 422);
        }

        $serviceAccount = $customer->serviceAccounts()
            ->where('activation_status', 'active')
            ->latest()
            ->first();

        if ($serviceAccount) {
            if (! $this->radiusProvisioning->isConfigured()) {
                return response()->json(['error' => 'Network account updates are temporarily unavailable.'], 503);
            }

            try {
                $this->radiusProvisioning->changePassword($serviceAccount, $validated['new_password']);
            } catch (\RuntimeException) {
                Log::warning('Private RADIUS password update failed', [
                    'customer_id' => $customer->id,
                    'service_account_id' => $serviceAccount->id,
                ]);
                return response()->json(['error' => 'Network account update failed. The customer password was not changed.'], 502);
            }
        }

        $customer->update(['password' => $validated['new_password']]);

        return response()->json(['success' => true]);
    }
}
