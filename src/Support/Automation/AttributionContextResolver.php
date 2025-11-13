<?php

namespace Marketin\LaravelBridge\Support\Automation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Marketin\LaravelBridge\Support\MarketinParams;

class AttributionContextResolver
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * Resolve attribution context from the current request/session/config.
     *
     * @return array<string, mixed>
     */
    public function resolve(array $overrides = []): array
    {
        $request = $this->app->make('request');
        $params = MarketinParams::current($request instanceof Request ? $request : null);
        $config = config('marketin');

        $context = array_filter([
            'affiliateId' => $overrides['affiliateId'] ?? $params->affiliateId() ?? Arr::get($config, 'affiliate_id'),
            'campaignId' => $overrides['campaignId'] ?? $params->campaignId() ?? Arr::get($config, 'campaign_id'),
            'productId' => $overrides['productId'] ?? $params->productId(),
            'brandId' => $overrides['brandId'] ?? Arr::get($config, 'brand_id'),
        ], fn ($value) => $value !== null && $value !== '');

        return $context;
    }

    /**
     * Inject attribution into a Paystack initialization payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function injectIntoPayload(array $payload): array
    {
        $context = $this->resolve();

        if (empty($context)) {
            return $payload;
        }

        $metadata = Arr::get($payload, 'metadata', []);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata = array_merge(
            [
                'affiliate_id' => Arr::get($metadata, 'affiliate_id', $context['affiliateId'] ?? null),
                'campaign_id' => Arr::get($metadata, 'campaign_id', $context['campaignId'] ?? null),
                'product_id' => Arr::get($metadata, 'product_id', $context['productId'] ?? null),
                'aid' => Arr::get($metadata, 'aid', $context['affiliateId'] ?? null),
                'cid' => Arr::get($metadata, 'cid', $context['campaignId'] ?? null),
                'pid' => Arr::get($metadata, 'pid', $context['productId'] ?? null),
            ],
            $metadata
        );

        if (! empty($metadata)) {
            $payload['metadata'] = $metadata;
        }

        if (($payload['amount'] ?? null) && is_numeric($payload['amount']) && (int) $payload['amount'] === $payload['amount']) {
            $payload['amount'] = (int) $payload['amount'];
        }

        return $payload;
    }
}
