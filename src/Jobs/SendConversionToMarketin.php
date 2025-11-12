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

        $url = sprintf('%s/conversions', $endpoint);
        $body = [
            'brandId' => $brandId,
            'conversion' => Arr::except($this->payload, ['brandId']),
        ];

        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])->post($url, $body);

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
