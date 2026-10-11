<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = AppSetting::where('key', '!=', 'sms_api_key')->get()->pluck('value', 'key');

        return response()->json([
            'settings' => $settings,
            'sms_api_key_configured' => filled(AppSetting::get('sms_api_key')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        if (array_key_exists('sms_api_key', $validated['settings'])) {
            return response()->json([
                'message' => 'Manage SMS credentials through the SMS settings endpoint.',
            ], 422);
        }

        foreach ($validated['settings'] as $key => $value) {
            AppSetting::set($key, (string) $value);
        }

        return response()->json(['success' => true]);
    }
}
