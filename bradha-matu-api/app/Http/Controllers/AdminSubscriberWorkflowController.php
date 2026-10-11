<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerNetworkIdentity;
use App\Models\NetworkClient;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\Router;
use App\Services\ClientInventory;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSubscriberWorkflowController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private ClientInventory $clientInventory,
        private PaymentService $paymentService,
    ) {}

    public function identities(string $customerId): JsonResponse
    {
        $customer = Customer::query()->findOrFail($customerId);

        return response()->json([
            'data' => $customer->networkIdentities()
                ->with(['router:id,name,ip_address,status_override,last_error_category', 'networkClient'])
                ->latest()
                ->get()
                ->map(fn (CustomerNetworkIdentity $identity): array => $this->identityResource($identity))
                ->values(),
        ]);
    }

    public function storeIdentity(Request $request, string $customerId): JsonResponse
    {
        $customer = Customer::query()->findOrFail($customerId);
        $validated = $request->validate([
            'router_id' => ['nullable', 'uuid', 'exists:routers,id'],
            'friendly_name' => ['required', 'string', 'max:100'],
            'mac_address' => ['nullable', 'string', 'max:32', 'regex:/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'ip_address' => ['nullable', 'ip'],
        ]);

        if (empty($validated['mac_address']) && empty($validated['ip_address'])) {
            throw ValidationException::withMessages([
                'mac_address' => 'Provide an observed device MAC address or an IP address.',
            ]);
        }

        $mac = $this->normalizeMac($validated['mac_address'] ?? null);
        $identity = $this->findIdentityMatch(
            $customer,
            $validated['router_id'] ?? null,
            $mac,
            $validated['ip_address'] ?? null,
        );

        if (! $identity) {
            $identity = new CustomerNetworkIdentity([
                'id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'router_id' => $validated['router_id'] ?? null,
                'source' => 'manual',
            ]);
        }

        $identity->fill([
            'friendly_name' => trim($validated['friendly_name']),
            'mac_address' => $mac,
            'ip_address' => $validated['ip_address'] ?? null,
            'source' => 'manual',
            'network_client_id' => $this->matchingClientId($validated['router_id'] ?? null, $mac),
        ])->save();

        return response()->json([
            'identity' => $this->identityResource($identity->fresh([
                'router:id,name,ip_address,status_override,last_error_category',
                'networkClient',
            ])),
        ], 201);
    }

    public function updateIdentity(Request $request, string $customerId, string $identityId): JsonResponse
    {
        $identity = CustomerNetworkIdentity::query()
            ->where('customer_id', $customerId)
            ->findOrFail($identityId);
        $validated = $request->validate([
            'friendly_name' => ['required', 'string', 'max:100'],
        ]);

        $identity->update(['friendly_name' => trim($validated['friendly_name'])]);

        return response()->json([
            'identity' => $this->identityResource($identity->fresh([
                'router:id,name,ip_address,status_override,last_error_category',
                'networkClient',
            ])),
        ]);
    }

    public function destroyIdentity(string $customerId, string $identityId): JsonResponse
    {
        $identity = CustomerNetworkIdentity::query()
            ->where('customer_id', $customerId)
            ->findOrFail($identityId);
        $identity->delete();

        return response()->json(['success' => true]);
    }

    public function issueInvoice(Request $request, string $customerId): JsonResponse
    {
        $customer = Customer::query()->findOrFail($customerId);

        return $this->persistInvoice($request, $customer);
    }

    public function quoteInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'uuid', 'exists:packages,id'],
            'billing_period_start_at' => ['required', 'date_format:Y-m-d'],
        ]);
        $package = Package::query()->whereKey($validated['package_id'])->where('is_enabled', true)->first();
        if (! $package) {
            return response()->json(['error' => 'The selected package is no longer available.'], 409);
        }

        $quote = $this->invoiceService->quote((int) $package->price_minor);
        $periodStart = Carbon::parse($validated['billing_period_start_at'])->startOfDay();

        return response()->json([
            'subtotal_minor' => $quote['subtotal_minor'],
            'tax_minor' => $quote['tax_minor'],
            'total_minor' => $quote['total_minor'],
            'tax_rate_percent' => $quote['tax_rate_percent'],
            'tax_inclusive' => $quote['tax_inclusive'],
            'currency' => \App\Models\AppSetting::get('currency', 'KSh'),
            'billing_period_end_at' => $periodStart
                ->copy()
                ->addMinutes((int) $package->duration_minutes)
                ->toIso8601String(),
        ]);
    }

    public function initiatePayment(Request $request, string $purchaseId): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:mpesa,pesapal,flutterwave,paystack'],
            'idempotency_key' => ['required', 'uuid'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $purchase = Purchase::query()->whereNotNull('invoice_number')->findOrFail($purchaseId);

        try {
            $result = $this->paymentService->initiatePaymentForPurchase(
                $purchase,
                $validated['provider'],
                $validated['idempotency_key'],
                $validated['email'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 503);
        }

        return response()->json([
            'purchase' => [
                'id' => $result['purchase']->id,
                'order_id' => $result['purchase']->order_id,
                'status' => $result['purchase']->status,
            ],
            'payment' => [
                'id' => $result['payment']->id,
                'provider' => $result['payment']->provider,
                'state' => $result['payment']->state,
                'amount_minor' => $result['payment']->amount_minor,
                'provider_reference' => $result['payment']->provider_reference,
                'initiated_at' => $result['payment']->initiated_at?->toIso8601String(),
                'completed_at' => $result['payment']->completed_at?->toIso8601String(),
            ],
            'checkout_url' => $result['checkout_url'],
            'reused' => $result['reused'],
        ]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        return $this->persistInvoice($request, null);
    }

    private function persistInvoice(Request $request, ?Customer $knownCustomer): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'customer' => ['nullable', 'array'],
            'customer.full_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.phone' => ['required_with:customer', 'string', 'max:32'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.password' => ['required_with:customer', 'string', 'min:8', 'max:255', 'confirmed'],
            'customer.location' => ['nullable', 'string', 'max:255'],
            'customer.notes' => ['nullable', 'string', 'max:2000'],
            'package_id' => ['required', 'uuid', 'exists:packages,id'],
            'router_id' => ['nullable', 'uuid', 'exists:routers,id'],
            'identity_mode' => ['required', 'in:existing,dhcp,manual,none'],
            'network_identity_id' => ['nullable', 'uuid', 'exists:customer_network_identities,id'],
            'lease_token' => ['nullable', 'string', 'max:10000'],
            'friendly_name' => ['nullable', 'string', 'max:100'],
            'mac_address' => ['nullable', 'string', 'max:32', 'regex:/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'ip_address' => ['nullable', 'ip'],
            'billing_period_start_at' => ['required', 'date_format:Y-m-d'],
            'due_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $customerId = $knownCustomer?->id ?? ($validated['customer_id'] ?? null);
        $hasNewCustomer = is_array($validated['customer'] ?? null);
        if (($customerId === null && ! $hasNewCustomer) || ($customerId !== null && $hasNewCustomer)) {
            throw ValidationException::withMessages([
                'customer_id' => 'Select an existing subscriber or enter the new subscriber details.',
            ]);
        }
        if ($knownCustomer !== null && isset($validated['customer_id']) && $validated['customer_id'] !== $knownCustomer->id) {
            throw ValidationException::withMessages([
                'customer_id' => 'The invoice route is scoped to a different subscriber.',
            ]);
        }

        $newCustomerData = $validated['customer'] ?? null;
        $normalizedPhone = null;
        if ($newCustomerData !== null) {
            try {
                $normalizedPhone = PhoneNormalizer::normalize($newCustomerData['phone']);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['customer.phone' => $exception->getMessage()]);
            }
        }
        $fingerprintData = $validated;
        unset($fingerprintData['idempotency_key']);
        if ($newCustomerData !== null) {
            $fingerprintData['customer']['phone'] = $normalizedPhone;
            unset(
                $fingerprintData['customer']['password'],
                $fingerprintData['customer']['password_confirmation'],
            );
        }
        $requestFingerprint = hash_hmac(
            'sha256',
            json_encode($fingerprintData, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );

        $existingPurchase = Purchase::query()
            ->where('admin_idempotency_key', $validated['idempotency_key'])
            ->first();
        if ($existingPurchase) {
            $expectedCustomer = $customerId
                ? $customerId === $existingPurchase->customer_id
                : $normalizedPhone === $existingPurchase->customer?->phone_normalized;
            if (
                ! $expectedCustomer
                || $existingPurchase->package_id !== $validated['package_id']
                || ! hash_equals(
                    (string) $existingPurchase->admin_request_fingerprint,
                    $requestFingerprint,
                )
            ) {
                return response()->json(['error' => 'This submission key was already used for different invoice details.'], 409);
            }

            return $this->invoiceResponse($existingPurchase, false);
        }
        $existingSubscriber = $customerId
            ? Customer::query()->find($customerId)
            : ($normalizedPhone ? Customer::query()->where('phone_normalized', $normalizedPhone)->first() : null);
        if ($existingSubscriber) {
            $cycleKey = $this->billingCycleKey(
                $existingSubscriber->id,
                $validated['package_id'],
                $validated['billing_period_start_at'],
            );
            $cyclePurchase = Purchase::query()->where('admin_billing_cycle_key', $cycleKey)->first();
            if ($cyclePurchase) {
                if (! hash_equals((string) $cyclePurchase->admin_request_fingerprint, $requestFingerprint)) {
                    return response()->json(['error' => 'An invoice already exists for this subscriber, package, and billing period.'], 409);
                }

                return $this->invoiceResponse($cyclePurchase, false);
            }
        }

        $package = Package::query()->whereKey($validated['package_id'])->where('is_enabled', true)->first();
        if (! $package) {
            return response()->json(['error' => 'The selected package is no longer available.'], 409);
        }

        $identityData = $this->resolveIdentityData($validated);
        if ($identityData['router_id'] !== null) {
            Router::query()->whereKey($identityData['router_id'])->where('monitoring_enabled', true)->firstOrFail();
        }

        try {
            $purchase = DB::transaction(function () use ($knownCustomer, $customerId, $newCustomerData, $normalizedPhone, $validated, $package, $identityData, $requestFingerprint): Purchase {
                if ($customerId !== null) {
                    $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
                } else {
                    $duplicate = Customer::query()->where('phone_normalized', $normalizedPhone)->first();
                    if ($duplicate) {
                        throw ValidationException::withMessages([
                            'customer.phone' => 'A subscriber with this normalized phone already exists. Select that existing subscriber instead.',
                        ]);
                    }

                    $customer = Customer::create([
                        'id' => (string) Str::uuid(),
                        'account_number' => $this->newAccountNumber(),
                        'full_name' => trim($newCustomerData['full_name']),
                        'phone_normalized' => $normalizedPhone,
                        'phone_display' => PhoneNormalizer::toDisplayFormat($normalizedPhone),
                        'email' => $newCustomerData['email'] ?? null,
                        'password' => $newCustomerData['password'],
                        'status' => 'active',
                        'location' => $newCustomerData['location'] ?? null,
                        'notes' => $newCustomerData['notes'] ?? null,
                        'terms_accepted_at' => null,
                    ]);
                }

                $identity = $this->resolveCustomerIdentity($customer, $validated, $identityData);
                $quote = $this->invoiceService->quote((int) $package->price_minor);
                $periodStart = Carbon::parse($validated['billing_period_start_at'])->startOfDay();
                $periodEnd = $periodStart->copy()->addMinutes((int) $package->duration_minutes);
                $routerId = $identity?->router_id ?? $validated['router_id'] ?? $package->router_id;
                $snapshot = array_merge($package->toSnapshot(), [
                    'router_id' => $routerId,
                    'billing_period_start_at' => $periodStart->toIso8601String(),
                    'billing_period_end_at' => $periodEnd->toIso8601String(),
                    'network_identity_id' => $identity?->id,
                ]);

                $purchase = Purchase::create([
                    'id' => (string) Str::uuid(),
                    'customer_id' => $customer->id,
                    'package_id' => $package->id,
                    'network_identity_id' => $identity?->id,
                    'package_snapshot' => $snapshot,
                    'order_id' => 'ORD-'.strtoupper(Str::random(12)),
                    'amount_minor' => $quote['total_minor'],
                    'subtotal_minor' => $quote['subtotal_minor'],
                    'tax_minor' => $quote['tax_minor'],
                    'status' => 'created',
                    'billing_period_start_at' => $periodStart,
                    'billing_period_end_at' => $periodEnd,
                    'due_at' => Carbon::parse($validated['due_at'])->endOfDay(),
                    'admin_idempotency_key' => $validated['idempotency_key'],
                    'admin_billing_cycle_key' => $this->billingCycleKey(
                        $customer->id,
                        $package->id,
                        $validated['billing_period_start_at'],
                    ),
                    'admin_request_fingerprint' => $requestFingerprint,
                ]);

                return $this->invoiceService->applyInvoiceAndTax($purchase);
            });
        } catch (QueryException $exception) {
            $purchase = Purchase::query()
                ->where('admin_idempotency_key', $validated['idempotency_key'])
                ->first();

            if (! $purchase) {
                $resolvedCustomer = $customerId
                    ? Customer::query()->find($customerId)
                    : ($normalizedPhone ? Customer::query()->where('phone_normalized', $normalizedPhone)->first() : null);
                if ($resolvedCustomer) {
                    $cycleKey = $this->billingCycleKey(
                        $resolvedCustomer->id,
                        $validated['package_id'],
                        $validated['billing_period_start_at'],
                    );
                    $purchase = Purchase::query()->where('admin_billing_cycle_key', $cycleKey)->first();
                }
            }
            if (! $purchase) {
                throw $exception;
            }
            if (! hash_equals((string) $purchase->admin_request_fingerprint, $requestFingerprint)) {
                return response()->json(['error' => 'An invoice already exists for these subscriber billing details.'], 409);
            }

            return $this->invoiceResponse($purchase, false);
        }

        return $this->invoiceResponse($purchase, true);
    }

    private function resolveIdentityData(array $validated): array
    {
        $mode = $validated['identity_mode'];
        if ($mode === 'existing') {
            return ['mode' => $mode, 'identity' => null, 'router_id' => null, 'mac' => null, 'ip' => null];
        }

        if ($mode === 'none') {
            return ['mode' => $mode, 'identity' => null, 'router_id' => $validated['router_id'] ?? null, 'mac' => null, 'ip' => null];
        }

        if ($mode === 'dhcp') {
            if (empty($validated['lease_token'])) {
                throw ValidationException::withMessages(['lease_token' => 'Select a freshly observed DHCP lease.']);
            }
            try {
                $lease = json_decode(Crypt::decryptString($validated['lease_token']), true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw ValidationException::withMessages(['lease_token' => 'The DHCP observation is invalid or expired. Refresh the lease list.']);
            }

            $observedAt = isset($lease['observedAt']) ? strtotime($lease['observedAt']) : false;
            if (
                ! is_array($lease)
                || ($lease['source'] ?? null) !== 'dhcp'
                || $observedAt === false
                || $observedAt < now()->subMinutes(5)->timestamp
                || ($validated['router_id'] ?? null) !== ($lease['routerId'] ?? null)
                || empty($lease['macAddress'])
                || empty($lease['ipAddress'])
            ) {
                throw ValidationException::withMessages(['lease_token' => 'This DHCP observation is stale or does not match the selected router. Refresh the lease list.']);
            }

            return [
                'mode' => 'dhcp',
                'identity' => $lease,
                'router_id' => $lease['routerId'],
                'mac' => $this->normalizeMac($lease['macAddress']),
                'ip' => $lease['ipAddress'],
            ];
        }

        $mac = $this->normalizeMac($validated['mac_address'] ?? null);
        $ip = $validated['ip_address'] ?? null;
        if ($mac === null && $ip === null) {
            throw ValidationException::withMessages([
                'mac_address' => 'Enter a full MAC address or IP address for the subscriber router identity.',
            ]);
        }

        return [
            'mode' => 'manual',
            'identity' => null,
            'router_id' => $validated['router_id'] ?? null,
            'mac' => $mac,
            'ip' => $ip,
        ];
    }

    private function resolveCustomerIdentity(Customer $customer, array $validated, array $identityData): ?CustomerNetworkIdentity
    {
        if ($identityData['mode'] === 'none') {
            return null;
        }

        if ($identityData['mode'] === 'existing') {
            $identity = $customer->networkIdentities()->find($validated['network_identity_id'] ?? null);
            if (! $identity) {
                throw ValidationException::withMessages([
                    'network_identity_id' => 'Select an identity belonging to this subscriber.',
                ]);
            }
            if (($validated['router_id'] ?? null) !== null && $identity->router_id !== $validated['router_id']) {
                throw ValidationException::withMessages([
                    'network_identity_id' => 'The selected identity does not belong to the selected router.',
                ]);
            }
            if (! empty($validated['friendly_name'])) {
                $identity->update(['friendly_name' => trim($validated['friendly_name'])]);
            }

            return $identity->fresh();
        }

        $identity = $this->findIdentityMatch(
            $customer,
            $identityData['router_id'],
            $identityData['mac'],
            $identityData['ip'],
        );
        if ($identityData['router_id'] !== null && $identityData['mac'] !== null) {
            $assignedToAnotherSubscriber = CustomerNetworkIdentity::query()
                ->where('router_id', $identityData['router_id'])
                ->where('mac_address', $identityData['mac'])
                ->where('customer_id', '!=', $customer->id)
                ->exists();
            if ($assignedToAnotherSubscriber) {
                throw ValidationException::withMessages([
                    'mac_address' => 'This router MAC address is already assigned to another subscriber.',
                ]);
            }
        }
        if (! $identity) {
            $identity = new CustomerNetworkIdentity([
                'id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'router_id' => $identityData['router_id'],
            ]);
        }

        $observed = $identityData['identity'];
        $identity->fill([
            'friendly_name' => trim((string) ($validated['friendly_name'] ?? 'Customer router')),
            'mac_address' => $identityData['mac'],
            'ip_address' => $identityData['ip'],
            'source' => $identityData['mode'],
            'observed_at' => $observed ? Carbon::parse($observed['observedAt']) : null,
            'network_client_id' => $this->matchingClientId($identityData['router_id'], $identityData['mac']),
        ])->save();

        return $identity;
    }

    private function findIdentityMatch(Customer $customer, ?string $routerId, ?string $mac, ?string $ip): ?CustomerNetworkIdentity
    {
        $query = $customer->networkIdentities()->where('router_id', $routerId);
        if ($mac !== null) {
            $query->where('mac_address', $mac);
        } elseif ($ip !== null) {
            $query->where('ip_address', $ip);
        } else {
            return null;
        }

        return $query->first();
    }

    private function matchingClientId(?string $routerId, ?string $mac): ?string
    {
        if ($routerId === null || $mac === null) {
            return null;
        }

        return NetworkClient::query()
            ->where('router_id', $routerId)
            ->where('mac_address', $mac)
            ->value('id');
    }

    private function normalizeMac(?string $mac): ?string
    {
        return $mac === null || trim($mac) === '' ? null : strtoupper(str_replace('-', ':', trim($mac)));
    }

    private function newAccountNumber(): string
    {
        do {
            $accountNumber = 'BM-'.strtoupper(Str::random(10));
        } while (Customer::query()->where('account_number', $accountNumber)->exists());

        return $accountNumber;
    }

    private function billingCycleKey(string $customerId, string $packageId, string $periodStart): string
    {
        return hash_hmac(
            'sha256',
            "{$customerId}|{$packageId}|{$periodStart}",
            (string) config('app.key'),
        );
    }

    private function invoiceResponse(Purchase $purchase, bool $created): JsonResponse
    {
        $purchase->load([
            'customer',
            'package',
            'payments',
            'networkIdentity.router:id,name,ip_address,status_override,last_error_category',
            'networkIdentity.networkClient',
        ]);

        return response()->json([
            'created' => $created,
            'customer' => $this->customerResource($purchase->customer),
            'identity' => $purchase->networkIdentity
                ? $this->identityResource($purchase->networkIdentity)
                : null,
            'invoice' => $this->invoiceService->buildInvoiceData($purchase),
            'purchase' => [
                'id' => $purchase->id,
                'order_id' => $purchase->order_id,
                'status' => $purchase->status,
                'amount_minor' => $purchase->amount_minor,
                'invoice_number' => $purchase->invoice_number,
                'due_at' => $purchase->due_at?->toIso8601String(),
                'billing_period_start_at' => $purchase->billing_period_start_at?->toIso8601String(),
                'billing_period_end_at' => $purchase->billing_period_end_at?->toIso8601String(),
                'payments' => $purchase->payments->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'provider' => $payment->provider,
                    'state' => $payment->state,
                    'amount_minor' => $payment->amount_minor,
                    'provider_reference' => $payment->provider_reference,
                    'initiated_at' => $payment->initiated_at?->toIso8601String(),
                    'completed_at' => $payment->completed_at?->toIso8601String(),
                ]),
            ],
        ], $created ? 201 : 200);
    }

    private function customerResource(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'account_number' => $customer->account_number,
            'full_name' => $customer->full_name,
            'phone_display' => $customer->phone_display,
            'email' => $customer->email,
            'location' => $customer->location,
            'notes' => $customer->notes,
            'status' => $customer->status,
        ];
    }

    private function identityResource(CustomerNetworkIdentity $identity): array
    {
        $identity->loadMissing([
            'router:id,name,ip_address,status_override,last_error_category',
            'networkClient',
        ]);
        $client = $identity->networkClient;
        $connectionStatus = $client
            ? $this->clientInventory->present($client->loadMissing('router'))['status']
            : 'unknown';

        return [
            'id' => $identity->id,
            'router_id' => $identity->router_id,
            'router_name' => $identity->router?->name,
            'router_host' => $identity->router?->ip_address,
            'friendly_name' => $identity->friendly_name,
            'mac_address' => $identity->mac_address,
            'ip_address' => $identity->ip_address,
            'source' => $identity->source,
            'observed_at' => $identity->observed_at?->toIso8601String(),
            'connection_status' => $connectionStatus,
        ];
    }
}
