<?php

namespace App\Http\Controllers;

use App\Services\HotspotUserManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CustomerPasswordController extends Controller
{
    public function __construct(
        private HotspotUserManager $hotspotManager,
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

        $customer->update(['password' => $validated['new_password']]);

        $serviceAccount = $customer->serviceAccounts()
            ->where('activation_status', 'active')
            ->latest()
            ->first();

        if ($serviceAccount && $serviceAccount->router && $this->hotspotManager->isConfigured()) {
            try {
                $client = $this->hotspotManager->connectToRouter($serviceAccount->router);
                $profileName = "bm-{$serviceAccount->rate_limit_rx_kbps}-{$serviceAccount->rate_limit_tx_kbps}";
                if ($serviceAccount->access_type === 'pppoe') {
                    $this->hotspotManager->createOrUpdatePppoeSecret(
                        $client,
                        $serviceAccount->username,
                        $validated['new_password'],
                        $profileName,
                    );
                } else {
                    $this->hotspotManager->createOrUpdateUser($client, $serviceAccount->username, $validated['new_password'], $profileName);
                }
                $client->disconnect();
            } catch (\Throwable $e) {
                Log::warning('Failed to sync password to router', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['success' => true]);
    }
}
