<?php

namespace Marketin\LaravelBridge\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Marketin\LaravelBridge\Jobs\SendConversionToMarketin;

class ConversionDispatcher
{
    /**
     * Queue a conversion payload for delivery to the Marketin API.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public static function queue(array $payload, array $context = []): void
    {
        $config = config('marketin');

        $brandId = $payload['brandId']
            ?? $context['brandId']
            ?? Arr::get($config, 'brand_id');

        if (! $brandId) {
            Log::warning('Marketin conversion skipped: missing brandId', ['payload' => $payload, 'context' => $context]);
            return;
        }

        $params = MarketinParams::current();

        $payload['brandId'] = $brandId;
        $payload['affiliateId'] = $payload['affiliateId']
            ?? $context['affiliateId']
            ?? $params->affiliateId()
            ?? Arr::get($config, 'affiliate_id');
        $payload['campaignId'] = $payload['campaignId']
            ?? $context['campaignId']
            ?? $params->campaignId()
            ?? Arr::get($config, 'campaign_id');
        $payload['productId'] = $payload['productId'] ?? $params->productId();

        $job = new SendConversionToMarketin($payload, $context);

        if ($job instanceof ShouldQueue) {
            Bus::dispatch($job);
            return;
        }

        // Falls back to synchronous execution when queueing is disabled.
        $job->handle();
    }
}
