<?php

namespace Marketin\LaravelBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marketin\LaravelBridge\Facades\Marketin;
use Marketin\LaravelBridge\Support\Automation\ConversionTrackerState;
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
            $this->info('Skipping Paystack auto-track middleware: response content empty or not string', [
                'url' => $request->fullUrl(),
            ]);

            return $response;
        }

        // Check if this looks like a Paystack verification response
        if (! $this->looksLikePaystackVerification($content)) {
            $this->debug('Skipping Paystack auto-track middleware: response does not look like Paystack verification JSON', [
                'url' => $request->fullUrl(),
            ]);

            return $response;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if ($this->isSuccessfulPaystackResponse($data)) {
                $this->trackConversion($data, $request);
            } else {
                $this->debug('Skipping Paystack auto-track middleware: verification JSON missing success flag', [
                    'url' => $request->fullUrl(),
                ]);
            }
        } catch (\JsonException $e) {
            // Not JSON, ignore silently
            $this->debug('Skipping Paystack auto-track middleware: response was not valid JSON', [
                'url' => $request->fullUrl(),
            ]);
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
        $reference = $transaction['reference'] ?? null;

        if (ConversionTrackerState::hasTracked(is_string($reference) ? $reference : null)) {
            $this->debug('Skipping Paystack auto-track middleware: reference already tracked this request', [
                'reference' => $reference,
            ]);

            return;
        }

        if (config('marketin.debug', false)) {
            Log::debug('[Marketin] Auto-detected Paystack verification success via middleware, queuing conversion', [
                'reference' => $transaction['reference'] ?? null,
                'amount' => $transaction['amount'] ?? null,
                'url' => $request->fullUrl(),
            ]);
        }

        try {
            Marketin::trackAfterPayment($transaction);
            ConversionTrackerState::remember(is_string($reference) ? $reference : null);

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

    protected function info(string $message, array $context = []): void
    {
        Log::info('[Marketin] '.$message, $context);
    }

    protected function debug(string $message, array $context = []): void
    {
        if (! config('marketin.debug', false)) {
            return;
        }

        Log::debug('[Marketin] '.$message, $context);
    }
}
