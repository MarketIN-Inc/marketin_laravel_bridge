# Marketin Laravel Bridge

A lightweight, automation‑ready integration layer that brings Marketin tracking, attribution persistence, and Paystack conversion automation to any Laravel application. This package injects the Marketin JavaScript SDK, listens to Paystack verification responses, manages attribution data, and queues conversions that follow Marketin’s latest API schema.

This README (Option B) includes fully detailed sections, explanations, and integration steps for both beginners and advanced Laravel developers.

---

## Table of Contents

1. Features
2. Requirements
3. Installation
4. Environment Setup
5. Blade Directives
6. Layout Integration (Full Example)
7. Paystack Flow & Automatic Conversion Tracking
8. Attribution Persistence
9. Configuration Reference
10. Conversion Queueing & Session Alignment
11. Troubleshooting
12. Support

---

## 1. Features

- **Zero‑config SDK injection** – `@marketinScripts()` loads the official Marketin SDK + bridge bundle.
- **Automatic Paystack tracking** – Detects verification responses from Laravel’s HTTP client and queues conversions.
- **Attribution persistence** – `pid`, `cid`, `aid` follow users through pages, checkout redirects, and webhooks.
- **Session alignment** – every conversion includes your Laravel `session_id`.
- **Event normalization** – ensures `event` and `event_type` match Marketin API requirements.
- **No boilerplate tracking code** – controllers remain clean; no manual posting required.
- **Powerful logging** – `MARKETIN_DEBUG=true` prints detailed logs for each tracking decision.

---

## 2. Requirements

- PHP 8.2+
- Laravel 10.x, 11.x, or 12.x
- Queue worker (`database`, `redis`, `sqs`, or `sync`)
- Paystack integration using Laravel’s HTTP client

---

## 3. Installation

```bash
composer require marketin-inc/marketin-laravel-bridge
```

The package auto‑discovers its service provider.

---

## 4. Environment Setup

Add the minimum configuration:

```dotenv
MARKETIN_BRAND_ID=your-brand-id
MARKETIN_API_ENDPOINT=https://api.marketin.now/api/v1
MARKETIN_PAYSTACK_ENABLED=true
MARKETIN_DEBUG=true   # optional, helpful during testing
```

Run a queue worker:

```bash
php artisan queue:work
```

---

## 5. Blade Directives

### `@marketinScripts()`

Loads:

- Marketin SDK
- Marketin bridge bundle
- configuration payload

**Placement:** inside `<head>`.

---

### `@marketinAutoTrack()`

Forces automation for the current request. It **prints no HTML**.

Automation includes:

- Paystack metadata injection
- HTTP verification listener
- webhook-context recovery
- conversion auto‑queueing

**Placement:** Place immediately **after **`` in your base layout.

---

### `@marketinTracking()`

Pushes structured tracking events to your tracking layer.

**Placement:** bottom of `<body>`.

---

## 6. Layout Integration (Full Example)

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    @marketinScripts()
</head>
<body>
    @marketinAutoTrack()

    {{ $slot ?? '' }}

    @marketinTracking([
        'event' => 'marketin.page_view',
        'productId' => request('pid'),
    ])
</body>
</html>
```

This ensures automation + SDK loading on every page.

---

## 7. Paystack Flow & Automatic Conversion Tracking

You **must keep your Paystack verification logic**. The bridge listens to the verification response and queues conversions.

Your usual code:

```php
$response = Http::withToken(config('paystack.secret_key'))
    ->get("https://api.paystack.co/transaction/verify/{$reference}");

if ($response->successful() && $response->json('data.status') === 'success') {
    // Your order processing logic
}
```

**Do not manually track conversions.**\
The package detects the verification response and queues the conversion.

---

## 8. Attribution Persistence

The package captures `pid`, `cid`, `aid` from URLs like:

```
?pid=10&cid=3&aid=8
```

Attribution is persisted via:

- Laravel session
- encrypted cookies
- checkout‑context caching (per Paystack reference)

Precedence:

1. URL parameters
2. session/cookie
3. cached Paystack reference context
4. fallback config values

---

## 9. Configuration Reference

Common environment variables:

| Variable                                | Description                                  |
| --------------------------------------- | -------------------------------------------- |
| `MARKETIN_BRAND_ID`                     | Required brand identifier                    |
| `MARKETIN_API_ENDPOINT`                 | Marketin API root                            |
| `MARKETIN_PAYSTACK_ENABLED`             | Enables webhook route                        |
| `MARKETIN_AUTO_TRACK_HTTP_VERIFICATION` | Enables auto‑detection of Paystack responses |
| `MARKETIN_DEFAULT_EVENT_TYPE`           | Default event type (e.g., purchase)          |
| `MARKETIN_DEBUG`                        | Verbose logs                                 |

You rarely need to publish `config/marketin.php`.

---

## 10. Conversion Queueing & Session Alignment

When Paystack verification succeeds:

- attribution IDs are resolved
- event + event\_type are normalized
- session\_id is attached
- a conversion job is queued: `SendConversionToMarketin`

The job posts conversions to the API and logs success or failures.

---

## 11. Troubleshooting

### Conversions not appearing

- Ensure `@marketinAutoTrack()` is in your layout.
- Run a queue worker.
- Check `laravel.log` for Marketin messages.

### Duplicate conversions

- Ensure you are **not** manually calling a Marketin tracking function.

### Missing brand ID

Set:

```dotenv
MARKETIN_BRAND_ID=your-brand-id
```

### Paystack auto‑track skipped

Enable:

```dotenv
MARKETIN_DEBUG=true
```

Check logs for precise skip reasons.

---

## 12. Support

- Open an issue in this repository.
- Contact your Marketin solutions engineer for integration or production assistance.

