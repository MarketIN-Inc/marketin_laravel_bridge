<?php

namespace Marketin\LaravelBridge\Support\Automation;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

class PendingAttributionStore
{
    protected string $prefix = 'marketin:checkout:';

    public function __construct(protected CacheRepository $cache)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function remember(string $reference, array $context): void
    {
        if ($reference === '') {
            return;
        }

        if (empty($context)) {
            return;
        }

        $ttl = max(1, (int) config('marketin.automation.checkout_context_ttl', 60 * 24 * 2));
        $this->cache->put($this->key($reference), $context, $ttl * 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function pull(string $reference): array
    {
        if ($reference === '') {
            return [];
        }

        return (array) $this->cache->pull($this->key($reference), []);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $reference): array
    {
        if ($reference === '') {
            return [];
        }

        return (array) $this->cache->get($this->key($reference), []);
    }

    protected function key(string $reference): string
    {
        return $this->prefix . Str::lower(trim($reference));
    }
}
