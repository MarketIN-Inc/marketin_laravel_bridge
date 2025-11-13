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
        $endpoint = rtrim(Arr::get($config, 'api_endpoint', ''), '/');

        if ($endpoint === '') {
            Log::warning('Marketin conversion skipped: missing api_endpoint config', ['payload' => $this->payload]);
            return;
        }

        $brandId = $this->payload['brandId'] ?? null;

        if (! $brandId) {
            Log::warning('Marketin conversion skipped: payload missing brandId', ['payload' => $this->payload]);
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

        if (config('marketin.debug', false)) {
            Log::debug('Dispatching Marketin conversion', [
                'endpoint' => $url,
                'headers' => $headers,
                'body' => $body,
                'context' => $this->context,
            ]);
        }

        $response = Http::withHeaders($headers)->post($url, $body);

        if (! $response->successful()) {
            $status = $response->status();
            $message = $response->body();

            throw new RuntimeException(sprintf(
                'Failed to post conversion to Marketin API (HTTP %d): %s',
                $status,
                $message
            ));
        }
    }
}