<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'daraja' => [
        'env' => env('DARAJA_ENV', 'sandbox'),
        'consumer_key' => env('DARAJA_CONSUMER_KEY', ''),
        'consumer_secret' => env('DARAJA_CONSUMER_SECRET', ''),
        'short_code' => env('DARAJA_SHORT_CODE', ''),
        'passkey' => env('DARAJA_PASSKEY', ''),
        'callback_url' => env('DARAJA_CALLBACK_URL', ''),
        'transaction_type' => env('DARAJA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
    ],

    'pesapal' => [
        'env' => env('PESAPAL_ENV', 'sandbox'),
        'consumer_key' => env('PESAPAL_CONSUMER_KEY', ''),
        'consumer_secret' => env('PESAPAL_CONSUMER_SECRET', ''),
        'ipn_id' => env('PESAPAL_IPN_ID', ''),
        'currency' => env('PESAPAL_CURRENCY', 'KES'),
    ],

    'flutterwave' => [
        'secret_key' => env('FLW_SECRET_KEY', ''),
        'webhook_secret_hash' => env('FLW_WEBHOOK_SECRET_HASH', ''),
        'currency' => env('FLW_CURRENCY', 'KES'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
        'currency' => env('PAYSTACK_CURRENCY', 'KES'),
    ],

    'radius_provisioning' => [
        'url' => env('RADIUS_PROVISIONING_URL', ''),
        'allowed_host' => env('RADIUS_PROVISIONING_ALLOWED_HOST', ''),
        'client_certificate' => env('RADIUS_PROVISIONING_CLIENT_CERT', ''),
        'client_private_key' => env('RADIUS_PROVISIONING_CLIENT_KEY', ''),
        'client_private_key_passphrase' => env('RADIUS_PROVISIONING_CLIENT_KEY_PASSPHRASE', ''),
        'ca_bundle' => env('RADIUS_PROVISIONING_CA_BUNDLE', ''),
    ],

];
