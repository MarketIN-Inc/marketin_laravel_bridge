<?php

namespace Marketin\LaravelBridge\Support\Automation;

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marketin\LaravelBridge\Facades\Marketin;

class PaystackHttpResponseTracker
{
    public function handle(ResponseReceived $event): void
    {
        if (! config('marketin.automation.enabled', true)) {
            return;
        }

        if (! config('marketin.automation.auto_track_http_verification', true)) {
            return;
        }

        $url = $event->request->url();

        if (! $this->isPaystackVerificationUrl($url)) {
            return;
        }

        $response = $event->response;

        if (! $response->successful()) {
            $this->debug('Skipping Paystack auto-track: response not successful', [
                'status' => $response->status(),
                'url' => $url,
            ]);

            return;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->info('Skipping Paystack auto-track: verification response was not JSON', [
                'url' => $url,
            ]);

            return;
        }

        if (($payload['status'] ?? null) !== true) {
            $this->info('Skipping Paystack auto-track: verification JSON status flag was not truthy', [
                'url' => $url,
                'status_flag' => $payload['status'] ?? null,
            ]);

            return;
        }

        $transaction = Arr::get($payload, 'data');

        if (! is_array($transaction)) {
            $this->info('Skipping Paystack auto-track: verification payload missing data block', [
                'url' => $url,
            ]);

            return;
        }

        $reference = Arr::get($transaction, 'reference');

        if (ConversionTrackerState::hasTracked(is_string($reference) ? $reference : null)) {
            $this->debug('Skipping Paystack auto-track: reference already tracked in this request', [
                'reference' => $reference,
            ]);

            return;
        }

        $status = Arr::get($transaction, 'status');

        if (! is_string($status) || ! in_array(strtolower($status), ['success', 'successful'], true)) {
            $this->info('Skipping Paystack auto-track: transaction status not successful', [
                'reference' => $reference,
                'status' => $status,
            ]);

            return;
        }

        ConversionTrackerState::remember(is_string($reference) ? $reference : null);

        $this->info('✅ Queuing conversion from Paystack verification (HTTP listener)', [
            'reference' => $reference,
            'url' => $url,
        ]);

        try {
            Marketin::trackAfterPayment($transaction);
        } catch (\Throwable $exception) {
            Log::error('[Marketin] Failed to queue conversion from Paystack HTTP listener', [
                'reference' => $reference,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function isPaystackVerificationUrl(string $url): bool
    {
        return Str::contains(Str::lower($url), 'api.paystack.co/transaction/verify');
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
