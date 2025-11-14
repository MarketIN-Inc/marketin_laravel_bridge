# Marketin Laravel Bridge

Marketin Laravel Bridge is a lightweight helper that drops the Marketin JavaScript SDK and a self-initialising bridge script into any Laravel application. A single Blade directive renders the SDK, injects a configuration blob, and loads the bridge that wires URL attribution, Livewire events, and conversion tracking.

---

## Table of contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Quick start](#quick-start)
4. [Installation](#installation)
   - [1. Install the package](#1-install-the-package)
   - [2. Configure environment variables](#2-configure-environment-variables)
   - [3. Add the Blade directive](#3-add-the-blade-directive)
5. [Configuration](#configuration)
   - [Environment variables](#environment-variables)
   - [Bridge hosting](#bridge-hosting)
6. [Usage](#usage)
   - [Blade directives](#blade-directives)
   - [Sample layout integration](#sample-layout-integration)
   - [Tracking data-layer snippet](#tracking-data-layer-snippet)
7. [Self-hosting the bridge (optional)](#self-hosting-the-bridge-optional)
8. [Support](#support)

---

## Features

- **True drop-in integration**: Works with standard Laravel patterns—no SDK dependency required. The package automatically detects Paystack verifications made via `Http::post()` and queues conversions without manual calls.
- One-line Blade directive (`@marketinScripts`) that loads the official Marketin SDK and passes a signed config payload to the bridge.
- CDN defaults for both the SDK (`https://cdn.jsdelivr.net/gh/MarketIN-Inc/sdk@latest/marketin-sdk.min.js`) and the bridge bundle—no publishing step required.
- Environment-driven configuration so each deployment can set the required brand identifier (with optional fallbacks).
- Optional `@marketinTracking` directive to push structured events into the data layer you already use.
- **Automatic conversion tracking**: Middleware intercepts Paystack verification responses and queues conversions automatically—no code changes required.
- Drop-in conversion pipeline: attribution parameters survive gateway redirects and confirmed Paystack webhooks queue a Market!N conversion without manual wiring.
- Automatic Paystack instrumentation: metadata injection, webhook dispatching, and conversion queuing now run out of the box—no checkout boilerplate required.
- **Comprehensive logging**: When `MARKETIN_DEBUG=true`, see exactly what's happening in `laravel.log` with actionable error messages and clear success indicators.
- Extensible helper that accepts per-render overrides for advanced pages or A/B tests.

## Requirements

| Dependency | Version |
|------------|---------|
| PHP        | 8.2+    |
| Laravel    | 10.x, 11.x, or 12.x |
| Node.js    | 18+ (only for maintainers rebuilding the bundle) |
| NPM        | 9+ (only for maintainers rebuilding the bundle) |

---

## Quick start

```bash
composer require marketin-inc/marketin-laravel-bridge

# In your base layout <head> tag:
@marketinScripts()

# In your base layout <main> tag
@marketinTracking([
    'event' => 'marketin.page_view',
])

# Enable the Paystack webhook
MARKETIN_PAYSTACK_ENABLED=true
PAYSTACK_WEBHOOK_SECRET=your-paystack-secret
QUEUE_CONNECTION=database # or redis / sqs / etc.
# (Optional) disable automation if you prefer manual control
# MARKETIN_AUTOMATION_ENABLED=false
```

Set `MARKETIN_BRAND_ID` in your environment before deploying. Affiliate, campaign, and product identifiers arrive via URL parameters when Marketin hands off traffic and are persisted automatically for later requests.

Once the queue connection is set, run a worker (`php artisan queue:work`) so conversion jobs can post to the Marketin API.

---

## Installation

### 1. Install the package

```bash
composer require marketin-inc/marketin-laravel-bridge
```

The service provider is auto-discovered; no manual registration is necessary.

### 2. Configure environment variables

Add the IDs Marketin assigned to you. The bridge will refuse to initialise if `MARKETIN_BRAND_ID` is missing.

```dotenv
MARKETIN_BRAND_ID=123
MARKETIN_API_ENDPOINT=https://api.marketin.now/api/v1
```

You can leave the campaign or affiliate values blank if you plan to provide them per-page via the directive.

### 3. Add the Blade directive

Place `@marketinScripts()` once in your primary layout (typically inside `<head>`). The directive renders:

1. The Marketin SDK tag (`https://cdn.jsdelivr.net/gh/MarketIN-Inc/sdk@latest/marketin-sdk.min.js`) with the required `data-navigate-once` attribute and `defer` so the bridge never races the SDK.
2. A configuration blob (`window.__marketInBridgeConfig`).
3. The Marketin bridge bundle.

When `MARKETIN_DEBUG=true`, the bridge prints a "✅ MarketIn SDK initialized" message once the SDK is available and surfaces warnings if the SDK fails to load in time. That makes layout or CDN issues immediately visible during integration.

---

## Configuration

The package ships with `config/marketin.php`. You do not need to publish it unless you prefer a file-based override; environment variables are sufficient for most teams.

### Environment variables

| Variable                        | Default                                                          | Description |
|---------------------------------|------------------------------------------------------------------|-------------|
| `MARKETIN_SDK_URL`              | `https://cdn.jsdelivr.net/gh/MarketIN-Inc/sdk@latest/marketin-sdk.min.js` | CDN location of the Marketin SDK. |
| `MARKETIN_BRAND_ID`             | `null`                                                           | **Required.** Your Marketin brand identifier. |
| `MARKETIN_CAMPAIGN_ID`          | `null`                                                           | Optional fallback campaign identifier; typical traffic supplies `cid` via URL. |
| `MARKETIN_AFFILIATE_ID`         | `null`                                                           | Optional fallback affiliate/advocate identifier; typical traffic supplies `aid` via URL. |
| `MARKETIN_DEFAULT_CAMPAIGN_ID`  | `null`                                                           | Secondary campaign fallback when neither URL parameters nor overrides are provided. |
| `MARKETIN_DEFAULT_AFFILIATE_ID` | `null`                                                           | Secondary affiliate fallback when neither URL parameters nor overrides are provided. |
| `MARKETIN_API_ENDPOINT`         | `https://api.marketin.now/api/v1`                                | REST endpoint consumed by the bridge. |
| `MARKETIN_API_PUBLIC_PATH`      | `/sdk-log-conversion`                                            | Public path appended to `MARKETIN_API_ENDPOINT` for conversion posts. |
| `MARKETIN_API_TOKEN`            | `null`                                                           | Optional bearer token when you point the bridge at a protected endpoint. |
| `MARKETIN_DEBUG`                | `false`                                                          | Enables verbose console logging. |
| `MARKETIN_BRIDGE_URL`           | `https://cdn.jsdelivr.net/gh/MarketIN-Inc/marketin_laravel_bridge@latest/dist/marketin-bridge.js` | CDN location of the Laravel bridge bundle. |
| `MARKETIN_ASSET_PATH`           | `vendor/marketin`                                                | Target path if you choose to self-host the bridge. |
| `MARKETIN_BRIDGE_FILENAME`      | `marketin-bridge.js`                                             | Filename used when self-hosting. |
| `MARKETIN_TRACKING_ENABLED`     | `true`                                                           | Toggles the `@marketinTracking` directive output. |
| `MARKETIN_TRACKING_LAYER`       | `dataLayer`                                                      | Window variable that receives tracking events. |
| `MARKETIN_AUTOMATION_ENABLED`   | `true`                                                           | Master switch for the automation layer (Paystack metadata, dispatcher fallbacks). |
| `MARKETIN_AUTOMATION_ATTACH_PAYSTACK_METADATA` | `true`                                           | Toggle automatic metadata injection on Paystack initialisation. |
| `MARKETIN_AUTOMATION_STORE_CHECKOUT_CONTEXT`   | `true`                                           | Persist attribution context against Paystack references for webhook recovery. |
| `MARKETIN_AUTOMATION_CONTEXT_TTL` | `2880`                                                        | Minutes to retain checkout context (default 48h). |
| `MARKETIN_AUTOMATION_QUEUE_CONVERSION` | `true`                                                   | Allow `Marketin::trackAfterPayment()` to queue conversions automatically. |
| `MARKETIN_AUTO_TRACK_HTTP_VERIFICATION` | `true`                                                  | Auto-detect and track Paystack verifications made via `Http::post()`. Set to `false` to disable automatic tracking and use manual `Marketin::trackAfterPayment()` calls instead. |
| `MARKETIN_PAYSTACK_ENABLED`     | `false`                                                          | When true, registers the Paystack webhook route. |
| `PAYSTACK_WEBHOOK_SECRET`       | `null`                                                           | Secret used to validate Paystack webhook signatures. |

### Bridge hosting

By default the directive loads the bridge from the CDN value in `MARKETIN_BRIDGE_URL`. If you prefer to host the file yourself, set `MARKETIN_BRIDGE_URL` to an empty string (or remove it) and follow the [self-hosting instructions](#self-hosting-the-bridge-optional).

---

## Usage

### Automation helper

Automation loads itself when `MARKETIN_AUTOMATION_ENABLED` (default `true`). Teams that toggle it off globally can opt back in per request or environment with a single call:

```php
use Marketin\LaravelBridge\Facades\Marketin;

Marketin::enableAutomation();
```

### Blade directives

| Directive             | Purpose |
|-----------------------|---------|
| `@marketinScripts()`  | Injects the SDK, configuration blob, and bridge script. Accepts an optional associative array of overrides. |
| `@marketinTracking()` | Pushes an event into the configured data layer. Also accepts overrides. |
| `@marketinAutoTrack`  | Ensures automation is enabled for the current request (no output; typically unnecessary because it is on by default). |

```blade
@marketinScripts(['brandId' => 42, 'campaignId' => 1001])
@marketinTracking(['event' => 'marketin.conversion', 'value' => 99.95, 'currency' => 'USD'])
```

### Sample layout integration

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>

    @marketinScripts([
        'productId' => request('pid'),
        'campaignId' => request('cid'),
        'affiliateId' => request('aid'),
    ])
</head>
<body>
    {{ $slot ?? '' }}
    @marketinTracking([
        'event' => 'marketin.page_view',
        'productId' => request('pid'),
        'campaignId' => request('cid'),
        'affiliateId' => request('aid'),
    ])
</body>
</html>
```

### Tracking data-layer snippet

Place `@marketinTracking()` near the bottom of your layout if you want every page view sent to your analytics layer. You can also emit it ad-hoc inside campaign pages.

```blade
@marketinTracking([
    'event' => 'marketin.page_view',
    'brandId' => config('marketin.brand_id'),
])
```

### Conversion tracking (automation-first)

With automation enabled (default), the package stitches the full Paystack flow together:

1. The persistence middleware (`marketin.persist_params`) captures `aid`, `cid`, and `pid` from landing URLs and stores them in the session plus an encrypted cookie.
2. **Automatic HTTP-based tracking** (new): When you verify a Paystack transaction using `Http::post('https://api.paystack.co/transaction/verify/...')`, the package automatically detects the successful response and queues a conversion—no manual `Marketin::trackAfterPayment()` call required. This works out of the box for the most common Laravel integration pattern.
3. **SDK-based decoration**: If your app uses Paystack's PHP SDK (e.g., `Paystack::initialize()`), the package decorates the service to inject Marketin identifiers into `metadata` automatically and caches the attribution context against the Paystack reference.
4. **Webhook support**: The package registers `POST /marketin/paystack/webhook` for you; the controller verifies signatures, normalizes payloads according to `config/marketin.php`, and forwards them to `ConversionDispatcher`. Missing IDs are recovered from the cached context, the active request, or persisted cookies.
5. **Job queue**: `ConversionDispatcher` queues `SendConversionToMarketin`, which posts the conversion to the Marketin API using the SDK-compatible public endpoint (`/sdk-log-conversion/`) and the required `X-BRAND-ID` header. A queue worker is recommended, but the job falls back to synchronous execution when queues are disabled.

```bash
# For database queues
php artisan queue:table
php artisan migrate
php artisan queue:work
```

#### HTTP-based Paystack integration (most common)

If your application verifies Paystack transactions using Laravel's HTTP client (the most common pattern):

```php
use Illuminate\Support\Facades\Http;

// Your typical verification code
$response = Http::withToken(config('paystack.secret_key'))
    ->get("https://api.paystack.co/transaction/verify/{$reference}");

if ($response->successful() && $response->json('data.status') === 'success') {
    // Process the order...
}
```

**No additional code needed!** The package automatically:
- Detects the successful Paystack verification response
- Extracts the transaction data
- Queues a conversion with all captured attribution parameters
- Logs everything when `MARKETIN_DEBUG=true`

Check `storage/logs/laravel.log` to see:
```
[Marketin] 📦 Queuing conversion
[Marketin] ✅ Conversion dispatched to queue
[Marketin] 🚀 Sending conversion to API
[Marketin] ✅ Conversion successfully sent to API
```

#### SDK-based Paystack integration

If you use Paystack's PHP SDK, the package decorates it automatically:

#### SDK-based Paystack integration

If you use Paystack's PHP SDK, the package decorates it automatically:

```php
use Paystack;

$paystack = Paystack::transaction()->initialize([
    'amount' => 50000,
    'email' => $customer->email,
    'reference' => $reference,
]);
// Marketin attribution is automatically injected into metadata
```

#### Manual tracking (optional)

If you need an immediate confirmation step or want to disable automatic tracking, call the facade helper manually:

```php
use Marketin\LaravelBridge\Facades\Marketin;

// After verifying a Paystack transaction
$response = Http::withToken(config('paystack.secret_key'))
    ->get("https://api.paystack.co/transaction/verify/{$reference}");

if ($response->successful()) {
    $transaction = $response->json('data');
    
    // Manually track the conversion
    Marketin::trackAfterPayment($transaction);
}
```

To disable automatic HTTP tracking:

```env
MARKETIN_AUTO_TRACK_HTTP_VERIFICATION=false
```

The helper extracts `amount`, `currency`, `reference`, and `metadata` from the Paystack object/array, fills any gaps via cached context, and forwards everything to the conversion queue. Pass an overrides array as the second argument when you need to tweak values.

### Attribution persistence

When a visitor lands on a Marketin link such as:

```text
https://example.com/product/101?pid=101&cid=3&aid=8
```

- `aid` → `affiliateId` / `advocateId`
- `cid` → `campaignId`
- `pid` → `productId` (consumed when `marketin:conversion` events fire)

The persistence middleware stores these identifiers in the session (when available) and an encrypted, HTTP-only cookie. Every request merges the current query string, persisted values, and any overrides so Paystack return URLs or subsequent page loads still contain complete attribution data. The Blade bridge continues to populate `window.sessionStorage`, ensuring client-side events and Livewire transitions re-use the same identifiers.

When automation is active, the same attribution snapshot is cached against the Paystack transaction reference during checkout initialisation. Webhooks, queued jobs, and `Marketin::trackAfterPayment()` all read from that cache first, so conversions retain the original affiliate/campaign identifiers even if the gateway omits them from the callback payload.

The Marketin platform issues customer-facing links containing these parameters, so most teams leave campaign and affiliate IDs blank in their configuration. Overrides exist purely as an escape hatch for bespoke flows or testing.

#### Custom query parameter names

If you receive different query parameter names (for example `affiliate` instead of `aid`), normalise them before the directive runs so the bridge can still discover them. A lightweight middleware keeps the logic centralised:

```php
<?php

namespace App\Http\Middleware;

use Closure;

class NormalizeMarketinParams
{
    public function handle($request, Closure $next)
    {
        $mapping = [
            'affiliate' => 'aid',
            'campaign' => 'cid',
            'product' => 'pid',
        ];

        foreach ($mapping as $incoming => $expected) {
            if ($request->query->has($incoming) && ! $request->query->has($expected)) {
                $request->query->set($expected, $request->query($incoming));
            }
        }

        return $next($request);
    }
}
```

Register the middleware in `app/Http/Kernel.php` (for example inside the `web` group) so every request presents the expected keys to the bridge. For a one-off page you can also pass overrides directly to the directive:

```blade
@marketinScripts([
    'affiliateId' => request('affiliate'),
    'campaignId' => request('campaign'),
    'defaultCampaignId' => config('marketin.default_campaign_id'),
])
```

The bridge still honours the precedence list above, so explicit overrides win whenever the canonical query parameters are missing.

#### Product conversion example

Here is a minimal product page that relies entirely on URL-provided identifiers. The `data-marketin-conversion` attribute emits a conversion payload, while the stored `pid` becomes the product identifier sent to Marketin.

```blade
@extends('layouts.app')

@section('content')
    <main class="product">
        <header>
            <h1>{{ $product->name }}</h1>
            <p class="price">${{ number_format($product->price, 2) }}</p>
        </header>

        <button
            type="button"
            class="btn btn-primary"
            data-marketin-conversion='{"value": {{ number_format($product->price, 2, '.', '') }}, "currency": "USD"}'
            data-marketin-product-id="{{ $product->id }}"
        >
            Purchase
        </button>
    </main>
@endsection
```

When a visitor lands on a Marketin link such as `https://example.com/product/101?pid=101&cid=3&aid=8`, the bridge attaches the `pid`, `cid`, and `aid` captured from the URL to the conversion event fired when the button is pressed. If you omit `data-marketin-product-id`, the stored `pid` automatically becomes the product identifier.

---

## Self-hosting the bridge (optional)

If corporate policy requires hosting the bridge script yourself, run the publish command once and clear the CDN override:

```bash
php artisan vendor:publish --tag=marketin-assets
# then set MARKETIN_BRIDGE_URL= in your .env file
```

The file will be copied to `public/vendor/marketin/marketin-bridge.js`. Future package updates may ship a newer bundle; rerun the command after upgrading.

> **Note:** Only teams that self-host need to run `php artisan vendor:publish --tag=marketin-assets`. CDN users can skip this step entirely.

---

## Troubleshooting

### Conversions aren't being tracked

1. **Enable debug logging** to see what's happening:
   ```env
   MARKETIN_DEBUG=true
   ```

2. **Check `storage/logs/laravel.log`** for Marketin messages:
   - `📦 Queuing conversion` - Conversion was queued successfully
   - `⚠️ Conversion skipped: missing brandId` - Set `MARKETIN_BRAND_ID` in `.env`
   - `✅ Conversion successfully sent to API` - Everything worked!

3. **Verify queue is running**:
   ```bash
   php artisan queue:work
   ```
   
   If you see `Queue driver is "sync"` in logs, conversions run immediately. For production, use `database`, `redis`, or `sqs`.

4. **Check middleware is active**:
   ```bash
   php artisan route:list --middleware=marketin
   ```

5. **Verify Paystack response format**: The automatic tracker looks for successful verification responses. Make sure your code gets a `200` response with `status: true` and `data.status: "success"`.

### No logs appear

- Ensure `MARKETIN_DEBUG=true` is set
- Check `config/marketin.php` hasn't been published with debug disabled
- Verify Laravel logging is working: `Log::info('test')` should appear in `laravel.log`

### Conversions tracked multiple times

If you're calling `Marketin::trackAfterPayment()` manually AND have automatic tracking enabled, you might see duplicates. Choose one approach:

```env
# For automatic tracking (recommended)
MARKETIN_AUTO_TRACK_HTTP_VERIFICATION=true

# For manual tracking only
MARKETIN_AUTO_TRACK_HTTP_VERIFICATION=false
```

---

## Support

- Documentation issues or feature requests: open an issue in the Marketin Laravel Bridge repository.
- SDK behaviour questions: contact the Marketin SDK team or your Marketin solutions engineer.
- Production incidents: escalate through your Marketin account manager.
