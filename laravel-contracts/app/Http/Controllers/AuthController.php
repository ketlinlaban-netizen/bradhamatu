<?php

namespace App\Http\Controllers;

use App\Services\RouterService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * AuthController — Laravel Sanctum authentication
 *
 * Handles admin login/logout. All router operations remain read-only
 * regardless of the authenticated user's role.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!auth()->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid email or password',
            ], 401);
        }

        $user = auth()->user();
        $token = $user->createToken('admin-console')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
        ]);
    }
}
