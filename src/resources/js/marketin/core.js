const MARKETIN_PARAM_KEY = 'marketinParams';

const isBrowser = typeof window !== 'undefined';

const SDK_RETRY_INTERVAL_MS = 250;
const SDK_TIMEOUT_NOTICE_MS = 5000;

let sdkRetryTimer = null;
let sdkWaitStartedAt = null;
let sdkMissingWarned = false;
let sdkTimeoutNotified = false;

const readStoredMarketInParams = () => {
	if (!isBrowser) {
		return {};
	}

	try {
		const raw = window.sessionStorage?.getItem(MARKETIN_PARAM_KEY);
		return raw ? JSON.parse(raw) : {};
	} catch (error) {
		console.warn('[MarketIn Demo] Unable to read stored params', error);
		return {};
	}
};

const writeStoredMarketInParams = (params) => {
	if (!isBrowser) {
		return;
	}

	try {
		window.sessionStorage?.setItem(MARKETIN_PARAM_KEY, JSON.stringify(params));
	} catch (error) {
		console.warn('[MarketIn Demo] Unable to persist params', error);
	}
};

const syncMarketInParamsFromUrl = () => {
	if (!isBrowser) {
		return {};
	}

	const current = readStoredMarketInParams();
	const searchParams = new URLSearchParams(window.location.search ?? '');
	const updates = {};

	if (searchParams.has('aid')) {
		updates.aid = searchParams.get('aid') ?? undefined;
	}

	if (searchParams.has('cid')) {
		updates.cid = searchParams.get('cid') ?? undefined;
	}

	if (searchParams.has('pid')) {
		updates.pid = searchParams.get('pid') ?? undefined;
	}

	if (Object.keys(updates).length > 0) {
		const merged = { ...current, ...updates };
		writeStoredMarketInParams(merged);
		return merged;
	}

	return current;
};

const parseNumericId = (value) => {
	if (value === undefined || value === null || value === '') {
		return undefined;
	}

	const numeric = Number(value);
	return Number.isFinite(numeric) ? numeric : undefined;
};

const readBridgeConfig = () => {
	if (!isBrowser) {
		return {};
	}

	return window.__marketInBridgeConfig ?? {};
};

const resolveEventName = (payload, fallback) => {
	const eventValue = typeof payload?.event === 'string' && payload.event.trim() !== ''
		? payload.event.trim()
		: undefined;

	if (eventValue) {
		return eventValue;
	}

	const eventTypeValue = typeof payload?.eventType === 'string' && payload.eventType.trim() !== ''
		? payload.eventType.trim()
		: undefined;

	return eventTypeValue || fallback;
};

const resolveBootstrapOptions = (overrides = {}) => {
	const globalConfig = readBridgeConfig();
	const combined = { ...globalConfig, ...overrides };

	const toNumberOrUndefined = (value) => {
		const numeric = parseNumericId(value);
		return numeric === undefined ? undefined : numeric;
	};

	return {
		brandId: toNumberOrUndefined(combined.brandId),
		campaignId: toNumberOrUndefined(combined.campaignId),
		affiliateId: toNumberOrUndefined(combined.affiliateId),
		defaultCampaignId: toNumberOrUndefined(combined.defaultCampaignId),
		defaultAffiliateId: toNumberOrUndefined(combined.defaultAffiliateId),
		apiEndpoint: combined.apiEndpoint || 'https://api.marketin.now/api/v1',
		debug: typeof combined.debug === 'boolean' ? combined.debug : Boolean(readBridgeConfig().debug ?? true),
	};
};

