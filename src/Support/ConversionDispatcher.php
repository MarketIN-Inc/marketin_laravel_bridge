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
        $debug = config('marketin.debug', false);

        $brandId = self::firstValue(
            $payload['brandId'] ?? null,
            Arr::get($context, 'brandId'),
            Arr::get($context, 'brand_id'),
            Arr::get($config, 'brand_id')
        );

        if (! $brandId) {
            Log::warning('[Marketin] ⚠️ Conversion skipped: missing brandId. Set MARKETIN_BRAND_ID in your environment or pass brandId in the payload.', [
                'payload' => $payload,
                'context' => $context,
                'hint' => 'Add MARKETIN_BRAND_ID=your-brand-id to .env',
            ]);
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
            Arr::get($context, 'productId'),
            Arr::get($context, 'product_id'),
            Arr::get($stored, 'productId'),
            Arr::get($stored, 'product_id'),
            $requestProduct,
            $params->productId(),
            Arr::get($payload, 'productId'),
            Arr::get($payload, 'product_id')
        );

        $payload['eventType'] = self::firstValue(
            Arr::get($payload, 'eventType'),
            Arr::get($payload, 'event_type'),
            Arr::get($context, 'eventType'),
            Arr::get($context, 'event_type'),
            Arr::get($config, 'payments.providers.paystack.defaults.eventType'),
            'purchase'
        );

        if (! isset($payload['event']) || $payload['event'] === null || $payload['event'] === '') {
            $payload['event'] = $payload['eventType'];
        }

        $sessionId = self::firstValue(
            Arr::get($payload, 'sessionId'),
            Arr::get($payload, 'session_id'),
            Arr::get($context, 'sessionId'),
            Arr::get($context, 'session_id'),
            Arr::get($stored, 'sessionId'),
            Arr::get($stored, 'session_id'),
            self::requestSessionId()
        );

        if ($sessionId !== null) {
            $payload['sessionId'] = $sessionId;
        }

        $job = new SendConversionToMarketin($payload, $context);

        if ($debug) {
            Log::info('[Marketin] 📦 Queuing conversion', [
                'brandId' => $payload['brandId'],
                'affiliateId' => $payload['affiliateId'] ?? null,
                'campaignId' => $payload['campaignId'] ?? null,
                'productId' => $payload['productId'] ?? null,
                'eventType' => $payload['eventType'] ?? null,
                'value' => $payload['value'] ?? null,
                'reference' => self::resolveReference($payload, $context),
                'source' => $context['source'] ?? 'manual',
            ]);
        }

        if ($job instanceof ShouldQueue) {
            // Check if queue is configured but warn if it might not be running
            $connection = config('queue.default');
            $driver = config("queue.connections.{$connection}.driver");
            
            if ($driver === 'sync') {
                Log::info('[Marketin] ℹ️ Queue driver is "sync" - conversion will be sent immediately');
            } elseif ($debug) {
                Log::debug('[Marketin] Queue driver: ' . $driver . ' - ensure queue worker is running (php artisan queue:work)');
            }

            Bus::dispatch($job);
            
            if ($debug) {
                Log::info('[Marketin] ✅ Conversion dispatched to queue', [
                    'queue' => $connection,
                    'driver' => $driver,
                ]);
            }
            
            return;
        }

        // Falls back to synchronous execution when queueing is disabled.
        if ($debug) {
            Log::info('[Marketin] 🔄 Executing conversion synchronously (job is not ShouldQueue)');
        }
        
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

    protected static function requestSessionId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request || ! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }
}
