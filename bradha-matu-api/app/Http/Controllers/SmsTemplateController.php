<?php

namespace App\Http\Controllers;

use App\Services\SmsService;
use App\Models\SmsMessage;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsTemplateController extends Controller
{
    public function __construct(
        private SmsService $smsService,
    ) {}

    public function templates(): JsonResponse
    {
        return response()->json(['templates' => SmsService::TEMPLATES]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'sms_endpoint' => AppSetting::get('sms_endpoint'),
            'sms_sender_id' => AppSetting::get('sms_sender_id', 'BradhaMatu'),
            'sms_api_key_configured' => !empty(AppSetting::get('sms_api_key')),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sms_endpoint' => 'nullable|string|url',
            'sms_sender_id' => 'nullable|string|max:20',
            'sms_api_key' => 'nullable|string|max:200',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                AppSetting::set($key, $value);
            }
        }

        return response()->json(['success' => true]);
    }

    public function history(Request $request): JsonResponse
    {
        $query = SmsMessage::with('customer');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->latest()->paginate(50));
    }
}
