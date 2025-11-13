<?php

namespace Marketin\LaravelBridge\Tests\Unit;

use Marketin\LaravelBridge\MarketinServiceProvider;
use Marketin\LaravelBridge\Support\BridgeDirective;
use Orchestra\Testbench\TestCase;

class BridgeDirectiveTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [MarketinServiceProvider::class];
    }

    public function testScriptsOutputsBridgeDatasetAttributes(): void
    {
        config([
            'marketin.brand_id' => 321,
            'marketin.api_endpoint' => 'https://api.example.test',
            'marketin.sdk_url' => 'https://cdn.example.test/sdk.js',
        ]);

        $html = (string) BridgeDirective::scripts();

        $this->assertStringContainsString('data-marketin-brand="321"', $html);
        $this->assertStringContainsString('data-marketin-api="https://api.example.test"', $html);
        $this->assertStringContainsString('window.__marketInBridgeConfig', $html);
        $this->assertMatchesRegularExpression('/<script src="https:\/\/cdn\.example\.test\/sdk\.js"[^>]*data-navigate-once[^>]*defer/', $html);
        $this->assertStringNotContainsString('async', $html);
    }

    public function testScriptsIgnoresAsyncOverrideAndKeepsCustomAttributes(): void
    {
        config([
            'marketin.brand_id' => 555,
            'marketin.sdk_url' => 'https://cdn.example.test/sdk.js',
            'marketin.sdk_attributes' => [
                'Async' => true,
                'crossorigin' => 'anonymous',
            ],
        ]);

        $html = (string) BridgeDirective::scripts();

        $this->assertStringNotContainsString('async', $html);
        $this->assertMatchesRegularExpression('/<script src="https:\/\/cdn\.example\.test\/sdk\.js"[^>]*crossorigin="anonymous"[^>]*defer/', $html);
    }
}
