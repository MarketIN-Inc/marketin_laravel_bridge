<?php

namespace Marketin\LaravelBridge\Support;

class MarketinParamsBag
{
    /**
     * @var array<string, mixed>
     */
    protected array $params;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public function affiliateId(): mixed
    {
        return $this->params['affiliate_id'] ?? $this->params['affiliateId'] ?? null;
    }

    public function campaignId(): mixed
    {
        return $this->params['campaign_id'] ?? $this->params['campaignId'] ?? null;
    }

    public function productId(): mixed
    {
        return $this->params['product_id'] ?? $this->params['productId'] ?? null;
    }

    public function brandId(): mixed
    {
        return $this->params['brand_id'] ?? $this->params['brandId'] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->params;
    }
}
