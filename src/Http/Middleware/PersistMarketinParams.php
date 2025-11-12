<?php

namespace Marketin\LaravelBridge\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Marketin\LaravelBridge\Support\MarketinParams;

class PersistMarketinParams
{
    /**
     * Capture Marketin attribution parameters from the request and persist them.
     */
    public function handle(Request $request, Closure $next)
    {
        $config = config('marketin.payments.persistence');

        if (! Arr::get($config, 'enabled', true)) {
            return $next($request);
        }

        $sessionKey = Arr::get($config, 'session_key', 'marketin.params');
        $cookieName = Arr::get($config, 'cookie_name', 'marketin_params');
        $lifetime = Arr::get($config, 'cookie_lifetime', 60 * 24 * 30);
        $queryKeys = Arr::get($config, 'query_keys', ['aid' => 'affiliate_id', 'cid' => 'campaign_id', 'pid' => 'product_id']);

        $captured = [];

        foreach ($queryKeys as $queryKey => $storeKey) {
            if (! $request->query->has($queryKey)) {
                continue;
            }

            $value = $request->query($queryKey);
            if ($value === null || $value === '') {
                continue;
            }

            $captured[$storeKey] = $value;
        }

        $existing = MarketinParams::fromGlobals($request, $sessionKey, $cookieName);
        $merged = array_filter(array_merge($existing->all(), $captured), static fn ($value) => $value !== null && $value !== '');

        if (! empty($merged)) {
            if ($request->hasSession()) {
                $request->session()->put($sessionKey, $merged);
            }

            $payload = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                $encrypted = Crypt::encryptString($payload);

                Cookie::queue(cookie(
                    $cookieName,
                    $encrypted,
                    $lifetime,
                    null,
                    null,
                    false,
                    true,
                    false,
                    config('session.same_site', 'lax')
                ));
            } catch (EncryptException) {
                // Skip cookie persistence if encryption fails.
            }
        }

        return $next($request);
    }
}
