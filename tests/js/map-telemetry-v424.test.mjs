import test from 'node:test';
import assert from 'node:assert/strict';

import {
    advanceMapTelemetryFlow,
    attachMapTelemetryToWorkStartForm,
    ensureMapTelemetryFlow,
    mapTelemetryMetadata,
    readMapTelemetryFlow,
} from '../../resources/js/map-telemetry.mjs';

function fakeWindow() {
    const data = new Map();
    return {
        location: { pathname: '/map', origin: 'https://example.test', href: 'https://example.test/map' },
        navigator: { userAgent: 'desktop-test' },
        matchMedia: () => ({ matches: false }),
        crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
        sessionStorage: {
            getItem: (key) => data.has(key) ? data.get(key) : null,
            setItem: (key, value) => data.set(key, value),
            removeItem: (key) => data.delete(key),
        },
    };
}

test('map telemetry flow is stable until it expires', () => {
    const windowRef = fakeWindow();
    const first = ensureMapTelemetryFlow(windowRef, 1000);
    const second = ensureMapTelemetryFlow(windowRef, 2000);
    assert.equal(first.isNew, true);
    assert.equal(second.isNew, false);
    assert.equal(first.flow.id, second.flow.id);
    const advanced = advanceMapTelemetryFlow(windowRef, 2500, 2);
    assert.equal(advanced.stepCount, 2);
    assert.equal(readMapTelemetryFlow(windowRef, 2500).stepCount, 2);
    const metadata = mapTelemetryMetadata(advanced, { node_type: 'task' }, 3000);
    assert.equal(metadata.elapsed_ms, 2000);
    assert.equal(metadata.step_count, 2);
    assert.equal(metadata.node_type, 'task');
});

test('work start form receives and consumes one pending Map flow', () => {
    const windowRef = fakeWindow();
    ensureMapTelemetryFlow(windowRef, 1000);
    const inputs = new Map();
    const form = {
        dataset: {},
        matches: (selector) => selector === '[data-work-start-form]',
        ownerDocument: { createElement: () => ({ type: '', name: '', value: '' }) },
        querySelector: (selector) => {
            const match = selector.match(/input\[name="([^"]+)"\]/);
            return match ? inputs.get(match[1]) || null : null;
        },
        appendChild: (input) => { inputs.set(input.name, input); },
    };
    const result = attachMapTelemetryToWorkStartForm(form, windowRef, 4000);
    assert.equal(result.flowId, '11111111-1111-4111-8111-111111111111');
    assert.equal(result.elapsedMs, 3000);
    assert.equal(result.stepCount, 1);
    assert.equal(inputs.get('map_flow_id').value, result.flowId);
    assert.equal(inputs.get('map_flow_elapsed_ms').value, '3000');
    assert.equal(inputs.get('map_flow_step_count').value, '1');
    assert.equal(readMapTelemetryFlow(windowRef, 4000), null);
});