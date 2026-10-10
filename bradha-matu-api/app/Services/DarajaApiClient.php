<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DarajaApiClient — Safaricom Daraja API integration for STK Push.
 *
 * Supports sandbox and production environments via env config.
 * Handles OAuth token management, STK push requests, and transaction status queries.
 *
 * Endpoints (verified from Daraja API docs):
 *   Sandbox:  https://sandbox.safaricom.co.ke
 *   Production: https://api.safaricom.co.ke
 *
 *   OAuth: /oauth/v1/generate?grant_type=client_credentials
 *   STK Push: /mpesa/stkpush/v1/processrequest
 *   STK Query: /mpesa/stkpushquery/v1/query
 */
class DarajaApiClient
{
    private string $baseUrl;

    private string $consumerKey;

    private string $consumerSecret;

    private string $shortCode;

    private string $passkey;

    private string $callbackUrl;

    private string $transactionType;

    public function __construct()
    {
        $this->baseUrl = config('services.daraja.env', 'sandbox') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        $this->consumerKey = config('services.daraja.consumer_key', '');
        $this->consumerSecret = config('services.daraja.consumer_secret', '');
        $this->shortCode = config('services.daraja.short_code', '');
        $this->passkey = config('services.daraja.passkey', '');
        $this->callbackUrl = config('services.daraja.callback_url', '');
        $this->transactionType = config('services.daraja.transaction_type', 'CustomerPayBillOnline');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->consumerKey)
            && ! empty($this->consumerSecret)
            && ! empty($this->shortCode)
            && ! empty($this->passkey)
            && ! empty($this->callbackUrl);
    }

    public function isProduction(): bool
    {
        return config('services.daraja.env', 'sandbox') === 'production';
    }

    /**
     * Get an OAuth access token. Cached for 50 minutes (tokens last 1 hour).
     */
    public function getAccessToken(): string
    {
        return Cache::remember('daraja_access_token', now()->addMinutes(50), function () {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->get("{$this->baseUrl}/oauth/v1/generate", [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                Log::error('Daraja OAuth failed', ['status' => $response->status()]);
                throw new \RuntimeException('Failed to obtain Daraja access token');
            }

            return $response->json('access_token');
        });
    }

    /**
     * Generate the password for STK Push: base64(shortcode + passkey + timestamp).
     */
    private function generatePassword(string $timestamp): string
    {
        return base64_encode($this->shortCode . $this->passkey . $timestamp);
    }

    private function generateTimestamp(): string
    {
        return now()->format('YmdHis');
    }

    /**
     * Send an STK Push request to the customer's phone.
     *
     * @param  string  $phoneNormalized  2547XXXXXXXX format
     * @param  int  $amountMinor  Amount in KSh cents
     * @param  string  $accountReference  Internal order ID
     * @param  string  $transactionDesc  Description
     * @return array{request_id: string, checkout_request_id: string, response_code: string, response_description: string}
     *
     * @throws \RuntimeException
     */
    public function stkPush(
        string $phoneNormalized,
        int $amountMinor,
        string $accountReference,
        string $transactionDesc = 'Internet Package'
    ): array {
        $timestamp = $this->generateTimestamp();
        $password = $this->generatePassword($timestamp);

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", [
                'BusinessShortCode' => $this->shortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $this->transactionType,
                'Amount' => (int) ceil($amountMinor / 100),
                'PartyA' => $phoneNormalized,
                'PartyB' => $this->shortCode,
                'PhoneNumber' => $phoneNormalized,
                'CallBackURL' => $this->callbackUrl,
                'AccountReference' => substr($accountReference, 0, 12),
                'TransactionDesc' => substr($transactionDesc, 0, 13),
            ]);

        $body = $response->json();

        if ($response->failed() || ! isset($body['MerchantRequestID'])) {
            Log::error('Daraja STK Push failed', [
                'status' => $response->status(),
                'error_code' => $body['errorCode'] ?? null,
                'error_message' => $body['errorMessage'] ?? null,
            ]);

            throw new \RuntimeException(
                $body['errorMessage'] ?? 'STK Push request failed'
            );
        }

        return [
            'request_id' => $body['MerchantRequestID'],
            'checkout_request_id' => $body['CheckoutRequestID'],
            'response_code' => $body['ResponseCode'],
            'response_description' => $body['ResponseDescription'],
        ];
    }

    /**
     * Query the status of an STK Push transaction.
     *
     * @return array{result_code: ?string, result_desc: string, is_completed: bool, is_success: bool}
     */
    public function stkQuery(string $checkoutRequestId): array
    {
        $timestamp = $this->generateTimestamp();
        $password = $this->generatePassword($timestamp);

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/mpesa/stkpushquery/v1/query", [
                'BusinessShortCode' => $this->shortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

        $body = $response->json();

        if ($response->failed() && ! isset($body['ResultCode'])) {
            Log::error('Daraja STK Query failed', ['status' => $response->status()]);

            return [
                'result_code' => null,
                'result_desc' => $body['errorMessage'] ?? 'Query failed',
                'is_completed' => false,
                'is_success' => false,
            ];
        }

        $resultCode = $body['ResultCode'] ?? null;
        $isSuccess = $resultCode === '0';
        $isCompleted = $resultCode !== null;

        return [
            'result_code' => $resultCode,
            'result_desc' => $body['ResultDesc'] ?? 'Unknown',
            'is_completed' => $isCompleted,
            'is_success' => $isSuccess,
        ];
    }
}
