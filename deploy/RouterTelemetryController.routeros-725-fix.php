<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterApiException;
use App\Services\RouterTelemetryService;
use App\Support\IpAddress;
use App\Support\MacAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;

class RouterTelemetryController extends Controller
{
    public function __construct(private RouterTelemetryService $telemetry) {}

    public function ingest(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $maxBytes = min(49152, max(1024, (int) config('mikrotik.telemetry_max_payload_bytes', 49152)));
        if (strlen($raw) > $maxBytes) {
            return response()->json(['error' => 'Telemetry payload is too large.'], 413);
        }
        if (! $request->isJson()) {
            return response()->json(['error' => 'Telemetry content type must be application/json.'], 415);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'Telemetry body is not valid JSON.'], 400);
        }
        if (! is_array($payload)
            || array_is_list($payload)
            || ! isset($payload['router_id'])
            || ! is_string($payload['router_id'])
            || ! Str::isUuid($payload['router_id'])) {
            return $this->authenticationFailure();
        }
        foreach ([
            'interfaces' => ['id', 'name', 'type', 'running', 'disabled', 'mac_address', 'rx_bytes', 'tx_bytes', 'ip_address'],
            'ip_addresses' => ['address', 'network', 'interface', 'disabled', 'dynamic'],
            'routes' => ['id', 'dst_address', 'gateway', 'immediate_gateway', 'distance', 'active', 'dynamic', 'static', 'disabled', 'routing_table'],
            'dhcp_leases' => ['mac_address', 'host_name', 'address', 'interface', 'status', 'expires_after', 'dynamic'],
            'wireless_clients' => ['id', 'interface', 'mac_address', 'signal_strength', 'bytes'],
            'bridge_hosts' => ['id', 'mac_address', 'bridge', 'on_interface', 'local', 'dynamic'],
            'arp_neighbors' => ['address', 'mac_address', 'interface', 'dynamic'],
        ] as $collection => $recordFields) {
            $items = $payload[$collection] ?? null;
            if (! is_array($items) || array_is_list($items)) {
                continue;
            }
            if (array_diff(array_keys($items), $recordFields) === []) {
                $payload[$collection] = [$items];
            } else {
                $payload[$collection] = array_values($items);
            }
        }
        $adjusted = [];
        $payload = $this->normalizeRepresentations($payload, $adjusted);
        $unexpectedFields = array_diff(
            array_keys($payload),
            [
                'schema_version',
                'router_id',
                'snapshot_id',
                'observed_at',
                'system',
                'interfaces',
                'interfaces_collection_succeeded',
                'interfaces_complete',
                'interface_count',
                'interfaces_collected_count',
                'router_mac_address',
                'ip_addresses',
                'ip_addresses_collection_succeeded',
                'ip_addresses_complete',
                'ip_address_count',
                'ip_addresses_collected_count',
                'routes',
                'routes_collection_succeeded',
                'routes_complete',
                'route_count',
                'routes_collected_count',
                'dhcp_leases',
                'dhcp_leases_collection_succeeded',
                'dhcp_lease_dynamic_supported',
                'dhcp_leases_complete',
                'dhcp_lease_count',
                'dhcp_collected_count',
                'wireless_clients',
                'wireless_clients_collection_succeeded',
                'wireless_clients_complete',
                'wireless_registration_count',
                'wireless_collected_count',
                'bridge_hosts',
                'bridge_hosts_collection_succeeded',
                'bridge_hosts_complete',
                'bridge_host_count',
                'bridge_hosts_collected_count',
                'arp_neighbors',
                'arp_neighbors_collection_succeeded',
                'arp_neighbors_complete',
                'arp_neighbor_count',
                'arp_neighbors_collected_count',
            ],
        );
        if ($unexpectedFields !== []) {
            return response()->json(['error' => 'Telemetry body contains unsupported fields.'], 422)
                ->header('X-Telemetry-Error', 'unsupported_fields');
        }
        $router = Router::query()
            ->whereKey($payload['router_id'])
            ->where('monitoring_enabled', true)
            ->first();
        $providedToken = $request->bearerToken();
        if (! $router || ! $router->telemetry_token_hash || ! is_string($providedToken)
            || ! preg_match('/^[0-9a-f]{64}$/', $providedToken)
            || ! hash_equals($router->telemetry_token_hash, hash('sha256', $providedToken))) {
            return $this->authenticationFailure();
        }

        $validator = Validator::make($payload, [
            'schema_version' => 'required|integer|in:1',
            'router_id' => 'required|uuid',
            'snapshot_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'observed_at' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP'],
            'system' => 'required|array:identity,version,board_name,architecture,cpu_load,uptime,total_memory,free_memory,total_hdd_space,free_hdd_space',
            'system.identity' => 'required|string|max:128',
            'system.version' => 'required|string|max:64',
            'system.board_name' => 'required|string|max:128',
            'system.architecture' => 'required|string|max:64',
            'system.cpu_load' => 'required|numeric|between:0,100',
            'system.uptime' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Za-z :.-]+$/'],
            'system.total_memory' => 'required|integer|min:1',
            'system.free_memory' => 'required|integer|min:0',
            'system.total_hdd_space' => 'required|integer|min:1',
            'system.free_hdd_space' => 'required|integer|min:0',
            'interfaces' => 'required|array|max:64',
            'interfaces.*' => 'required|array:id,name,type,running,disabled,mac_address,rx_bytes,tx_bytes,ip_address',
            'interfaces.*.id' => 'nullable|string|max:128',
            'interfaces.*.name' => 'required|string|max:128',
            'interfaces.*.type' => 'required|string|max:64',
            'interfaces.*.running' => 'required',
            'interfaces.*.disabled' => 'required',
            'interfaces.*.mac_address' => 'nullable|string|max:32',
            'interfaces.*.rx_bytes' => 'required|integer|min:0',
            'interfaces.*.tx_bytes' => 'required|integer|min:0',
            'interfaces.*.ip_address' => 'nullable|string|max:64',
            'interfaces_collection_succeeded' => 'sometimes|boolean',
            'interfaces_complete' => 'sometimes|boolean',
            'interface_count' => 'sometimes|integer|min:0|max:64',
            'interfaces_collected_count' => 'sometimes|integer|min:0|max:64',
            'router_mac_address' => [
                'nullable',
                'string',
                'regex:/^(?:[0-9A-F]{12}|(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}|[0-9A-F]{4}(?:\\.[0-9A-F]{4}){2})$/i',
            ],
            'ip_addresses' => 'sometimes|array|max:128',
            'ip_addresses.*' => 'required|array:address,network,interface,disabled,dynamic',
            'ip_addresses.*.address' => 'required|string|max:64',
            'ip_addresses.*.network' => 'nullable|ip',
            'ip_addresses.*.interface' => 'required|string|max:128',
            'ip_addresses.*.disabled' => 'required',
            'ip_addresses.*.dynamic' => 'required',
            'ip_addresses_collection_succeeded' => 'sometimes|boolean',
            'ip_addresses_complete' => 'sometimes|boolean',
            'ip_address_count' => 'sometimes|integer|min:0|max:128',
            'ip_addresses_collected_count' => 'sometimes|integer|min:0|max:128',
            'routes' => 'sometimes|array|max:64',
            'routes.*' => 'required|array:id,dst_address,gateway,immediate_gateway,distance,active,dynamic,static,disabled,routing_table',
            'routes.*.id' => 'nullable|string|max:128',
            'routes.*.dst_address' => 'required|string|max:64',
            'routes.*.gateway' => 'nullable|string|max:255',
            'routes.*.immediate_gateway' => 'nullable|string|max:255',
            'routes.*.distance' => 'nullable|integer|min:1|max:255',
            'routes.*.active' => 'required',
            'routes.*.dynamic' => 'required',
            'routes.*.static' => 'required',
            'routes.*.disabled' => 'required',
            'routes.*.routing_table' => 'required|string|max:128',
            'routes_collection_succeeded' => 'sometimes|boolean',
            'routes_complete' => 'sometimes|boolean',
            'route_count' => 'sometimes|integer|min:0|max:64',
            'routes_collected_count' => 'sometimes|integer|min:0|max:64',
            'dhcp_leases' => 'present|array|max:512',
            'dhcp_leases.*' => 'required|array:mac_address,host_name,address,interface,status,expires_after,dynamic',
            'dhcp_leases.*.mac_address' => [
                'required',
                'string',
                'regex:/^(?:[0-9A-F]{12}|(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}|[0-9A-F]{4}(?:\\.[0-9A-F]{4}){2})$/i',
            ],
            'dhcp_leases.*.host_name' => 'nullable|string|max:255',
            'dhcp_leases.*.address' => 'required|ip',
            'dhcp_leases.*.interface' => 'required|string|max:128',
            'dhcp_leases.*.status' => 'required|string|in:bound,waiting,offered,unknown',
            'dhcp_leases.*.expires_after' => 'nullable|string|max:64',
            'dhcp_leases.*.dynamic' => 'sometimes|boolean',
            'dhcp_lease_dynamic_supported' => 'sometimes|boolean',
            'dhcp_leases_complete' => 'sometimes|boolean',
            'dhcp_leases_collection_succeeded' => 'sometimes|boolean',
            'dhcp_lease_count' => 'sometimes|integer|min:0|max:512',
            'dhcp_collected_count' => 'sometimes|integer|min:0|max:512',
            'wireless_clients' => 'present|array|max:256',
            'wireless_clients.*' => 'required|array:id,interface,mac_address,signal_strength,bytes',
            'wireless_clients.*.id' => 'nullable|string|max:128',
            'wireless_clients.*.interface' => 'required|string|max:128',
            'wireless_clients.*.mac_address' => [
                'required',
                'string',
                'regex:/^(?:[0-9A-F]{12}|(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}|[0-9A-F]{4}(?:\\.[0-9A-F]{4}){2})$/i',
            ],
            'wireless_clients.*.signal_strength' => 'nullable|integer|between:-150,0',
            'wireless_clients.*.bytes' => ['nullable', 'string', 'regex:/^\d+,\d+$/'],
            'wireless_clients_complete' => 'sometimes|boolean',
            'wireless_clients_collection_succeeded' => 'sometimes|boolean',
            'wireless_registration_count' => 'sometimes|integer|min:0|max:256',
            'wireless_collected_count' => 'sometimes|integer|min:0|max:256',
            'bridge_hosts' => 'sometimes|array|max:512',
            'bridge_hosts.*' => 'required|array:id,mac_address,bridge,on_interface,local,dynamic',
            'bridge_hosts.*.id' => 'nullable|string|max:128',
            'bridge_hosts.*.mac_address' => [
                'required',
                'string',
                'regex:/^(?:[0-9A-F]{12}|(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}|[0-9A-F]{4}(?:\\.[0-9A-F]{4}){2})$/i',
            ],
            // A bridge-host entry whose port is not resolved yet still identifies the MAC as present on
            // the bridge, so it is stored with an unknown location rather than rejecting the whole report.
            'bridge_hosts.*.bridge' => 'nullable|string|max:128',
            'bridge_hosts.*.on_interface' => 'nullable|string|max:128',
            'bridge_hosts.*.local' => 'required|boolean',
            'bridge_hosts.*.dynamic' => 'required|boolean',
            'bridge_hosts_collection_succeeded' => 'sometimes|boolean',
            'bridge_hosts_complete' => 'sometimes|boolean',
            'bridge_host_count' => 'sometimes|integer|min:0|max:512',
            'bridge_hosts_collected_count' => 'sometimes|integer|min:0|max:512',
            'arp_neighbors' => 'sometimes|array|max:256',
            'arp_neighbors.*' => 'required|array:address,mac_address,interface,dynamic',
            'arp_neighbors.*.address' => 'required|ip',
            'arp_neighbors.*.mac_address' => [
                'required',
                'string',
                'regex:/^(?:[0-9A-F]{12}|(?:[0-9A-F]{2}[:-]){5}[0-9A-F]{2}|[0-9A-F]{4}(?:\\.[0-9A-F]{4}){2})$/i',
            ],
            'arp_neighbors.*.interface' => 'required|string|max:128',
            'arp_neighbors.*.dynamic' => 'required|boolean',
            'arp_neighbors_collection_succeeded' => 'sometimes|boolean',
            'arp_neighbors_complete' => 'sometimes|boolean',
            'arp_neighbor_count' => 'sometimes|integer|min:0|max:256',
            'arp_neighbors_collected_count' => 'sometimes|integer|min:0|max:256',
        ]);

        $validator->after(function ($validator) use ($payload): void {
            $system = $payload['system'] ?? [];
            foreach (['total_memory', 'free_memory', 'total_hdd_space', 'free_hdd_space'] as $field) {
                if (isset($system[$field]) && (float) $system[$field] > PHP_INT_MAX) {
                    $validator->errors()->add("system.{$field}", 'The value exceeds the supported integer range.');
                }
            }
            if (is_array($system)
                && isset($system['total_memory'], $system['free_memory'])
                && is_numeric($system['total_memory'])
                && is_numeric($system['free_memory'])
                && $system['free_memory'] > $system['total_memory']) {
                $validator->errors()->add('system.free_memory', 'Free memory cannot exceed total memory.');
            }
            if (is_array($system)
                && isset($system['total_hdd_space'], $system['free_hdd_space'])
                && is_numeric($system['total_hdd_space'])
                && is_numeric($system['free_hdd_space'])
                && $system['free_hdd_space'] > $system['total_hdd_space']) {
                $validator->errors()->add('system.free_hdd_space', 'Free storage cannot exceed total storage.');
            }
            foreach (['dhcp_leases', 'wireless_clients', 'bridge_hosts', 'arp_neighbors'] as $collection) {
                foreach (is_array($payload[$collection] ?? null) ? $payload[$collection] : [] as $index => $row) {
                    if (is_array($row)
                        && MacAddress::normalize((string) ($row['mac_address'] ?? '')) === null) {
                        $validator->errors()->add("{$collection}.{$index}.mac_address", 'The MAC address must identify a unicast device.');
                    }
                }
            }
            foreach (is_array($payload['dhcp_leases'] ?? null) ? $payload['dhcp_leases'] : [] as $index => $lease) {
                if (is_array($lease)
                    && IpAddress::normalize($lease['address'] ?? null) === null) {
                    $validator->errors()->add("dhcp_leases.{$index}.address", 'The lease address must be a usable IP address.');
                }
            }
            foreach (is_array($payload['arp_neighbors'] ?? null) ? $payload['arp_neighbors'] : [] as $index => $neighbor) {
                if (is_array($neighbor)
                    && IpAddress::normalize($neighbor['address'] ?? null) === null) {
                    $validator->errors()->add("arp_neighbors.{$index}.address", 'The neighbor address must be a usable IP address.');
                }
            }
            foreach (is_array($payload['interfaces'] ?? null) ? $payload['interfaces'] : [] as $index => $interface) {
                if (! is_array($interface)) {
                    continue;
                }
                foreach (['running', 'disabled'] as $field) {
                    if (! in_array($interface[$field] ?? null, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                        $validator->errors()->add("interfaces.{$index}.{$field}", 'The value must be a boolean flag.');
                    }
                }
                foreach (['rx_bytes', 'tx_bytes'] as $field) {
                    if (isset($interface[$field]) && (float) $interface[$field] > PHP_INT_MAX) {
                        $validator->errors()->add("interfaces.{$index}.{$field}", 'The counter exceeds the supported integer range.');
                    }
                }
                if (isset($interface['ip_address'])
                    && $interface['ip_address'] !== ''
                    && filter_var($interface['ip_address'], FILTER_VALIDATE_IP) === false) {
                    $validator->errors()->add("interfaces.{$index}.ip_address", 'The value must be an IP address or empty.');
                }
            }
            foreach (is_array($payload['ip_addresses'] ?? null) ? $payload['ip_addresses'] : [] as $index => $address) {
                if (! is_array($address)) {
                    continue;
                }
                if (! $this->validCidrAddress($address['address'] ?? null)) {
                    $validator->errors()->add("ip_addresses.{$index}.address", 'The address must be a valid IP address with a prefix.');
                }
                foreach (['disabled', 'dynamic'] as $field) {
                    if (! in_array($address[$field] ?? null, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                        $validator->errors()->add("ip_addresses.{$index}.{$field}", 'The value must be a boolean flag.');
                    }
                }
            }
            foreach (is_array($payload['routes'] ?? null) ? $payload['routes'] : [] as $index => $route) {
                if (! is_array($route)) {
                    continue;
                }
                if (! $this->validCidrAddress($route['dst_address'] ?? null)) {
                    $validator->errors()->add("routes.{$index}.dst_address", 'The destination must be a valid network prefix.');
                }
                foreach (['active', 'dynamic', 'static', 'disabled'] as $field) {
                    if (! in_array($route[$field] ?? null, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                        $validator->errors()->add("routes.{$index}.{$field}", 'The value must be a boolean flag.');
                    }
                }
            }
            foreach (is_array($payload['dhcp_leases'] ?? null) ? $payload['dhcp_leases'] : [] as $index => $lease) {
                if (! is_array($lease)) {
                    continue;
                }
                if (! in_array($lease['status'] ?? null, ['bound', 'waiting', 'offered', 'unknown'], true)) {
                    $validator->errors()->add("dhcp_leases.{$index}.status", 'The lease status is not supported.');
                }
            }
        });

        if ($validator->fails()) {
            $errorFields = array_slice($validator->errors()->keys(), 0, 24);
            $snapshotId = $this->safeSnapshotId($payload['snapshot_id'] ?? null);
            Log::notice('MikroTik telemetry snapshot validation failed.', [
                'router_id' => $router->id,
                'snapshot_id' => $snapshotId,
                'error_fields' => $errorFields,
                'raw_representations' => array_slice($adjusted, 0, 24, true),
            ]);

            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422)
                ->header('X-Telemetry-Error', 'validation:'.implode(',', $errorFields))
                ->header('X-Telemetry-Correlation-ID', $snapshotId ?? 'unavailable');
        }
        if (! hash_equals((string) $router->identity, $validator->validated()['system']['identity'])) {
            return response()->json([
                'error' => 'Reported RouterOS identity does not match this router record.',
                'category' => 'router_identity_mismatch',
            ], 422)->header('X-Telemetry-Error', 'router_identity_mismatch');
        }

        try {
            $validated = $validator->validated();
            if (config('app.env') === 'production'
                && str_starts_with(strtoupper(trim($validated['system']['identity'])), 'TEST-')) {
                Log::notice('MikroTik test identity telemetry rejected in production.', [
                    'router_id' => $router->id,
                    'snapshot_id' => $this->safeSnapshotId($validated['snapshot_id']),
                    'category' => 'test_identity',
                ]);

                return response()->json([
                    'error' => 'Test router identities cannot populate production telemetry.',
                    'category' => 'test_identity',
                ], 422)->header('X-Telemetry-Error', 'test_identity');
            }
            $result = $this->telemetry->receive($router, $validated);
            Log::info('MikroTik telemetry snapshot processed.', [
                'router_id' => $router->id,
                'snapshot_id' => $result['snapshotId'],
                'observed_at' => $validated['observed_at'],
                'duplicate' => $result['duplicate'],
                'interface_expected_count' => $validated['interface_count'] ?? null,
                'interface_collected_count' => $validated['interfaces_collected_count'] ?? count($validated['interfaces']),
                'interface_received_count' => count($validated['interfaces']),
                'ip_address_expected_count' => $validated['ip_address_count'] ?? null,
                'ip_address_collected_count' => $validated['ip_addresses_collected_count'] ?? count($validated['ip_addresses'] ?? []),
                'ip_address_received_count' => count($validated['ip_addresses'] ?? []),
                'route_expected_count' => $validated['route_count'] ?? null,
                'route_collected_count' => $validated['routes_collected_count'] ?? count($validated['routes'] ?? []),
                'route_received_count' => count($validated['routes'] ?? []),
                'dhcp_expected_count' => $validated['dhcp_lease_count'] ?? null,
                'dhcp_collected_count' => $validated['dhcp_collected_count'] ?? null,
                'dhcp_received_count' => count($validated['dhcp_leases']),
                'dhcp_collection_complete' => $validated['dhcp_leases_complete'] ?? null,
                'wireless_expected_count' => $validated['wireless_registration_count'] ?? null,
                'wireless_collected_count' => $validated['wireless_collected_count'] ?? null,
                'wireless_received_count' => count($validated['wireless_clients']),
                'wireless_collection_complete' => $validated['wireless_clients_complete'] ?? null,
                'bridge_hosts_expected_count' => $validated['bridge_host_count'] ?? null,
                'bridge_hosts_collected_count' => $validated['bridge_hosts_collected_count'] ?? null,
                'bridge_hosts_received_count' => count($validated['bridge_hosts'] ?? []),
                'bridge_hosts_collection_complete' => $validated['bridge_hosts_complete'] ?? null,
                'arp_neighbors_expected_count' => $validated['arp_neighbor_count'] ?? null,
                'arp_neighbors_collected_count' => $validated['arp_neighbors_collected_count'] ?? null,
                'arp_neighbors_received_count' => count($validated['arp_neighbors'] ?? []),
                'arp_neighbors_collection_complete' => $validated['arp_neighbors_complete'] ?? null,
                'inventory' => $result['inventory'] ?? null,
            ]);

            return response()->json($result)
                ->header('X-Telemetry-Correlation-ID', $result['snapshotId']);
        } catch (RouterApiException $exception) {
            $snapshotId = $this->safeSnapshotId($payload['snapshot_id'] ?? null);
            Log::notice('MikroTik telemetry snapshot rejected.', [
                'router_id' => $router->id,
                'snapshot_id' => $snapshotId,
                'category' => $exception->category,
            ]);

            return response()->json([
                'error' => $exception->safeMessage,
                'category' => $exception->category,
            ], $exception->httpStatus)->header(
                'X-Telemetry-Error',
                $exception->category,
            )->header('X-Telemetry-Correlation-ID', $snapshotId ?? 'unavailable');
        }
    }

    private function safeSnapshotId(mixed $snapshotId): ?string
    {
        return is_string($snapshotId)
            && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $snapshotId)
            ? $snapshotId
            : null;
    }

    /**
     * RouterOS ":serialize" picks JSON types from the value text, so the shape of a field changes
     * with the release: 7.25 dropped the channel label from "/system resource get version"
     * ("system - remove the channel label from the RouterOS version"), which leaves a two-part
     * version such as 7.25 numeric and therefore emitted unquoted. RouterOS also answers "nothing" for
     * an inapplicable flag property (for example "active" on a route it does not compute), which
     * ":serialize" emits as JSON null; under the RouterOS convention a flag that is not reported is a
     * flag that is not set. A resolved-but-empty string property (for example a bridge host whose
     * port is not named yet) is the same class of "nothing" for a label, and is kept as an unknown
     * rather than a value. All of these are representation differences, so they are resolved
     * here; anything still unresolvable is recorded in $adjusted and surfaces in the rejection log.
     */
    private function normalizeRepresentations(array $payload, array &$adjusted): array
    {
        $version = $payload['system']['version'] ?? null;
        if (is_int($version) || is_float($version)) {
            $payload['system']['version'] = rtrim(rtrim(number_format((float) $version, 6, '.', ''), '0'), '.');
            $adjusted['system.version'] = json_encode($version);
        } elseif (isset($version) && (! is_string($version) || trim($version) === '')) {
            // Some builds answer "/system resource get version" with an empty value rather than a
            // version, which fails the same rule for a different reason; record which one it was.
            $adjusted['system.version'] = json_encode($version);
        }

        $flags = [
            'interfaces' => ['running', 'disabled'],
            'ip_addresses' => ['disabled', 'dynamic'],
            'routes' => ['active', 'dynamic', 'static', 'disabled'],
            'dhcp_leases' => ['dynamic'],
            'bridge_hosts' => ['local', 'dynamic'],
            'arp_neighbors' => ['dynamic'],
        ];
        foreach ($flags as $collection => $fields) {
            $rows = is_array($payload[$collection] ?? null) ? $payload[$collection] : [];
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (! array_key_exists($field, $row) || $row[$field] === null) {
                        // RouterOS answers "nothing" for a flag it does not compute, which ":serialize"
                        // emits as JSON null. Under the RouterOS convention a flag that is not reported is
                        // a flag that is not set, so it means false; it is recorded so the rejection log can
                        // still name the shape if this assumption is ever wrong for a build.
                        $adjusted["{$collection}.{$index}.{$field}"] = array_key_exists($field, $row) ? 'null' : 'absent';
                        $payload[$collection][$index][$field] = false;
                        continue;
                    }
                    $resolved = $this->booleanFlag($row[$field]);
                    if ($resolved === $row[$field]) {
                        continue;
                    }
                    $adjusted["{$collection}.{$index}.{$field}"] = json_encode($row[$field]);
                    if ($resolved !== null) {
                        $payload[$collection][$index][$field] = $resolved;
                    }
                }
            }
        }

        $portLabels = ['bridge_hosts' => ['bridge', 'on_interface']];
        foreach ($portLabels as $collection => $fields) {
            $rows = is_array($payload[$collection] ?? null) ? $payload[$collection] : [];
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (! array_key_exists($field, $row)) {
                        continue;
                    }
                    $value = $row[$field];
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        $adjusted["{$collection}.{$index}.{$field}"] = $value === null ? 'null' : 'empty';
                        $payload[$collection][$index][$field] = null;
                    }
                }
            }
        }

        return $payload;
    }

    private function booleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return in_array($value, [0, 1], true) ? $value === 1 : null;
        }
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'yes', 'on', 'true', '1' => true,
            'no', 'off', 'false', '0', '' => false,
            default => null,
        };
    }

    private function validCidrAddress(mixed $value): bool
    {
        if (! is_string($value) || substr_count($value, '/') !== 1) {
            return false;
        }
        [$address, $prefix] = explode('/', $value, 2);
        if (filter_var($address, FILTER_VALIDATE_IP) === false
            || ! ctype_digit($prefix)) {
            return false;
        }
        $maximumPrefix = str_contains($address, ':') ? 128 : 32;

        return (int) $prefix <= $maximumPrefix;
    }

    private function authenticationFailure(): JsonResponse
    {
        return response()
            ->json(['error' => 'Telemetry authentication failed.'], 401)
            ->header('WWW-Authenticate', 'Bearer realm="router-telemetry"');
    }
}
