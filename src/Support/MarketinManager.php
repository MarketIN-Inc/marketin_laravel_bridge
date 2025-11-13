<?php

namespace Marketin\LaravelBridge\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Marketin\LaravelBridge\Support\Automation\AttributionContextResolver;
use Marketin\LaravelBridge\Support\Automation\PaystackDecorator;
use Marketin\LaravelBridge\Support\Automation\PendingAttributionStore;
use Marketin\LaravelBridge\Support\ConversionDispatcher;

class MarketinManager
{
    protected bool $automationBooted = false;

    public function __construct(
        protected Container $app,
        protected AttributionContextResolver $resolver,
        protected PendingAttributionStore $store
    ) {
    }

    public function enableAutomation(): void
    {
        if ($this->automationBooted) {
            return;
        }

        $config = config('marketin.automation', []);
        $this->automationBooted = true;

        if (! Arr::get($config, 'enabled', true)) {
            return;
        }

        if (Arr::get($config, 'attach_paystack_metadata', true)) {
            $this->decoratePaystack();
        }
    }

    public function trackAfterPayment(mixed $transaction, array $overrides = []): void
    {
        if (! config('marketin.automation.auto_queue_conversion', true)) {
            return;
        }

        $payload = $this->resolveConversionPayload($transaction, $overrides);

        if (empty($payload)) {
            Log::debug('Marketin automation skipped empty after payment payload');
            return;
        }

        ConversionDispatcher::queue($payload, ['source' => 'automation.after_payment']);
    }

    protected function decoratePaystack(): void
    {
        $wrap = function ($service) {
            return $this->wrapPaystack($service);
        };

        if ($this->app->has('paystack')) {
            $this->app->extend('paystack', function ($service, Container $app) use ($wrap) {
                return $wrap($service);
            });
        }

        $this->app->resolving('paystack', function ($service, Container $app) use ($wrap) {
            if ($service instanceof PaystackDecorator) {
                return;
            }

            $decorated = $wrap($service);
            $app->instance('paystack', $decorated);
        });

        $this->app->rebinding('paystack', function (Container $app, $service) use ($wrap) {
            $app->instance('paystack', $wrap($service));
        });
    }

    protected function wrapPaystack(mixed $service): mixed
    {
        if ($service instanceof PaystackDecorator) {
            return $service;
        }

        return new PaystackDecorator(
            $service,
            $this->resolver,
            $this->store,
            config('marketin.automation', [])
        );
    }

    protected function resolveConversionPayload(mixed $transaction, array $overrides = []): array
    {
        $standard = [
            'orderId' => $this->extractValue($transaction, ['reference', 'data.reference', 'id', 'data.id']),
            'value' => $this->normalizeMonetaryValue($this->extractValue($transaction, ['amount', 'data.amount', 'value'])),
            'currency' => $this->extractValue($transaction, ['currency', 'data.currency']) ?? 'NGN',
            'productId' => $this->extractValue($transaction, ['product_id', 'data.metadata.product_id', 'productId']),
        ];

        $metadata = (array) $this->extractValue($transaction, ['metadata', 'data.metadata']) ?: [];

        $context = array_filter([
            'affiliateId' => Arr::get($metadata, 'affiliate_id') ?? Arr::get($metadata, 'aid'),
            'campaignId' => Arr::get($metadata, 'campaign_id') ?? Arr::get($metadata, 'cid'),
            'productId' => Arr::get($metadata, 'product_id') ?? Arr::get($metadata, 'pid'),
        ], fn ($value) => $value !== null && $value !== '');

        $payload = array_merge($standard, $context, $overrides);

        if (! Arr::get($payload, 'value')) {
            return [];
        }

        return $payload;
    }

    protected function extractValue(mixed $subject, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($subject, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function normalizeMonetaryValue(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_string($value)) {
            $clean = str_replace([',', ' '], '', $value);
            if (is_numeric($clean)) {
                $value = $clean;
            }
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