const bindMarketInEvents = () => {
	if (!isBrowser || window.__marketInEventsBound) {
		return;
	}

	const logEvent = (label, detail) => {
		if (!window.__marketInDebug) {
			return;
		}

		console.info(`[MarketIn Demo] ${label}`, detail);
	};

	const subscribeTo = (eventName, callback) => {
		window.addEventListener(eventName, (event) => {
			callback(event.detail ?? {});
		});
	};

	subscribeTo('marketin:affiliate-click', (detail) => {
		logEvent('Affiliate Click', detail);
		window.MarketIn?.trackAffiliateClick?.(detail);
	});

	subscribeTo('marketin:conversion', (detail) => {
		logEvent('Purchase Conversion (raw)', detail);

		const payload = Array.isArray(detail) ? detail[0] : detail;
		const storedParams = readStoredMarketInParams();

		const rawAmount = payload?.amount ?? payload?.value ?? payload?.price;
		let value = Number(rawAmount);
		if (typeof rawAmount === 'string') {
			const cleaned = rawAmount.replace(/,/g, '').trim();
			value = Number.parseFloat(cleaned);
		}

		const storedProductId = storedParams?.pid ?? storedParams?.productId;
		const productIdCandidate = storedProductId ?? payload?.productId ?? payload?.id;
		const productId = productIdCandidate !== undefined && productIdCandidate !== null ? String(productIdCandidate).trim() : '';

		const affiliateId = parseNumericId(payload?.affiliateId) ?? parseNumericId(storedParams?.aid);
		const campaignId = parseNumericId(payload?.campaignId) ?? parseNumericId(storedParams?.cid);

		if (!Number.isFinite(value) || !productId) {
			console.error('[MarketIn Demo] Invalid conversion payload. value/productId required.', {
				rawAmount,
				value,
				productId,
				payload,
				detail,
				storedParams,
			});
			return;
		}

		const eventName = resolveEventName(payload, 'purchase');

		const conversionData = {
			event: eventName,
			eventType: eventName,
			value,
			currency: payload?.currency || 'USD',
			conversionRef: payload?.orderId,
			productId,
			metadata: {
				productName: payload?.name,
				purchasedAt: payload?.purchasedAt,
			},
		};

		if (affiliateId !== undefined) {
			conversionData.affiliateId = affiliateId;
		}

		if (campaignId !== undefined) {
			conversionData.campaignId = campaignId;
		}

		logEvent('Purchase Conversion (mapped)', conversionData);
		window.MarketIn?.trackConversion?.(conversionData);
	});

	subscribeTo('marketin:subscription', (detail) => {
		logEvent('Subscription detail (raw)', detail);

		const payload = Array.isArray(detail) ? { ...(detail[0] ?? {}) } : { ...(detail ?? {}) };
		const storedParams = readStoredMarketInParams();

		const affiliateId = parseNumericId(payload?.affiliateId) ?? parseNumericId(storedParams?.aid);
		const campaignId = parseNumericId(payload?.campaignId) ?? parseNumericId(storedParams?.cid);
		const productId = payload?.productId ?? storedParams?.pid ?? storedParams?.productId;
		const eventName = resolveEventName(payload, 'subscription.created');

		if (affiliateId !== undefined) {
			payload.affiliateId = affiliateId;
		}

		if (campaignId !== undefined) {
			payload.campaignId = campaignId;
		}

		if (productId !== undefined && payload.productId === undefined) {
			payload.productId = productId;
		}

		payload.event = eventName;
		payload.eventType = eventName;

		logEvent('Subscription detail (mapped)', payload);
		window.MarketIn?.trackConversion?.(payload);
	});

	window.__marketInEventsBound = true;
};

const bindSubscriptionForm = (form) => {
	if (!form || form.dataset.marketinSubscriptionBound === 'true') {
		return;
	}

	form.dataset.marketinSubscriptionBound = 'true';
	form.addEventListener('submit', (event) => {
		window.MarketIn?.handleSubscriptionSubmit?.(event);
	});
};

const resolvePayloadFromElement = (element, markerAttribute) => {
	const payload = {};

	const raw = element.getAttribute(markerAttribute);
	if (raw && raw !== '') {
		try {
			const parsed = JSON.parse(raw);
			Object.assign(payload, parsed);
		} catch (error) {
			console.warn('[MarketIn Demo] Invalid JSON in attribute', markerAttribute, error);
		}
	}

	Object.entries(element.dataset).forEach(([key, value]) => {
		if (!key.toLowerCase().startsWith('marketin')) {
			return;
		}

		const normalized = key.replace(/^marketin/i, '');
		if (!normalized || normalized.toLowerCase() === 'click' || normalized.toLowerCase() === 'conversion') {
			return;
		}

		const formattedKey = normalized.charAt(0).toLowerCase() + normalized.slice(1);

		if (value === undefined || value === null || value === '') {
			return;
		}

		if (/id$/i.test(formattedKey) || /amount|value|price$/i.test(formattedKey)) {
			const maybeNumber = parseNumericId(value);
			payload[formattedKey] = maybeNumber ?? value;
			return;
		}

		payload[formattedKey] = value;
	});

	return payload;
};

const dispatchMarketInEvent = (eventName, payload) => {
	if (!isBrowser) {
		return;
	}

	window.dispatchEvent(new CustomEvent(eventName, { detail: payload }));
};

const bindDeclarativeTarget = (selector, markerAttribute, eventName, defaultListenerEvent = 'click') => {
	if (!isBrowser) {
		return;
	}

	document.querySelectorAll(selector).forEach((element) => {
		const boundKey = `${eventName.replace(/[^a-z0-9]/gi, '')}Bound`;
		if (element.dataset[boundKey] === 'true') {
			return;
		}

		const listener = () => {
			const payload = resolvePayloadFromElement(element, markerAttribute);
			if (eventName === 'marketin:affiliate-click' && !payload.clickedAt) {
				payload.clickedAt = new Date().toISOString();
			}

			if (eventName === 'marketin:conversion' && !payload.purchasedAt) {
				payload.purchasedAt = new Date().toISOString();
			}
			dispatchMarketInEvent(eventName, payload);
		};

		const listenEvent = element.dataset.marketinEvent ?? defaultListenerEvent;
		element.addEventListener(listenEvent, listener);
		element.dataset[boundKey] = 'true';
	});
};

