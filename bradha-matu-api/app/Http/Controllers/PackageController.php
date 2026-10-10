<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = Package::orderBy('sort_order')->orderBy('price_minor')->get()
            ->map(fn ($p) => $this->resource($p));

        return response()->json(['packages' => $packages]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'price_minor' => 'required|integer|min:0',
            'duration_minutes' => 'required|integer|min:1',
            'access_type' => 'nullable|in:hotspot,pppoe',
            'download_limit_kbps' => 'nullable|integer|min:0',
            'upload_limit_kbps' => 'nullable|integer|min:0',
            'quota_mb' => 'nullable|integer|min:0',
            'max_devices' => 'nullable|integer|min:1',
            'router_id' => 'nullable|exists:routers,id',
            'is_enabled' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $package = Package::create(array_merge($validated, ['id' => Str::uuid()->toString()]));

        return response()->json($this->resource($package), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $package = Package::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'sometimes|nullable|string|max:1000',
            'price_minor' => 'sometimes|integer|min:0',
            'duration_minutes' => 'sometimes|integer|min:1',
            'access_type' => 'sometimes|in:hotspot,pppoe',
            'download_limit_kbps' => 'sometimes|integer|min:0',
            'upload_limit_kbps' => 'sometimes|integer|min:0',
            'quota_mb' => 'sometimes|nullable|integer|min:0',
            'max_devices' => 'sometimes|integer|min:1',
            'router_id' => 'sometimes|nullable|exists:routers,id',
            'is_enabled' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $package->update($validated);

        return response()->json($this->resource($package->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $package = Package::findOrFail($id);

        if ($package->purchases()->exists()) {
            $package->update(['is_enabled' => false]);
            return response()->json(['success' => true, 'message' => 'Package has purchases — disabled instead of deleted']);
        }

        $package->delete();
        return response()->json(['success' => true]);
    }

    public function publicIndex(): JsonResponse
    {
        $packages = Package::where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('price_minor')
            ->get()
            ->map(fn ($p) => $this->resource($p));

        return response()->json(['packages' => $packages]);
    }

    private function resource(Package $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price_ksh' => $p->price_minor / 100,
            'price_minor' => $p->price_minor,
            'duration_minutes' => $p->duration_minutes,
            'access_type' => $p->access_type,
            'download_kbps' => $p->download_limit_kbps,
            'upload_kbps' => $p->upload_limit_kbps,
            'quota_mb' => $p->quota_mb,
            'max_devices' => $p->max_devices,
            'is_enabled' => $p->is_enabled,
            'sort_order' => $p->sort_order,
        ];
    }
}
