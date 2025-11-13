<?php

namespace Marketin\LaravelBridge\Support\Automation;

use Marketin\LaravelBridge\Support\Automation\Concerns\AugmentsPayloads;

class PaystackDecorator
{
    use AugmentsPayloads;

    public function __construct(
        protected mixed $paystack,
        protected AttributionContextResolver $resolver,
        protected PendingAttributionStore $store,
        protected array $options = []
    ) {
    }

    public function __call(string $method, array $arguments)
    {
        if ($method === 'transaction' && isset($arguments[0])) {
            $result = $this->paystack->{$method}(...$arguments);

            if (is_object($result)) {
                return new PaystackTransactionDecorator($result, $this->resolver, $this->store, $this->options);
            }

            return $result;
        }

        if ($this->shouldAugment($method, $arguments)) {
            $arguments[0] = $this->augmentPayload($arguments[0]);
        }

        return $this->paystack->{$method}(...$arguments);
    }

    protected function shouldAugment(string $method, array $arguments): bool
    {
        if (empty($arguments)) {
            return false;
        }

        if (! is_array($arguments[0])) {
            return false;
        }

        $targetedMethods = [
            'initialize',
            'setPaymentData',
            'makePaymentRequest',
            'getAuthorizationUrl',
        ];

        return in_array($method, $targetedMethods, true);
    }
}
