<?php

namespace Marketin\LaravelBridge;

use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Marketin\LaravelBridge\Http\Controllers\PaystackWebhookController;
use Marketin\LaravelBridge\Http\Middleware\PersistMarketinParams;
use Marketin\LaravelBridge\Http\Middleware\TrackPaystackVerification;
use Marketin\LaravelBridge\Support\Automation\AttributionContextResolver;
use Marketin\LaravelBridge\Support\Automation\PendingAttributionStore;
use Marketin\LaravelBridge\Support\MarketinManager;

class MarketinServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and merge package configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/marketin.php', 'marketin');

        $this->app->singleton(AttributionContextResolver::class, function ($app) {
            return new AttributionContextResolver($app);
        });

        $this->app->singleton(PendingAttributionStore::class, function ($app) {
            return new PendingAttributionStore($app->make('cache.store'));
        });

        $this->app->singleton('marketin.manager', function ($app) {
            return new MarketinManager(
                $app,
                $app->make(AttributionContextResolver::class),
                $app->make(PendingAttributionStore::class)
            );
        });
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
        $this->bootAutomation();
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

        Blade::directive('marketinAutoTrack', function () {
            return "<?php \\Marketin\\LaravelBridge\\Facades\\Marketin::enableAutomation(); ?>";
        });
    }

    /**
     * Ensure attribution persistence middleware is available for host apps.
     */
    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        // Always register the persistence middleware alias
        $router->aliasMiddleware('marketin.persist_params', PersistMarketinParams::class);
        
        // Register the Paystack verification tracking middleware
        $router->aliasMiddleware('marketin.track_paystack', TrackPaystackVerification::class);

        if (! config('marketin.payments.persistence.enabled', true)) {
            return;
        }

        // Auto-add persistence to web group
        $router->pushMiddlewareToGroup('web', PersistMarketinParams::class);
        
        // Auto-add Paystack tracking to web group when enabled
        if (config('marketin.automation.auto_track_http_verification', true)) {
            $router->pushMiddlewareToGroup('web', TrackPaystackVerification::class);
        }
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

    protected function bootAutomation(): void
    {
        if (! config('marketin.automation.enabled', true)) {
            return;
        }

        $this->app->afterResolving('marketin.manager', function (MarketinManager $manager) {
            $manager->enableAutomation();
        });

        $this->app->booted(function ($app) {
            /** @var MarketinManager $manager */
            $manager = $app->make('marketin.manager');
            $manager->enableAutomation();
        });
    }

}
