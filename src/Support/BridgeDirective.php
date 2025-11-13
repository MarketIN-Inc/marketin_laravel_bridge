<?php

namespace Marketin\LaravelBridge\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class BridgeDirective
{
    /**
     * Render the Marketin SDK + bridge script tags.
     */
    public static function scripts(array $overrides = []): HtmlString
    {
        $config = config('marketin');
        $resolved = self::resolveOptions($config, $overrides);

        $sdkUrl = $resolved['sdkUrl'];
        $bridgeSrc = $resolved['bridgeSrc'];
        $dataset = $resolved['dataset'];
        $datasetString = self::attributeString($dataset);
        $sdkAttrString = self::attributeString($resolved['sdkAttributes'], true);
        $bridgeConfig = json_encode($resolved['bridgeConfig'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $snippet = <<<HTML
    <script src="{$sdkUrl}"{$sdkAttrString}></script>
<script>
window.__marketInBridgeConfig = {$bridgeConfig};
</script>
<script src="{$bridgeSrc}"{$datasetString} defer></script>
HTML;

        return new HtmlString($snippet);
    }

    /**
     * Render the Marketin tracking data-layer snippet.
     */
    public static function tracking(array $payload = []): HtmlString
    {
        $config = config('marketin');

        if (! Arr::get($config, 'tracking.enabled', true)) {
            return new HtmlString('');
        }

        $layer = Arr::get($payload, 'layer', Arr::get($config, 'tracking.layer_variable', 'dataLayer'));
        $bridgeConfig = self::resolveTrackingConfig($config, $payload);
        $json = json_encode($bridgeConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $snippet = <<<HTML
<script>
window.{$layer} = window.{$layer} || [];
window.{$layer}.push({$json});
</script>
HTML;

        return new HtmlString($snippet);
    }

    /**
     * Resolve effective options for the bridge dataset and config blob.
     */
    protected static function resolveOptions(array $config, array $overrides): array
    {
        $defaults = [
            'brand_id' => Arr::get($config, 'brand_id'),
            'campaign_id' => Arr::get($config, 'campaign_id'),
            'affiliate_id' => Arr::get($config, 'affiliate_id'),
            'default_campaign_id' => Arr::get($config, 'default_campaign_id'),
            'default_affiliate_id' => Arr::get($config, 'default_affiliate_id'),
            'api_endpoint' => Arr::get($config, 'api_endpoint'),
            'debug' => Arr::get($config, 'debug', false),
            'sdk_url' => Arr::get($config, 'sdk_url'),
            'bridge_filename' => Arr::get($config, 'assets.bridge_filename', 'marketin-bridge.js'),
            'publish_path' => Arr::get($config, 'assets.publish_path', 'vendor/marketin'),
            'bridge_url' => Arr::get($config, 'assets.bridge_url'),
            'sdk_attributes' => Arr::get($config, 'sdk_attributes', []),
        ];

        $merged = array_merge($defaults, $overrides);

        $bridgeUrl = $merged['bridge_url'] ?? null;
        if (is_string($bridgeUrl) && trim($bridgeUrl) === '') {
            $bridgeUrl = null;
        }

        $bridgeSrc = $bridgeUrl ?? asset(trim((string) $merged['publish_path'], '/').'/'.$merged['bridge_filename']);

        $rawSdkAttributes = $merged['sdkAttributes'] ?? $merged['sdk_attributes'] ?? [];
        $sdkAttributes = is_array($rawSdkAttributes)
            ? $rawSdkAttributes
            : Arr::wrap($rawSdkAttributes);

        $normalizedSdkAttributes = [];
        $hasDeferAttribute = false;

        foreach ($sdkAttributes as $attribute => $value) {
            if (is_int($attribute)) {
                $attribute = $value;
                $value = true;
            }

            if (! is_string($attribute)) {
                continue;
            }

            $normalized = strtolower($attribute);

            if ($normalized === 'async') {
                continue;
            }

            if ($normalized === 'defer') {
                $hasDeferAttribute = true;
                $normalizedSdkAttributes['defer'] = $value;

                continue;
            }

            $normalizedSdkAttributes[$attribute] = $value;
        }

        if (! $hasDeferAttribute) {
            $normalizedSdkAttributes['defer'] = true;
        }

        $sdkAttributes = $normalizedSdkAttributes;

        $bridgeConfig = array_filter([
            'brandId' => self::numericOrValue($merged['brandId'] ?? $merged['brand_id'] ?? null),
            'campaignId' => self::numericOrValue($merged['campaignId'] ?? $merged['campaign_id'] ?? null),
            'affiliateId' => self::numericOrValue($merged['affiliateId'] ?? $merged['affiliate_id'] ?? null),
            'defaultCampaignId' => self::numericOrValue($merged['defaultCampaignId'] ?? $merged['default_campaign_id'] ?? null),
            'defaultAffiliateId' => self::numericOrValue($merged['defaultAffiliateId'] ?? $merged['default_affiliate_id'] ?? null),
            'apiEndpoint' => $merged['apiEndpoint'] ?? $merged['api_endpoint'] ?? null,
            'debug' => filter_var($merged['debug'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        ], fn ($value) => $value !== null && $value !== '');

        $dataset = [
            'data-marketin-brand' => $bridgeConfig['brandId'] ?? null,
            'data-marketin-campaign' => $bridgeConfig['campaignId'] ?? null,
            'data-marketin-affiliate' => $bridgeConfig['affiliateId'] ?? null,
            'data-marketin-api' => $bridgeConfig['apiEndpoint'] ?? null,
            'data-marketin-debug' => $bridgeConfig['debug'] ?? null,
        ];

        return [
            'sdkUrl' => $merged['sdk_url'] ?? '#',
            'bridgeSrc' => $bridgeSrc,
            'dataset' => $dataset,
            'bridgeConfig' => $bridgeConfig,
            'sdkAttributes' => $sdkAttributes,
        ];
    }

    /**
     * Resolve the payload to push onto the tracking layer.
     */
    protected static function resolveTrackingConfig(array $config, array $payload): array
    {
        $baseline = array_filter([
            'brandId' => Arr::get($payload, 'brandId', Arr::get($payload, 'brand_id', Arr::get($config, 'brand_id'))),
            'campaignId' => Arr::get($payload, 'campaignId', Arr::get($payload, 'campaign_id', Arr::get($config, 'campaign_id'))),
            'affiliateId' => Arr::get($payload, 'affiliateId', Arr::get($payload, 'affiliate_id', Arr::get($config, 'affiliate_id'))),
            'event' => Arr::get($payload, 'event', 'marketin.page_view'),
        ], fn ($value) => $value !== null && $value !== '');

        $rest = Arr::except($payload, ['brandId', 'brand_id', 'campaignId', 'campaign_id', 'affiliateId', 'affiliate_id', 'event', 'layer']);

        return array_merge($baseline, $rest);
    }

    /**
     * Convert dataset key/value pairs into HTML attributes.
     */
    protected static function stringifyAttributes(array $attributes, bool $booleanAsPresence = false): string
    {
        return collect($attributes)
            ->filter(function ($value) use ($booleanAsPresence) {
                if ($booleanAsPresence && $value === false) {
                    return false;
                }

                return $value !== null && $value !== '';
            })
            ->map(function ($value, $attribute) use ($booleanAsPresence) {
                if ($booleanAsPresence && $value === true) {
                    return $attribute;
                }

                $escaped = e(self::stringifyValue($value));

                return sprintf('%s="%s"', $attribute, $escaped);
            })
            ->implode(' ');
    }

    /**
     * Provide a leading-space-prefixed attribute string when attributes exist.
     */
    protected static function attributeString(array $attributes, bool $booleanAsPresence = false): string
    {
        $string = self::stringifyAttributes($attributes, $booleanAsPresence);

        return $string === '' ? '' : ' '.$string;
    }

    /**
     * Cast numeric-like values to integers when appropriate.
     */
    protected static function numericOrValue(mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (is_numeric($value) && ! Str::contains((string) $value, ['e', 'E'])) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Normalize attribute values to strings.
     */
    protected static function stringifyValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
