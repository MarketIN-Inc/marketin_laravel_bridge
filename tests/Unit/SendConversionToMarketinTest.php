<?php

namespace Marketin\LaravelBridge\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Marketin\LaravelBridge\Jobs\SendConversionToMarketin;
use Marketin\LaravelBridge\MarketinServiceProvider;
use Orchestra\Testbench\TestCase;

class SendConversionToMarketinTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [MarketinServiceProvider::class];
    }

    public function testAffiliateAndCampaignHeadersAreInjected(): void
    {
        config([
            'marketin.api_endpoint' => 'https://api.example.test/v1',
            'marketin.api_public_path' => '/sdk-log-conversion',
            'marketin.debug' => false,
        ]);

        $captured = null;

        Http::fake(function (Request $request) use (&$captured) {
            $captured = [
                'url' => $request->url(),
                'headers' => $request->headers(),
                'body' => $request->data(),
            ];

            return Http::response(['ok' => true], 200);
        });

        $job = new SendConversionToMarketin([
            'brandId' => 123,
            'affiliateId' => 'af-1',
            'campaignId' => 'camp-9',
            'value' => 100,
        ]);

        $job->handle();

        $this->assertNotNull($captured, 'Expected HTTP request to be captured.');
        $this->assertSame('https://api.example.test/v1/sdk-log-conversion/', Arr::get($captured, 'url'));
        $this->assertSame('af-1', Arr::get($captured, 'headers.X-AFFILIATE-ID.0'));
        $this->assertSame('camp-9', Arr::get($captured, 'headers.X-CAMPAIGN-ID.0'));
        $this->assertSame(123, Arr::get($captured, 'body.brandId'));
        $this->assertSame(100, Arr::get($captured, 'body.conversion.value'));
    }
}
