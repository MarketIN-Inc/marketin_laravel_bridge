<?php

namespace Marketin\LaravelBridge;

use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Marketin\LaravelBridge\Http\Controllers\PaystackWebhookController;
use Marketin\LaravelBridge\Http\Middleware\PersistMarketinParams;

class MarketinServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and merge package configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/marketin.php', 'marketin');
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerDirectives();
        $this->registerMiddleware();
        $this->registerRoutes();
    }

    /**
     * Register publishable assets for the package.
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/config/marketin.php' => config_path('marketin.php'),
        ], 'marketin-config');

        $this->publishes([
            __DIR__.'/../dist' => public_path('vendor/marketin'),
        ], 'marketin-assets');
    }

    /**
     * Register the package's Blade directives.
     */
    protected function registerDirectives(): void
    {
        Blade::directive('marketinScripts', function (?string $expression = null) {
            $expression = $expression ?: '[]';

            return "<?php echo \\Marketin\\LaravelBridge\\Support\\BridgeDirective::scripts({$expression}); ?>";
        });

        Blade::directive('marketinTracking', function (?string $expression = null) {
            $expression = $expression ?: '[]';

            return "<?php echo \\Marketin\\LaravelBridge\\Support\\BridgeDirective::tracking({$expression}); ?>";
        });
    }

    /**
     * Ensure attribution persistence middleware is available for host apps.
     */
    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        if (! config('marketin.payments.persistence.enabled', true)) {
            $router->aliasMiddleware('marketin.persist_params', PersistMarketinParams::class);

            return;
        }

        $router->aliasMiddleware('marketin.persist_params', PersistMarketinParams::class);
        $router->pushMiddlewareToGroup('web', PersistMarketinParams::class);
    }

    /**
     * Register webhook routes for supported payment providers.
     */
    protected function registerRoutes(): void
    {
        $paystack = config('marketin.payments.providers.paystack');

        if (! Arr::get($paystack, 'enabled')) {
            return;
        }

        $uri = Arr::get($paystack, 'webhook.uri', 'marketin/paystack/webhook');
        $middleware = Arr::get($paystack, 'webhook.middleware', ['api']);

        Route::middleware($middleware)
            ->post($uri, PaystackWebhookController::class)
            ->name('marketin.payments.paystack.webhook');
    }

}
