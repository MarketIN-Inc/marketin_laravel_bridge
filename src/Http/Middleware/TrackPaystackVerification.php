<?php

namespace Marketin\LaravelBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marketin\LaravelBridge\Facades\Marketin;
use Symfony\Component\HttpFoundation\Response;

class TrackPaystackVerification
{
    /**
     * Intercept successful Paystack verification responses and auto-queue conversions.
     *
     * This middleware detects when an application verifies a Paystack transaction
     * via Http::post() (the most common Laravel pattern) and automatically queues
     * the conversion without requiring manual Marketin::trackAfterPayment() calls.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('marketin.automation.enabled', true)) {
            return $response;
        }

        if (! config('marketin.automation.auto_track_http_verification', true)) {
            return $response;
        }

        // Only process successful responses
        if (! $response->isSuccessful()) {
            return $response;
        }

        $content = $response->getContent();

        if (! $content || ! is_string($content)) {
            return $response;
        }

        // Check if this looks like a Paystack verification response
        if (! $this->looksLikePaystackVerification($content)) {
            return $response;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if ($this->isSuccessfulPaystackResponse($data)) {
                $this->trackConversion($data, $request);
            }
        } catch (\JsonException $e) {
            // Not JSON, ignore silently
        }

        return $response;
    }

    protected function looksLikePaystackVerification(string $content): bool
    {
        // Look for common Paystack API response patterns
        return Str::contains($content, [
            '"data":',
            '"status":',
            '"reference"',
        ]) && (
            Str::contains($content, ['"amount"']) ||
            Str::contains($content, ['"paystack'])
        );
    }

    protected function isSuccessfulPaystackResponse(array $data): bool
    {
        // Paystack verification returns: {"status": true, "message": "...", "data": {...}}
        if (! isset($data['status']) || $data['status'] !== true) {
            return false;
        }

        if (! isset($data['data'])) {
            return false;
        }

        $transaction = $data['data'];

        // Must have minimum required fields
        if (! isset($transaction['reference'], $transaction['amount'])) {
            return false;
        }

        // Only track successful/completed payments
        $status = $transaction['status'] ?? null;

        return in_array($status, ['success', 'successful'], true);
    }

    protected function trackConversion(array $data, Request $request): void
    {
        $transaction = $data['data'] ?? [];

        if (config('marketin.debug', false)) {
            Log::debug('[Marketin] Auto-detected Paystack verification success, queuing conversion', [
                'reference' => $transaction['reference'] ?? null,
                'amount' => $transaction['amount'] ?? null,
                'url' => $request->fullUrl(),
            ]);
        }

        try {
            Marketin::trackAfterPayment($transaction);

            if (config('marketin.debug', false)) {
                Log::info('[Marketin] ✅ Conversion queued automatically from Paystack verification', [
                    'reference' => $transaction['reference'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[Marketin] Failed to queue conversion from Paystack verification', [
                'error' => $e->getMessage(),
                'reference' => $transaction['reference'] ?? null,
            ]);
        }
    }
}
