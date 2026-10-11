<?php

namespace App\Http\Controllers;

use App\Models\NetworkClient;
use App\Models\Router;
use App\Services\ClientInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClientAliasController extends Controller
{
    public function update(Request $request, Router $router, NetworkClient $client, ClientInventory $inventory): JsonResponse
    {
        // Verify client belongs to router (authorization check)
        if ((string) $client->router_id !== (string) $router->id) {
            abort(404);
        }

        $alias = $request->input('alias');

        if (is_string($alias)) {
            $alias = trim($alias);
        }

        $validated = Validator::make(
            ['alias' => $alias],
            ['alias' => ['required', 'string', 'max:'.NetworkClient::ALIAS_MAX_LENGTH]],
        )->validate();

        $client->forceFill(['alias' => $validated['alias']])->save();

        return response()->json($inventory->present($client->fresh('router')));
    }

    public function destroy(Router $router, NetworkClient $client, ClientInventory $inventory): JsonResponse
    {
        // Verify client belongs to router (authorization check)
        if ((string) $client->router_id !== (string) $router->id) {
            abort(404);
        }

        $client->forceFill(['alias' => null])->save();

        return response()->json($inventory->present($client->fresh('router')));
    }
}
