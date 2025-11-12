<?php

namespace Marketin\LaravelBridge\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class MarketinParams
{
    /**
     * Resolve Marketin attribution parameters from the current session/cookie.
     */
    public static function current(?Request $request = null): MarketinParamsBag
    {
        $request = $request ?? request();
        $config = config('marketin.payments.persistence');
        $sessionKey = Arr::get($config, 'session_key', 'marketin.params');
        $cookieName = Arr::get($config, 'cookie_name', 'marketin_params');

        return self::fromGlobals($request, $sessionKey, $cookieName);
    }

    /**
     * Build a params bag using the request session and cookie values.
     */
    public static function fromGlobals(?Request $request, string $sessionKey, string $cookieName): MarketinParamsBag
    {
        $sessionValues = [];
        $cookieValues = [];

        if ($request && $request->hasSession()) {
            $sessionValues = (array) $request->session()->get($sessionKey, []);
        }

        $rawCookie = $request?->cookies->get($cookieName) ?? request()->cookies->get($cookieName, null);

        if ($rawCookie) {
            try {
                $decoded = Crypt::decryptString($rawCookie);
                $cookieValues = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (DecryptException | JsonException) {
                $cookieValues = [];
            }
        }

        $params = array_filter(
            array_merge($cookieValues, $sessionValues),
            static fn ($value) => $value !== null && $value !== ''
        );

        // Merge query string parameters (aid, cid, pid) with highest precedence
        $queryOverrides = [];
        $request = $request ?? request();

        if ($request) {
            $aid = $request->query('aid');
            $cid = $request->query('cid');
            $pid = $request->query('pid');

            if ($aid !== null && $aid !== '') {
                $queryOverrides['affiliate_id'] = $aid;
            }

            if ($cid !== null && $cid !== '') {
                $queryOverrides['campaign_id'] = $cid;
            }

            if ($pid !== null && $pid !== '') {
                $queryOverrides['product_id'] = $pid;
            }
        }

        return new MarketinParamsBag(array_merge($params, $queryOverrides));
    }
}
