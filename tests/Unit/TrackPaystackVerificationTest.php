<?php

namespace Marketin\LaravelBridge\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Marketin\LaravelBridge\Http\Middleware\TrackPaystackVerification;
use Marketin\LaravelBridge\Jobs\SendConversionToMarketin;
use Marketin\LaravelBridge\MarketinServiceProvider;
use Marketin\LaravelBridge\Support\Automation\ConversionTrackerState;
use Orchestra\Testbench\TestCase;

class TrackPaystackVerificationTest extends TestCase
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

    public function testMiddlewareIgnoresNonPaystackResponses(): void
    {
        Bus::fake();

        $middleware = new TrackPaystackVerification();
        $request = Request::create('/test');

        $response = $middleware->handle($request, function () {
            return new Response('Not a Paystack response', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertNotDispatched(SendConversionToMarketin::class);
    }

    public function testMiddlewareIgnoresFailedResponses(): void
    {
        Bus::fake();

        $middleware = new TrackPaystackVerification();
        $request = Request::create('/test');

        $response = $middleware->handle($request, function () {
            return new Response('Error', 500);
        });

        $this->assertSame(500, $response->getStatusCode());
        Bus::assertNotDispatched(SendConversionToMarketin::class);
    }

    public function testMiddlewareDetectsSuccessfulPaystackVerification(): void
    {
        Bus::fake();

        $middleware = new TrackPaystackVerification();
        $request = Request::create('/test');
        $request->query->set('aid', '123');
        $request->query->set('cid', '456');

        $paystackResponse = [
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'reference' => 'REF-12345',
                'amount' => 50000,
                'currency' => 'NGN',
                'status' => 'success',
                'metadata' => [
                    'product_id' => 'PROD-789',
                ],
            ],
        ];

        $response = $middleware->handle($request, function () use ($paystackResponse) {
            return new Response(json_encode($paystackResponse), 200, [
                'Content-Type' => 'application/json',
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());

        Bus::assertDispatched(SendConversionToMarketin::class, function (SendConversionToMarketin $job) {
            $payload = $this->readPayload($job);

            return $payload['brandId'] === 42
                && isset($payload['value'])
                && $payload['orderId'] === 'REF-12345';
        });
    }

    public function testMiddlewareIgnoresUnsuccessfulTransactions(): void
    {
        Bus::fake();

        $middleware = new TrackPaystackVerification();
        $request = Request::create('/test');

        $paystackResponse = [
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'reference' => 'REF-12345',
                'amount' => 50000,
                'currency' => 'NGN',
                'status' => 'failed', // Transaction failed
            ],
        ];

        $response = $middleware->handle($request, function () use ($paystackResponse) {
            return new Response(json_encode($paystackResponse), 200, [
                'Content-Type' => 'application/json',
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertNotDispatched(SendConversionToMarketin::class);
    }

    public function testMiddlewareRespectsDisabledConfig(): void
    {
        config(['marketin.automation.auto_track_http_verification' => false]);

        Bus::fake();

        $middleware = new TrackPaystackVerification();
        $request = Request::create('/test');

        $paystackResponse = [
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'reference' => 'REF-12345',
                'amount' => 50000,
                'status' => 'success',
            ],
        ];

        $response = $middleware->handle($request, function () use ($paystackResponse) {
            return new Response(json_encode($paystackResponse), 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertNotDispatched(SendConversionToMarketin::class);
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
