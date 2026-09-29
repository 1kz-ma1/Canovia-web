import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAP_DATA_LAYER_KEYS,
    MAP_DATA_LAYER_SCHEMA_VERSION,
    normalizeMapDataLayerState,
    parseMapDataLayerState,
    toggleMapDataLayer,
} from '../../resources/js/map-data-layers.mjs';

test('invalid stored data falls back safely', () => {
    assert.deepEqual(
        parseMapDataLayerState('{broken', ['progress', 'deadline'], ['deadline']),
        { version: MAP_DATA_LAYER_SCHEMA_VERSION, enabled: ['deadline'] },
    );

    assert.deepEqual(
        normalizeMapDataLayerState({ version: 999, enabled: ['progress'] }, ['progress'], []),
        { version: MAP_DATA_LAYER_SCHEMA_VERSION, enabled: [] },
    );
});

test('unknown and unavailable layers are discarded', () => {
    assert.deepEqual(
        normalizeMapDataLayerState(
            { version: MAP_DATA_LAYER_SCHEMA_VERSION, enabled: ['progress', 'unknown', 'deadline'] },
            ['progress'],
            [],
        ),
        { version: MAP_DATA_LAYER_SCHEMA_VERSION, enabled: ['progress'] },
    );
});

test('global preference state can retain layers that are unavailable on the current depth', () => {
    const state = {
        version: MAP_DATA_LAYER_SCHEMA_VERSION,
        enabled: ['progress'],
    };

    const next = toggleMapDataLayer(
        state,
        'deadline',
        true,
        MAP_DATA_LAYER_KEYS,
        [],
    );

    assert.deepEqual(next.enabled, ['progress', 'deadline']);

    const later = toggleMapDataLayer(
        next,
        'priority',
        true,
        MAP_DATA_LAYER_KEYS,
        [],
    );

    assert.deepEqual(later.enabled, ['progress', 'deadline', 'priority']);
});

test('unknown layer toggle is ignored', () => {
    const state = {
        version: MAP_DATA_LAYER_SCHEMA_VERSION,
        enabled: ['progress'],
    };

    assert.deepEqual(
        toggleMapDataLayer(state, 'not-a-layer', true, MAP_DATA_LAYER_KEYS, []),
        state,
    );
});
