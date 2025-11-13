<?php

namespace Marketin\LaravelBridge\Support\Automation;

use Marketin\LaravelBridge\Support\Automation\Concerns\AugmentsPayloads;

class PaystackTransactionDecorator
{
    use AugmentsPayloads;

    public function __construct(
        protected mixed $transaction,
        protected AttributionContextResolver $resolver,
        protected PendingAttributionStore $store,
        protected array $options = []
    ) {
    }

    public function __call(string $method, array $arguments)
    {
        if (! empty($arguments) && is_array($arguments[0])) {
            $arguments[0] = $this->augmentPayload($arguments[0]);
        }

        return $this->transaction->{$method}(...$arguments);
    }
}
