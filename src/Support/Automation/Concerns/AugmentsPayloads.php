<?php

namespace Marketin\LaravelBridge\Support\Automation\Concerns;

use Illuminate\Support\Arr;
use Marketin\LaravelBridge\Support\Automation\AttributionContextResolver;
use Marketin\LaravelBridge\Support\Automation\PendingAttributionStore;

trait AugmentsPayloads
{
    protected function augmentPayload(array $payload): array
    {
        /** @var AttributionContextResolver $resolver */
        $resolver = $this->resolver;
        /** @var PendingAttributionStore $store */
        $store = $this->store;

        $context = $resolver->resolve();

        if (empty($context)) {
            return $payload;
        }

        $payload = $resolver->injectIntoPayload($payload);

        $reference = $this->extractReference($payload);

        if ($reference && config('marketin.automation.store_checkout_context', true)) {
            $store->remember($reference, $context);
        }

        return $payload;
    }

    protected function extractReference(array $payload): ?string
    {
        $keys = ['reference', 'ref', 'order_id', 'orderId', 'metadata.reference'];

        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