const attachDeclarativeHooks = () => {
	if (!isBrowser) {
		return;
	}

	bindDeclarativeTarget('[data-marketin-click]', 'data-marketin-click', 'marketin:affiliate-click');
	bindDeclarativeTarget('[data-marketin-conversion]', 'data-marketin-conversion', 'marketin:conversion');
	document.querySelectorAll('form[data-marketin="subscription"]').forEach((form) => bindSubscriptionForm(form));

	const legacyForm = document.getElementById('subscription-test-form');
	if (legacyForm) {
		bindSubscriptionForm(legacyForm);
	}
};

const scheduleSdkRetry = () => {
	if (!isBrowser) {
		return;
	}

	if (sdkRetryTimer !== null) {
		return;
	}

	sdkRetryTimer = window.setTimeout(() => {
		sdkRetryTimer = null;
		registerMarketInBridge(latestOptions);
	}, SDK_RETRY_INTERVAL_MS);
};

const registerMarketInBridge = (options) => {
	if (!isBrowser) {
		return;
	}

	const resolved = resolveBootstrapOptions(options);
	window.__marketInBootstrapOptions = resolved;
	window.__marketInDebug = resolved.debug;

	const effectiveParams = syncMarketInParamsFromUrl();
	const affiliateId = parseNumericId(effectiveParams.aid) ?? resolved.affiliateId ?? resolved.defaultAffiliateId;
	const campaignId = parseNumericId(effectiveParams.cid) ?? resolved.campaignId ?? resolved.defaultCampaignId;

	if (resolved.brandId === undefined || resolved.brandId === null) {
		console.error('[MarketIn Demo] Missing brandId. Provide brandId via config, environment variables, or @marketinScripts override.');
		return;
	}

	bindMarketInEvents();
	attachDeclarativeHooks();

	if (typeof window.MarketIn === 'undefined') {
		if (!sdkMissingWarned) {
			console.warn('[MarketIn Demo] MarketIn SDK not detected yet. Waiting for the SDK to finish loading before continuing bootstrap.');
			sdkMissingWarned = true;
		}

		if (sdkWaitStartedAt === null) {
			sdkWaitStartedAt = Date.now();
		} else if (!sdkTimeoutNotified && Date.now() - sdkWaitStartedAt >= SDK_TIMEOUT_NOTICE_MS) {
			console.error('[MarketIn Demo] MarketIn SDK still missing after waiting 5000 ms. Confirm the @marketinScripts directive renders before </head> and that network requests to the SDK succeed.');
			sdkTimeoutNotified = true;
		}

		scheduleSdkRetry();
		return;
	}

	if (sdkRetryTimer !== null) {
		window.clearTimeout(sdkRetryTimer);
		sdkRetryTimer = null;
	}

	sdkWaitStartedAt = null;
	sdkMissingWarned = false;
	sdkTimeoutNotified = false;

	if (!window.__marketInInitialized) {
		window.MarketIn.init({
			brandId: resolved.brandId,
			campaignId,
			affiliateId,
			apiEndpoint: resolved.apiEndpoint,
			debug: resolved.debug,
		});

		if (resolved.debug) {
			console.log('✅ MarketIn SDK initialized', {
				brandId: resolved.brandId,
				campaignId,
				affiliateId,
				apiEndpoint: resolved.apiEndpoint,
			});
			console.log('📊 SDK Status:', window.MarketIn.getStatus?.());
			console.log('📌 MarketIn Params:', { affiliateId, campaignId, stored: effectiveParams });
		}

		window.__marketInInitialized = true;
	} else if (resolved.debug) {
		console.log('ℹ️ MarketIn SDK already initialised; refreshing bridge context.', {
			brandId: resolved.brandId,
			campaignId,
			affiliateId,
		});
	}

	if (window.MarketIn?.trackPageView) {
		window.MarketIn.trackPageView();
		if (resolved.debug) {
			console.log('🔄 Page view tracked');
		}
	}
};

let listenersBound = false;
let latestOptions = {};

const bootstrapMarketInBridge = (options = {}) => {
	latestOptions = { ...latestOptions, ...options };

	const execute = () => registerMarketInBridge(latestOptions);

	if (!listenersBound) {
		listenersBound = true;

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', execute);
		} else {
			execute();
		}

		document.addEventListener('livewire:load', execute);
		document.addEventListener('livewire:navigated', () => {
			execute();
		});
		window.addEventListener('marketin:sdk-loaded', execute);
	} else {
		execute();
	}
};

export {
	bootstrapMarketInBridge,
	resolveBootstrapOptions,
	registerMarketInBridge,
	parseNumericId,
	attachDeclarativeHooks,
};