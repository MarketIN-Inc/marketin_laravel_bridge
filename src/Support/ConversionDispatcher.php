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

        $brandId = self::firstValue(
            $payload['brandId'] ?? null,
            Arr::get($context, 'brandId'),
            Arr::get($context, 'brand_id'),
            Arr::get($config, 'brand_id')
        );

        if (! $brandId) {
            Log::warning('Marketin conversion skipped: missing brandId', ['payload' => $payload, 'context' => $context]);
            return;
        }

        $params = MarketinParams::current();
        $requestAffiliate = self::requestQuery('aid');
        $requestCampaign = self::requestQuery('cid');
        $requestProduct = self::requestQuery('pid');

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
        $payload['affiliateId'] = self::firstValue(
            Arr::get($payload, 'affiliateId'),
            Arr::get($payload, 'affiliate_id'),
            Arr::get($context, 'affiliateId'),
            Arr::get($context, 'affiliate_id'),
            Arr::get($stored, 'affiliateId'),
            Arr::get($stored, 'affiliate_id'),
            $requestAffiliate,
            $params->affiliateId(),
            Arr::get($config, 'affiliate_id'),
            Arr::get($config, 'default_affiliate_id')
        );

        $payload['campaignId'] = self::firstValue(
            Arr::get($payload, 'campaignId'),
            Arr::get($payload, 'campaign_id'),
            Arr::get($context, 'campaignId'),
            Arr::get($context, 'campaign_id'),
            Arr::get($stored, 'campaignId'),
            Arr::get($stored, 'campaign_id'),
            $requestCampaign,
            $params->campaignId(),
            Arr::get($config, 'campaign_id'),
            Arr::get($config, 'default_campaign_id')
        );

        $payload['productId'] = self::firstValue(
            Arr::get($payload, 'productId'),
            Arr::get($payload, 'product_id'),
            Arr::get($context, 'productId'),
            Arr::get($context, 'product_id'),
            Arr::get($stored, 'productId'),
            Arr::get($stored, 'product_id'),
            $requestProduct,
            $params->productId()
        );

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

    protected static function firstValue(mixed ...$candidates): mixed
    {
        foreach ($candidates as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                if ($trimmed === '') {
                    continue;
                }

                return $trimmed;
            }

            return $value;
        }

        return null;
    }

    protected static function requestQuery(string $key): mixed
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request?->query($key);
    }
}
