import { test } from 'node:test';
import assert from 'node:assert/strict';

const createTestEnvironment = () => {
	const conversions = [];
	const listenerMap = new Map();

	class SimpleCustomEvent {
		constructor(type, options = {}) {
			this.type = type;
			this.detail = options.detail;
		}
	}

	const sessionStorage = {
		_data: new Map(),
		getItem(key) {
			return this._data.has(key) ? this._data.get(key) : null;
		},
		setItem(key, value) {
			this._data.set(key, String(value));
		},
		removeItem(key) {
			this._data.delete(key);
		},
		clear() {
			this._data.clear();
		},
	};

	const documentMock = {
		readyState: 'complete',
		addEventListener() {},
		querySelectorAll() {
			return [];
		},
		getElementById() {
			return null;
		},
	};

	const windowMock = {
		_listeners: listenerMap,
		sessionStorage,
		location: { search: '' },
		setTimeout: globalThis.setTimeout.bind(globalThis),
		clearTimeout: globalThis.clearTimeout.bind(globalThis),
		document: documentMock,
		console,
		__marketInBridgeConfig: { debug: false },
		CustomEvent: SimpleCustomEvent,
		MarketIn: {
			init() {},
			trackConversion(payload) {
				conversions.push(payload);
			},
			trackPageView() {},
			getStatus() {
				return { initialized: true };
			},
		},
		addEventListener(type, callback) {
			if (!this._listeners.has(type)) {
				this._listeners.set(type, new Set());
			}

			this._listeners.get(type).add(callback);
		},
		removeEventListener(type, callback) {
			this._listeners.get(type)?.delete(callback);
		},
		dispatchEvent(event) {
			const listeners = this._listeners.get(event.type);
			if (!listeners) {
				return true;
			}

			for (const listener of listeners) {
				listener.call(this, event);
			}

			return true;
		},
	};

	return { windowMock, documentMock, conversions };
};

const withBridgeModule = async (testCallback) => {
	const { windowMock, documentMock, conversions } = createTestEnvironment();

	globalThis.window = windowMock;
	globalThis.document = documentMock;
	globalThis.CustomEvent = windowMock.CustomEvent;

	const module = await import('../../src/resources/js/marketin/core.js');

	try {
		await testCallback({ module, window: windowMock, conversions });
	} finally {
		delete globalThis.window;
		delete globalThis.document;
		delete globalThis.CustomEvent;
	}
};

test('marketin:conversion defaults event to purchase', async () => {
	await withBridgeModule(async ({ module, window, conversions }) => {
		const { registerMarketInBridge } = module;

		registerMarketInBridge({ brandId: 101 });

		window.dispatchEvent(
			new window.CustomEvent('marketin:conversion', {
				detail: { value: '125.50', productId: 'SKU-42' },
			}),
		);

		assert.equal(conversions.length, 1);
		assert.equal(conversions[0].event, 'purchase');
		assert.equal(conversions[0].eventType, 'purchase');
	});
});

test('marketin:subscription defaults event to subscription.created', async () => {
	await withBridgeModule(async ({ module, window, conversions }) => {
		const { registerMarketInBridge } = module;

		registerMarketInBridge({ brandId: 202 });

		window.dispatchEvent(
			new window.CustomEvent('marketin:subscription', {
				detail: { productId: 'SUB-1' },
			}),
		);

		assert.equal(conversions.length, 1);
		assert.equal(conversions[0].event, 'subscription.created');
		assert.equal(conversions[0].eventType, 'subscription.created');
	});
});
