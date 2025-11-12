<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Marketin SDK URL
    |--------------------------------------------------------------------------
    |
    | The URL for the primary Marketin JavaScript SDK. This script is expected
    | to expose the global `window.MarketIn` object consumed by the bridge.
    */
    'sdk_url' => env('MARKETIN_SDK_URL', 'https://cdn.jsdelivr.net/gh/MarketIN-Inc/marketin-sdk@1.0.2/marketin-sdk.min.js'),
    'sdk_attributes' => [
        'data-navigate-once' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Identification
    |--------------------------------------------------------------------------
    |
    | These identifiers are baked into the runtime configuration blob that the
    | bridge publishes. Individual environments can override them via env vars
    | or by passing overrides to the `@marketinScripts` directive.
    */
    'brand_id' => env('MARKETIN_BRAND_ID'),
    'campaign_id' => env('MARKETIN_CAMPAIGN_ID'),
    'affiliate_id' => env('MARKETIN_AFFILIATE_ID'),
    'default_campaign_id' => env('MARKETIN_DEFAULT_CAMPAIGN_ID'),
    'default_affiliate_id' => env('MARKETIN_DEFAULT_AFFILIATE_ID'),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | The bridge forwards conversions and activity to the Marketin API. Update
    | the endpoint to point to your production or sandbox environment.
    */
    'api_endpoint' => env('MARKETIN_API_ENDPOINT', 'https://api.marketin.now/api/v1'),
    'debug' => env('MARKETIN_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Asset Publishing
    |--------------------------------------------------------------------------
    |
    | Configure where the compiled bridge script should be published when
    | running `php artisan vendor:publish --tag=marketin-assets`.
    */
    'assets' => [
        'publish_path' => env('MARKETIN_ASSET_PATH', 'vendor/marketin'),
        'bridge_filename' => env('MARKETIN_BRIDGE_FILENAME', 'marketin-bridge.js'),
        'bridge_url' => env('MARKETIN_BRIDGE_URL', 'https://cdn.jsdelivr.net/gh/MarketIN-Inc/marketin_laravel_bridge@latest/dist/marketin-bridge.js'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking Snippet
    |--------------------------------------------------------------------------
    |
    | Control whether the `@marketinTracking` directive emits a lightweight
    | data-layer snippet payload.
    */
    'tracking' => [
        'enabled' => (bool) env('MARKETIN_TRACKING_ENABLED', true),
        'layer_variable' => env('MARKETIN_TRACKING_LAYER', 'dataLayer'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment + Conversion Integration
    |--------------------------------------------------------------------------
    |
    | Configure how the package captures attribution parameters and reacts to
    | payment provider callbacks. Middleware persists query parameters for
    | later use, while provider settings drive webhook registration and
    | payload normalisation for ConversionDispatcher.
    */
    'payments' => [
        'persistence' => [
            'enabled' => true,
            'cookie_name' => env('MARKETIN_PARAMS_COOKIE', 'marketin_params'),
            'cookie_lifetime' => 60 * 24 * 30,
            'session_key' => 'marketin.params',
            'query_keys' => [
                'aid' => 'affiliate_id',
                'cid' => 'campaign_id',
                'pid' => 'product_id',
            ],
        ],

        'providers' => [
            'paystack' => [
                'enabled' => env('MARKETIN_PAYSTACK_ENABLED', false),
                'webhook' => [
                    'uri' => env('MARKETIN_PAYSTACK_WEBHOOK_URI', 'marketin/paystack/webhook'),
                    'middleware' => ['api'],
                    'signature_header' => 'x-paystack-signature',
                    'secret' => env('PAYSTACK_WEBHOOK_SECRET'),
                ],
                'mapping' => [
                    'value' => [
                        'path' => 'data.amount',
                        'scale' => 0.01,
                    ],
                    'currency' => 'data.currency',
                    'orderId' => 'data.reference',
                    'productId' => 'metadata.product_id',
                    'affiliateId' => 'metadata.affiliate_id',
                    'campaignId' => 'metadata.campaign_id',
                    'customerEmail' => 'data.customer.email',
                ],
                'defaults' => [
                    'eventType' => 'purchase',
                ],
            ],
        ],
    ],
];
