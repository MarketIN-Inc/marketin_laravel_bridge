<?php

namespace Marketin\LaravelBridge\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Marketin\LaravelBridge\Jobs\SendConversionToMarketin;
use Marketin\LaravelBridge\Support\Automation\PendingAttributionStore;
use Marketin\LaravelBridge\Support\MarketinParams;

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

        $stored = [];

        if (config('marketin.automation.store_checkout_context', true)) {
            $reference = self::resolveReference($payload, $context);

            if ($reference && app()->bound(PendingAttributionStore::class)) {
                /** @var PendingAttributionStore $store */
                $store = app(PendingAttributionStore::class);
                $stored = $store->pull($reference);
            }
        }

        $payload['brandId'] = $brandId;
        $payload['affiliateId'] = $payload['affiliateId']
            ?? $context['affiliateId']
            ?? $stored['affiliateId'] ?? null
            ?? request()->query('aid')
            ?? $params->affiliateId()
            ?? Arr::get($config, 'affiliate_id');
        $payload['campaignId'] = $payload['campaignId']
            ?? $context['campaignId']
            ?? $stored['campaignId'] ?? null
            ?? request()->query('cid')
            ?? $params->campaignId()
            ?? Arr::get($config, 'campaign_id');
        $payload['productId'] = $payload['productId']
            ?? $stored['productId'] ?? null
            ?? request()->query('pid')
            ?? $params->productId();

        $job = new SendConversionToMarketin($payload, $context);

        if ($job instanceof ShouldQueue) {
            Bus::dispatch($job);
            return;
        }

        // Falls back to synchronous execution when queueing is disabled.
        $job->handle();
    }

    protected static function resolveReference(array $payload, array $context): ?string
    {
        $candidates = [
            $payload['reference'] ?? null,
            $payload['orderId'] ?? null,
            $payload['order_id'] ?? null,
            $payload['transactionReference'] ?? null,
            $context['reference'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
