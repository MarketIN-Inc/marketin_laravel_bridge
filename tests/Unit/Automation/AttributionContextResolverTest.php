<?php

namespace Marketin\LaravelBridge\Tests\Unit\Automation;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Marketin\LaravelBridge\MarketinServiceProvider;
use Marketin\LaravelBridge\Support\Automation\AttributionContextResolver;
use Orchestra\Testbench\TestCase;

class AttributionContextResolverTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [MarketinServiceProvider::class];
    }

    public function testInjectIntoPayloadOverridesProductIdWithMarketingPid(): void
    {
        $request = Request::create('/', 'GET', ['pid' => 'MKT-999']);
        $this->app->instance('request', $request);

        /** @var AttributionContextResolver $resolver */
        $resolver = $this->app->make(AttributionContextResolver::class);

        $payload = [
            'amount' => 2000,
            'metadata' => [
                'product_id' => '1',
            ],
        ];

        $result = $resolver->injectIntoPayload($payload);
        $metadata = Arr::get($result, 'metadata', []);

        $this->assertSame('MKT-999', Arr::get($metadata, 'product_id'));
        $this->assertSame('MKT-999', Arr::get($metadata, 'pid'));
        $this->assertSame('1', Arr::get($metadata, 'catalog_product_id'));
    }
}
