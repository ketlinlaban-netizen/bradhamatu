<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:100',
            'phone' => 'required|string',
            'email' => 'nullable|email|max:255',
            'password' => 'required|string|min:6|max:255',
            'terms_accepted' => 'required|boolean|accepted',
        ]);

        try {
            $phoneNormalized = PhoneNormalizer::normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        if (Customer::where('phone_normalized', $phoneNormalized)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'An account with this phone number already exists',
            ], 409);
        }

        $customer = Customer::create([
            'id' => Str::uuid()->toString(),
            'full_name' => $validated['full_name'],
            'phone_normalized' => $phoneNormalized,
            'phone_display' => PhoneNormalizer::toDisplayFormat($phoneNormalized),
            'email' => $validated['email'] ?? null,
            'password' => $validated['password'],
            'status' => 'active',
            'terms_accepted_at' => now(),
            'policy_version' => '1.0',
        ]);

        $token = $customer->createToken('customer-portal')->plainTextToken;

        AuditLog::record('customer', $customer->id, 'customer_registered', 'customer', $customer->id, []);

        return response()->json([
            'success' => true,
            'token' => $token,
            'customer' => $this->customerResource($customer),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $phoneNormalized = PhoneNormalizer::normalize($validated['phone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number format',
            ], 422);
        }

        $customer = Customer::where('phone_normalized', $phoneNormalized)->first();

        if (! $customer || ! password_verify($validated['password'], $customer->password)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number or password',
            ], 401);
        }

        if ($customer->status !== 'active') {
            return response()->json([
                'success' => false,
                'error' => 'Account suspended. Please contact support.',
            ], 403);
        }

        $token = $customer->createToken('customer-portal')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'customer' => $this->customerResource($customer),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->customerResource($request->user()));
    }

    private function customerResource(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'full_name' => $customer->full_name,
            'phone' => $customer->phone_display,
            'email' => $customer->email,
            'status' => $customer->status,
            'registered_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}
