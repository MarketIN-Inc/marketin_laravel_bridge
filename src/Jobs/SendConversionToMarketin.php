<?php

namespace Marketin\LaravelBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendConversionToMarketin implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public function __construct(protected array $payload, protected array $context = [])
    {
    }

    public function handle(): void
    {
        $config = config('marketin');
        $debug = config('marketin.debug', false);
        $endpoint = rtrim(Arr::get($config, 'api_endpoint', ''), '/');

        if ($endpoint === '') {
            Log::warning('[Marketin] ⚠️ Conversion skipped: missing api_endpoint config. Set MARKETIN_API_ENDPOINT in your environment.', [
                'payload' => $this->payload,
                'hint' => 'Add MARKETIN_API_ENDPOINT=https://api.marketin.now/api/v1 to .env',
            ]);
            return;
        }

        $brandId = $this->payload['brandId'] ?? null;

        if (! $brandId) {
            Log::warning('[Marketin] ⚠️ Conversion skipped: payload missing brandId', [
                'payload' => $this->payload,
                'hint' => 'Ensure MARKETIN_BRAND_ID is set or passed in the payload',
            ]);
            return;
        }

        // Use a public SDK-friendly path by default so integrations that
        // cannot present a server JWT can still post conversions. This
        // endpoint expects the X-BRAND-ID header and accepts payloads
        // without an Authorization token.
        $publicPath = (string) Arr::get($config, 'api_public_path', '/sdk-log-conversion');
        $normalizedPath = trim($publicPath, '/');
        $url = rtrim($endpoint, '/') . '/' . $normalizedPath . '/';

        // Flatten the payload: Django expects all fields at root level, not nested under 'conversion'
        $body = $this->payload;

        // Normalize eventType to snake_case for API compliance
        if (isset($body['eventType']) && ! isset($body['event_type'])) {
            $body['event_type'] = $body['eventType'];
            unset($body['eventType']);
        }

        $headers = [
            'Accept' => 'application/json',
            'X-BRAND-ID' => (string) $brandId,
        ];

        $affiliateId = Arr::get($this->payload, 'affiliateId');
        $campaignId = Arr::get($this->payload, 'campaignId');

        if ($affiliateId !== null && $affiliateId !== '') {
            $headers['X-AFFILIATE-ID'] = (string) $affiliateId;
        }

        if ($campaignId !== null && $campaignId !== '') {
            $headers['X-CAMPAIGN-ID'] = (string) $campaignId;
        }

        if ($debug) {
            Log::debug('[Marketin] 🚀 Sending conversion to API', [
                'endpoint' => $url,
                'headers' => $headers,
                'body' => $body,
                'event_type' => $body['event_type'] ?? null,
                'context' => $this->context,
            ]);
        }

        $response = Http::withHeaders($headers)->post($url, $body);

        if (! $response->successful()) {
            $status = $response->status();
            $message = $response->body();

            Log::error('[Marketin] ❌ Failed to post conversion to API', [
                'http_status' => $status,
                'response_body' => $message,
                'endpoint' => $url,
                'payload' => $this->payload,
                'hint' => 'Check API endpoint, network connectivity, and Marketin API status',
            ]);

            throw new RuntimeException(sprintf(
                'Failed to post conversion to Marketin API (HTTP %d): %s',
                $status,
                $message
            ));
        }

        if ($debug) {
            Log::info('[Marketin] ✅ Conversion successfully sent to API', [
                'reference' => $this->payload['orderId'] ?? $this->payload['reference'] ?? null,
                'value' => $this->payload['value'] ?? null,
                'http_status' => $response->status(),
            ]);
        }
    }
}