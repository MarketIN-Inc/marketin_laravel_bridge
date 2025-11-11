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
    'sdk_url' => env('MARKETIN_SDK_URL', 'https://cdn.jsdelivr.net/gh/MarketIN-Inc/sdk@latest/marketin-sdk.min.js'),
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
];
