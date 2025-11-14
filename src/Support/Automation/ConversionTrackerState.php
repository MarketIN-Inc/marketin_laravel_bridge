<?php

namespace Marketin\LaravelBridge\Support\Automation;

use Illuminate\Http\Request;

class ConversionTrackerState
{
    protected const REQUEST_ATTRIBUTE = '_marketin_tracked_references';

    /**
     * Determine if the provided reference has already been tracked within the current request lifecycle.
     */
    public static function hasTracked(?string $reference): bool
    {
        if ($reference === null || $reference === '') {
            return false;
        }

        $tracked = self::currentReferences();

        return in_array($reference, $tracked, true);
    }

    /**
     * Remember that a reference has been tracked so duplicate conversions are avoided.
     */
    public static function remember(?string $reference): void
    {
        if ($reference === null || $reference === '') {
            return;
        }

        $tracked = self::currentReferences();

        if (in_array($reference, $tracked, true)) {
            return;
        }

        $tracked[] = $reference;
        self::storeReferences($tracked);
    }

    /**
     * Forget tracked references. Useful for tests.
     */
    public static function flush(): void
    {
        self::storeReferences([]);
    }

    /**
     * @return array<int, string>
     */
    protected static function currentReferences(): array
    {
        $request = self::currentRequest();

        if ($request instanceof Request) {
            return (array) $request->attributes->get(self::REQUEST_ATTRIBUTE, []);
        }

        return app()->has(self::REQUEST_ATTRIBUTE)
            ? (array) app(self::REQUEST_ATTRIBUTE)
            : [];
    }

    /**
     * @param array<int, string> $references
     */
    protected static function storeReferences(array $references): void
    {
        $request = self::currentRequest();

        if ($request instanceof Request) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $references);

            return;
        }

        app()->instance(self::REQUEST_ATTRIBUTE, $references);
    }

    protected static function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
