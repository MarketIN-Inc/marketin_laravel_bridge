<?php

namespace Marketin\LaravelBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Marketin\LaravelBridge\Support\ConversionDispatcher;
use Marketin\LaravelBridge\Support\MarketinParams;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $config = config('marketin.payments.providers.paystack');

        abort_unless(Arr::get($config, 'enabled', false), 404);

        $this->verifySignature($request, $config);

        $payload = $request->json()->all();
        $normalized = $this->normalizePayload($payload, Arr::get($config, 'mapping', []), Arr::get($config, 'defaults', []));

        $params = MarketinParams::current($request);

        if (! isset($normalized['affiliateId']) && $params->affiliateId()) {
            $normalized['affiliateId'] = $params->affiliateId();
        }

        if (! isset($normalized['campaignId']) && $params->campaignId()) {
            $normalized['campaignId'] = $params->campaignId();
        }

        if (! isset($normalized['productId']) && $params->productId()) {
            $normalized['productId'] = $params->productId();
        }

        ConversionDispatcher::queue($normalized, ['provider' => 'paystack', 'raw' => $payload]);

        return response()->json(['status' => 'accepted']);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function verifySignature(Request $request, array $config): void
    {
        $secret = Arr::get($config, 'webhook.secret');
        $header = Arr::get($config, 'webhook.signature_header', 'x-paystack-signature');

        if (! $secret) {
            return;
        }

        $signature = $request->headers->get($header);
        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        abort_unless(is_string($signature) && hash_equals($expected, $signature), 401, 'Invalid webhook signature.');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $payload, array $mapping, array $defaults = []): array
    {
        $normalized = [];

        foreach ($mapping as $attribute => $definition) {
            if (is_string($definition)) {
                $definition = ['path' => $definition];
            }

            $path = $definition['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $value = data_get($payload, $path);

            if ($value === null) {
                continue;
            }

            if (isset($definition['scale']) && is_numeric($value)) {
                $value = (float) $value * (float) $definition['scale'];
            }

            if (isset($definition['trim']) && is_string($value)) {
                $value = $definition['trim'] ? trim($value) : $value;
            }

            if (Str::endsWith($attribute, 'Id')) {
                $value = is_numeric($value) ? (int) $value : $value;
            }

            $normalized[$attribute] = $value;
        }

        foreach ($defaults as $key => $value) {
            $normalized[$key] = $normalized[$key] ?? $value;
        }

        return $normalized;
    }
}
