<?php

namespace Marketin\LaravelBridge\Tests\Unit;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Marketin\LaravelBridge\Jobs\SendConversionToMarketin;
use Marketin\LaravelBridge\MarketinServiceProvider;
use Marketin\LaravelBridge\Support\Automation\ConversionTrackerState;
use Orchestra\Testbench\TestCase;

class PaystackHttpResponseTrackerTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [MarketinServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketin.brand_id' => 42,
            'marketin.automation.enabled' => true,
            'marketin.automation.auto_track_http_verification' => true,
            'marketin.debug' => false,
        ]);
    }

    protected function tearDown(): void
    {
        ConversionTrackerState::flush();

        parent::tearDown();
    }

    public function testListenerQueuesConversionForPaystackVerification(): void
    {
        Bus::fake();

        Http::fake([
            'api.paystack.co/transaction/verify*' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'REF-123',
                    'amount' => 10000,
                    'status' => 'success',
                ],
            ], 200),
        ]);

        Http::get('https://api.paystack.co/transaction/verify/REF-123');

        Bus::assertDispatched(SendConversionToMarketin::class, function (SendConversionToMarketin $job) {
            $payload = $this->readPayload($job);

            return $payload['brandId'] === 42
                && $payload['orderId'] === 'REF-123'
                && abs(($payload['value'] ?? 0) - 10000) < 0.001;
        });
    }

    public function testListenerSkipsNonPaystackRequests(): void
    {
        Bus::fake();

        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        Http::get('https://example.com/api/test');

        Bus::assertNotDispatched(SendConversionToMarketin::class);
    }

    public function testListenerSkipsWhenVerificationFails(): void
    {
        Bus::fake();

        Http::fake([
            'api.paystack.co/transaction/verify*' => Http::response([
                'status' => false,
                'data' => [
                    'reference' => 'REF-123',
                    'status' => 'failed',
                ],
            ], 200),
        ]);

        Http::get('https://api.paystack.co/transaction/verify/REF-123');

        Bus::assertNotDispatched(SendConversionToMarketin::class);
    }

    public function testListenerAvoidsDuplicateTrackingWithinRequest(): void
    {
        Bus::fake();

        Http::fake([
            'api.paystack.co/transaction/verify*' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'REF-456',
                    'amount' => 5000,
                    'status' => 'successful',
                ],
            ], 200),
        ]);

        Http::get('https://api.paystack.co/transaction/verify/REF-456');
        Http::get('https://api.paystack.co/transaction/verify/REF-456');

        Bus::assertDispatchedTimes(SendConversionToMarketin::class, 1);
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
