<?php

namespace App\Http\Controllers;

use App\Models\Nas;
use App\Models\RadAcct;
use App\Models\RadPostAuth;
use App\Services\RadiusService;
use App\Models\Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RadiusController extends Controller
{
    public function __construct(
        private RadiusService $radiusService,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->radiusService->isEnabled(),
            'configured' => $this->radiusService->isConfigured(),
            'nas_count' => Nas::count(),
            'active_sessions' => $this->radiusService->getActiveSessionsCount(),
            'total_users' => \App\Models\RadCheck::distinct('username')->count('username'),
            'total_groups' => \App\Models\RadGroupReply::distinct('groupname')->count('groupname'),
        ]);
    }

    public function nasIndex(): JsonResponse
    {
        $nas = Nas::with('router:id,name,ip_address,identity,status_override,last_seen_at')
            ->orderBy('shortname')
            ->paginate(50);

        return response()->json($nas);
    }

    public function nasStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nasname' => 'required|string|ip|max:128',
            'shortname' => 'nullable|string|max:32',
            'secret' => 'required|string|min:8|max:60',
            'description' => 'nullable|string|max:200',
        ]);

        $nas = Nas::create([
            'nasname' => $validated['nasname'],
            'shortname' => $validated['shortname'] ?? null,
            'type' => config('radius.nas_type', 'mikrotik'),
            'ports' => 0,
            'secret' => $validated['secret'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($nas, 201);
    }

    public function nasDestroy(string $id): JsonResponse
    {
        $nas = Nas::findOrFail($id);
        $nas->delete();
        return response()->json(null, 204);
    }

    public function syncNas(): JsonResponse
    {
        $count = $this->radiusService->syncAllNas();
        return response()->json(['synced' => $count]);
    }

    public function activeSessions(Request $request): JsonResponse
    {
        $query = RadAcct::whereNull('acctstoptime')
            ->orderByDesc('acctstarttime');

        if ($username = $request->get('username')) {
            $query->where('username', $username);
        }
        if ($nasIp = $request->get('nas_ip')) {
            $query->where('nasipaddress', $nasIp);
        }

        return response()->json($query->paginate(50));
    }

    public function disconnectSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:64',
        ]);

        $count = $this->radiusService->disconnectSession($validated['username']);
        return response()->json(['disconnected' => $count]);
    }

    public function accountingHistory(Request $request): JsonResponse
    {
        $query = RadAcct::orderByDesc('acctstarttime');

        if ($username = $request->get('username')) {
            $query->where('username', $username);
        }
        if ($nasIp = $request->get('nas_ip')) {
            $query->where('nasipaddress', $nasIp);
        }
        if ($request->get('active') === 'true') {
            $query->whereNull('acctstoptime');
        }

        return response()->json($query->paginate(50));
    }

    public function userDataUsage(string $username): JsonResponse
    {
        $usage = $this->radiusService->getDataUsage($username);
        $sessions = $this->radiusService->getUserSessions($username, 10);

        return response()->json([
            'username' => $username,
            'usage' => $usage,
            'recent_sessions' => $sessions,
        ]);
    }

    public function postAuthLog(Request $request): JsonResponse
    {
        $query = RadPostAuth::orderByDesc('id');

        if ($username = $request->get('username')) {
            $query->where('username', $username);
        }

        return response()->json($query->paginate(50));
    }

    public function groups(): JsonResponse
    {
        $groups = \App\Models\RadGroupReply::select('groupname')
            ->distinct()
            ->orderBy('groupname')
            ->get()
            ->map(function ($g) {
                $replies = \App\Models\RadGroupReply::where('groupname', $g->groupname)->get();
                $checks = \App\Models\RadGroupCheck::where('groupname', $g->groupname)->get();
                $userCount = \App\Models\RadUserGroup::where('groupname', $g->groupname)->count();

                return [
                    'groupname' => $g->groupname,
                    'user_count' => $userCount,
                    'reply_attributes' => $replies,
                    'check_attributes' => $checks,
                ];
            });

        return response()->json(['groups' => $groups]);
    }
}
