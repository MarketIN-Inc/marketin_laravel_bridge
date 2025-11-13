<?php

namespace Marketin\LaravelBridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void enableAutomation()
 * @method static void trackAfterPayment(mixed $transaction, array $overrides = [])
 */
class Marketin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'marketin.manager';
    }
}
