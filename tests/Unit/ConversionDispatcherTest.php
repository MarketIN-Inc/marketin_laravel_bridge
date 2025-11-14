<?php

namespace Marketin\LaravelBridge\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Marketin\LaravelBridge\Jobs\SendConversionToMarketin;
use Marketin\LaravelBridge\MarketinServiceProvider;
use Marketin\LaravelBridge\Support\Automation\PendingAttributionStore;
use Marketin\LaravelBridge\Support\ConversionDispatcher;
use Orchestra\Testbench\TestCase;

class ConversionDispatcherTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [MarketinServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a baseline configuration for tests.
        config([
            'marketin.brand_id' => 42,
            'marketin.affiliate_id' => 77,
            'marketin.default_campaign_id' => 88,
            'marketin.automation.store_checkout_context' => true,
        ]);
    }

    public function testQueueAppliesRequestAndConfigFallbacks(): void
    {
        Bus::fake();

        $request = $this->app->make('request');
        $request->query->set('cid', '55');
        $request->query->set('pid', '909');

        ConversionDispatcher::queue([
            'value' => 100.00,
            'reference' => 'ref-123',
        ]);

        Bus::assertDispatched(SendConversionToMarketin::class, function (SendConversionToMarketin $job) {
            $payload = $this->readPayload($job);

            return $payload['brandId'] === 42
                && $payload['affiliateId'] === 77
                && $payload['campaignId'] === '55'
                && $payload['productId'] === '909';
        });
    }

    public function testQueueRecoversIdentifiersFromPendingStore(): void
    {
        Bus::fake();

        $this->app->instance('request', Request::create('/', 'GET'));

        $store = new class {
            public ?string $lastReference = null;

            public function pull(string $reference): array
            {
                $this->lastReference = $reference;

                return [
                    'affiliate_id' => 'stored-aid',
                    'campaign_id' => 'stored-cid',
                    'product_id' => 'stored-pid',
                ];
            }
        };

        $this->app->instance(PendingAttributionStore::class, $store);

        ConversionDispatcher::queue([
            'value' => 250.00,
            'reference' => 'checkout-abc',
            'brandId' => null,
        ]);

        $this->assertSame('checkout-abc', $store->lastReference);

        Bus::assertDispatched(SendConversionToMarketin::class, function (SendConversionToMarketin $job) {
            $payload = $this->readPayload($job);

            return $payload['brandId'] === 42
                && $payload['affiliateId'] === 'stored-aid'
                && $payload['campaignId'] === 'stored-cid'
                && $payload['productId'] === 'stored-pid';
        });
    }

    public function testRequestPidOverridesPayloadMetadata(): void
    {
        Bus::fake();

        $request = Request::create('/', 'GET', ['pid' => 'marketing-override']);
        $this->app->instance('request', $request);

        ConversionDispatcher::queue([
            'value' => 175.50,
            'reference' => 'ref-override',
            'productId' => '1',
        ]);

        Bus::assertDispatched(SendConversionToMarketin::class, function (SendConversionToMarketin $job) {
            $payload = $this->readPayload($job);

            return $payload['productId'] === 'marketing-override';
        });
    }

    /**
     * @param SendConversionToMarketin $job
     * @return array<string, mixed>
     */
    protected function readPayload(SendConversionToMarketin $job): array
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('payload');
        $property->setAccessible(true);

        /** @var array<string, mixed> $payload */
        $payload = $property->getValue($job);

        return $payload;
    }
}
