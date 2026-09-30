import test from 'node:test';
import assert from 'node:assert/strict';

import {
    MAP_PERSONALIZATION_SCHEMA_VERSION,
    MAP_PERSONALIZATION_STORAGE_KEY,
    hiddenMapPersonalizationState,
    normalizeMapPersonalizationState,
    parseMapPersonalizationState,
    persistMapPersonalizationState,
    resetMapPersonalizationState,
} from '../../resources/js/map-personalization.mjs';

test('personalization state is versioned, bounded and ignores malformed node ids', () => {
    const state = normalizeMapPersonalizationState({
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [
            'satellite:plan:1',
            'satellite:plan:1',
            'bad id with spaces',
            '',
            'satellite:plan:2',
        ],
    });

    assert.deepEqual(state, {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [
            'satellite:plan:1',
            'satellite:plan:2',
        ],
    });
});

test('broken or future personalization payload safely falls back to clean defaults', () => {
    assert.deepEqual(parseMapPersonalizationState('{broken'), {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [],
    });

    assert.deepEqual(parseMapPersonalizationState(JSON.stringify({
        version: 999,
        hidden_node_ids: ['satellite:plan:1'],
    })), {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [],
    });
});

test('hiding a personalized shortcut changes only device-local presentation state', () => {
    const initial = {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [],
    };
    const next = hiddenMapPersonalizationState(initial, 'satellite:plan:42');

    assert.deepEqual(initial, {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [],
    });
    assert.deepEqual(next, {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: ['satellite:plan:42'],
    });

    const writes = new Map();
    const storage = {
        setItem(key, value) {
            writes.set(key, value);
        },
    };

    const persisted = persistMapPersonalizationState(storage, next);

    assert.deepEqual(persisted, next);
    assert.equal(
        writes.get(MAP_PERSONALIZATION_STORAGE_KEY),
        JSON.stringify(next),
    );
});

test('reset clears only personalization presentation preferences', () => {
    assert.deepEqual(resetMapPersonalizationState(), {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [],
    });
});
